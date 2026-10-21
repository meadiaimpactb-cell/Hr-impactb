<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbsenceAlert;
use App\Models\Employee;
use App\Models\Lookup;
use App\Models\User;
use App\Models\Violation;
use App\Services\Access;
use App\Services\AttendanceService;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Violations. Types (with escalation ladder / lookback / deduction days) and penalty degrees are lookups.
 * Flow: record (discovery ≤ 30 days, §97) → employee defence (≥ 2 working days, §95) → HR investigation & recommendation
 * (investigator ≠ recorder, minutes, optional precautionary removal approved by the company CEO, §104) → company CEO signs
 * (≤ 30 days after the investigation, §97) → HR executes & notifies → active. Degree 4 = fine on the basic wage (§98),
 * 5 = deprivation of raise/promotion ≤ 12 months, 6 = suspension ≤ 5 days a month, 7 = termination (group CEO).
 * Grievance (§105): 30 days from notification, independent committee (no investigator/recommender/signer), decision within
 * 15 days, the group CEO approves the committee's decision.
 */
class ViolationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('VIO', 'view'), 403);
        $ids = Access::for()->employees('VIO')->pluck('id');

        return Violation::with('employee:id,name,emp_no', 'company:id,name')->whereIn('employee_id', $ids)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->orderByDesc('id')->limit(300)->get()->map(fn ($v) => $this->row($v));
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return Violation::where('employee_id', $e->id)->where('status', '!=', 'cancelled')->orderByDesc('id')->get()->map(fn ($v) => $this->row($v));
    }

    /** Escalation rules (from lookups) for the UI. */
    public function rules()
    {
        return ['types' => Lookup::grouped()['violation_types'] ?? [], 'degrees' => Lookup::grouped()['penalty_degrees'] ?? [], 'objection_days' => (int) Settings::get('violationObjectionDays', null, 30),
            'statement_days' => max(2, (int) Settings::get('violationStatementDays', null, 3)), 'statement_unit' => 'working', 'charge_days' => (int) Settings::get('violationChargeDays', null, 30),
            'max_fine_days' => 5, 'max_month_days' => 5, 'max_suspension_month' => 5, 'max_deprivation_months' => 12, 'decision_days' => 15, 'repeat_days' => 180,
            'deg_deprivation' => Violation::DEG_DEPRIVATION, 'deg_suspension' => Violation::DEG_SUSPENSION];
    }

    /**
     * Auto-suggestions: last month's attendance (late ≥ 3 or absence ≥ 1, not yet recorded) plus the §100–101 absence
     * watch (10 consecutive days → written warning before dismissal; > 15 consecutive or > 30 scattered in the contract
     * year → consider dismissal under art. 80; 20 scattered → warning). Nothing is imposed automatically (§95).
     */
    public function suggestions(Request $request)
    {
        abort_unless(Access::for()->canAny('VIO', 'view'), 403);
        $period = $request->input('period', now()->subMonthNoOverflow()->format('Y-m'));
        $out = [];
        $emps = Access::for()->employees('VIO')->where('status', '!=', 'terminated')->get();
        $last = now()->parse($period.'-01')->endOfMonth()->toDateString();
        foreach ($emps->where('punch_required', true) as $e) {
            $s = AttendanceService::summary($e, $period);
            $absent = (int) floor($s['absent']);
            $absType = $absent >= 7 ? 'absence7' : ($absent >= 4 ? 'absence4' : 'absence1');
            foreach ([['late15', $s['late'] >= 3, "تأخر {$s['late']} مرات في $period"], [$absType, $absent >= 1, "غياب $absent يوم في $period"]] as [$type, $hit, $desc]) {
                if ($hit && ! Violation::where('employee_id', $e->id)->where('description', 'like', "%$period%")->where('status', '!=', 'cancelled')->where('type', $type)->exists()) {
                    $out[] = ['kind' => 'attendance', 'employee' => $e->only('id', 'name', 'emp_no'), 'type' => $type, 'type_name' => Lookup::name('violation_types', $type), 'description' => $desc, 'occurred_on' => $last];
                }
            }
        }
        $alerts = AbsenceAlert::with('employee:id,name,emp_no')->whereIn('employee_id', $emps->pluck('id'))->where('status', 'open')->orderByDesc('id')->get();
        foreach ($alerts as $a) {
            $out[] = ['kind' => 'absence_watch', 'alert_id' => $a->id, 'alert_kind' => $a->kind, 'alert_label' => AbsenceAlert::KINDS[$a->kind] ?? $a->kind, 'severity' => in_array($a->kind, ['consecutive15', 'scattered30'], true) ? 'bad' : 'warn',
                'employee' => $a->employee?->only('id', 'name', 'emp_no'), 'type' => $a->suggestedType(), 'type_name' => Lookup::name('violation_types', $a->suggestedType()), 'degree' => 2,
                'description' => $a->describe(), 'occurred_on' => $a->to->toDateString()];
        }

        return $out;
    }

    /** HR dismisses an absence-watch alert (absence excused after verification). */
    public function dismissAlert(Request $request, AbsenceAlert $alert)
    {
        abort_unless($request->user()->isHr() && Access::for()->can('VIO', 'approve', ['employee' => $alert->employee]), 403);
        $d = $request->validate(['note' => 'required|string|max:500']);
        $alert->update(['status' => 'dismissed']);
        Audit::log('absence_alerts', $alert->id, 'dismiss', null, null, $d['note']);

        return ['ok' => true];
    }

    public function show(Request $request, Violation $violation)
    {
        $u = $request->user();
        $acc = Access::for();
        abort_unless($violation->employee_id === $u->employee_id || $acc->canSeeEmployee($violation->employee), 403);
        $objDays = (int) Settings::get('violationObjectionDays', $violation->company_id, 30);
        $deadline = $violation->statementDeadline();
        $deadlinePassed = $deadline->isPast();
        $isCeo = $u->hasRole('super') || $acc->isCeoOf($violation->company_id);
        $isHr = $u->isHr() && $acc->can('VIO', 'approve', ['employee' => $violation->employee]);
        $prior = $this->priorCount($violation->employee, $violation->type, $violation->occurred_on->toDateString(), $violation->id);
        $steps = $this->steps($violation->type);
        $notified = $violation->notifiedDate();
        $grievanceDeadline = $notified ? self::grievanceEnd($violation->employee, $notified, $objDays) : null;
        $members = (array) ($violation->committee ?? []);
        $excluded = $this->committeeExcluded($violation);
        $open = ! in_array($violation->status, ['active', 'cancelled'], true);
        $signDeadline = $violation->investigation_done_on ? $violation->investigation_done_on->copy()->addDays((int) Settings::get('violationChargeDays', $violation->company_id, 30)) : null;

        return $this->row($violation->load('employee:id,name,emp_no', 'company:id,name', 'recorder:id,name', 'investigator:id,name')) + [
            'prior' => $prior, 'ladder' => array_map(fn ($s) => Violation::stepLabel($s), $steps), 'step_index' => min($prior, max(0, count($steps) - 1)),
            'deadline' => $deadline->toDateString(), 'deadline_rule' => 'يوما عمل على الأقل من التبليغ (§95)',
            'sign_deadline' => $signDeadline?->toDateString(),
            'objection_deadline' => $violation->status === 'active' ? $grievanceDeadline?->toDateString() : null, 'objection_rule' => '30 يومًا من تاريخ التبليغ (تُستثنى العطل الرسمية متى سُجّلت)',
            'decision_deadline' => $violation->objection_at ? $violation->objection_at->copy()->addDays(15)->toDateString() : null,
            'committee_members' => $members ? User::whereIn('id', $members)->get(['id', 'name'])->map->only('id', 'name')->values() : [],
            'committee_excluded' => $excluded,
            'can_state' => $violation->employee_id === $u->employee_id && $violation->status === 'awaiting_statement',
            'can_investigate' => $isHr && $open,
            'can_recommend' => $isHr && ($violation->status === 'awaiting_recommendation' || ($violation->status === 'awaiting_statement' && $deadlinePassed)),
            'can_sign' => $isCeo && $violation->status === 'awaiting_approval' && $violation->employee_id !== $u->employee_id,
            'can_approve' => $isHr && $violation->status === 'awaiting_approval' && $violation->signed_degree !== null,
            'can_object' => $violation->employee_id === $u->employee_id && $violation->status === 'active' && $violation->objection === 'none' && $grievanceDeadline && $grievanceDeadline->isFuture(),
            'can_form_committee' => $isHr && $violation->objection === 'open' && ! $members,
            'can_propose' => $violation->objection === 'open' && in_array($u->id, $members, true),
            'can_finalize' => $u->hasRole('super') && $violation->objection === 'proposed' && ! in_array($u->id, $excluded, true),
            'can_decide_objection' => false,
            'can_propose_removal' => $isHr && $open && in_array($violation->removal_status, ['none', 'rejected', 'ended'], true),
            'can_approve_removal' => $isCeo && $violation->removal_status === 'proposed' && $violation->employee_id !== $u->employee_id,
            'can_end_removal' => ($isHr || $isCeo) && $violation->removal_status === 'approved',
            'can_cancel' => ($isHr || $isCeo) && ! in_array($violation->status, ['cancelled'], true),
        ];
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $types = Lookup::grouped()['violation_types'] ?? [];
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'type' => ['required', Rule::in(array_column($types, 'code'))],
            'occurred_on' => 'required|date|before_or_equal:today', 'discovered_on' => 'nullable|date|after_or_equal:occurred_on|before_or_equal:today',
            'description' => 'required|string|max:2000', 'evidence' => 'nullable|string|max:300', 'operational_impact' => 'boolean', 'alert_id' => 'nullable|integer']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(Access::for()->isManagerOf($e) || Access::for()->can('VIO', 'add', ['employee' => $e]), 403);
        abort_if($e->id === $u->employee_id, 422, 'لا يمكن تسجيل مخالفة على نفسك');
        $d['discovered_on'] ??= $d['occurred_on'];
        // §97: no charge for a violation discovered more than 30 days ago (counted from discovery, not occurrence)
        $charge = (int) Settings::get('violationChargeDays', $e->company_id, 30);
        abort_if(Carbon::parse($d['discovered_on'])->addDays($charge)->endOfDay()->isPast(), 422, "لا يجوز اتهام الموظف بمخالفة مضى على كشفها أكثر من $charge يومًا (§97)");
        $alertId = $d['alert_id'] ?? null;
        unset($d['alert_id']);
        $steps = $this->steps($d['type']);
        $prior = $this->priorCount($e, $d['type'], $d['occurred_on']);
        $step = $steps[min($prior, max(0, count($steps) - 1))] ?? 'warn';
        $proposed = Violation::stepDegree($step);
        if ($alertId && ($alert = AbsenceAlert::where('employee_id', $e->id)->find($alertId))) {
            $proposed = 2; // §101: the statutory written warning before dismissal
        }
        $v = Violation::create($d + ['company_id' => $e->company_id, 'proposed_degree' => $proposed, 'step' => $step, 'status' => 'awaiting_statement', 'recorded_by' => $u->id]);
        if (isset($alert)) {
            $alert->update(['status' => 'recorded', 'violation_id' => $v->id]);
        }
        $deadline = $v->statementDeadline();
        Audit::log('violations', $v->id, 'create', null, $v->only('type', 'occurred_on', 'discovered_on', 'proposed_degree'));
        Audit::notify(User::where('employee_id', $e->id)->value('id'), 'مخالفة مسجَّلة بحقك — مطلوب إفادتك حتى '.$deadline->format('d/m/Y'), Lookup::name('violation_types', $v->type).' · '.$v->occurred_on->format('d/m/Y').' · مهلة الدفاع يوما عمل على الأقل (§95)', 'bad', ['screen' => 'violations']);
        Audit::notify(Access::hrUsersFor($e->company_id, 'VIO'), 'مخالفة جديدة', $e->name.' — '.Lookup::name('violation_types', $v->type), 'info', ['screen' => 'violations']);

        return response()->json($this->row($v->load('employee:id,name,emp_no')), 201);
    }

    public function statement(Request $request, Violation $violation)
    {
        abort_unless($violation->employee_id === $request->user()->employee_id && $violation->status === 'awaiting_statement', 403);
        $d = $request->validate(['statement' => 'required|string|max:3000']);
        $violation->update(['statement' => $d['statement'], 'statement_at' => now(), 'status' => 'awaiting_recommendation']);
        Audit::notify(Access::hrUsersFor($violation->company_id, 'VIO'), 'وصلت إفادة الموظف — بانتظار توصية الموارد البشرية', $violation->employee->name, 'approval', ['screen' => 'violations']);

        return $this->row($violation);
    }

    /** §104: HR records the investigator (independent of whoever recorded the violation) and the minutes. */
    public function investigation(Request $request, Violation $violation)
    {
        $u = $request->user();
        abort_unless($u->isHr() && Access::for()->can('VIO', 'approve', ['employee' => $violation->employee]) && ! in_array($violation->status, ['active', 'cancelled'], true), 403);
        $d = $request->validate(['investigator_id' => 'required|exists:users,id', 'minutes' => 'nullable|string|max:8000']);
        abort_if((int) $d['investigator_id'] === (int) $violation->recorded_by, 422, 'لا يكون المحقق من سجّل المخالفة (§104) — عيّن محققًا مستقلًا');
        abort_if(User::whereKey($d['investigator_id'])->value('employee_id') === $violation->employee_id, 422, 'لا يحقق الموظف في مخالفته');
        $violation->update(['investigator_id' => (int) $d['investigator_id'], 'minutes' => $d['minutes'] ?? $violation->minutes]);
        Audit::log('violations', $violation->id, 'investigation', null, ['investigator' => $violation->investigator_id]);
        if ((int) $d['investigator_id'] !== $u->id) {
            Audit::notify((int) $d['investigator_id'], 'عُيّنت محققًا في مخالفة', $violation->employee->name.' — '.Lookup::name('violation_types', $violation->type), 'approval', ['screen' => 'violations']);
        }

        return $this->row($violation->fresh());
    }

    /** §104: precautionary removal from a task/authority during the investigation — HR prepares, company CEO approves, pay continues. */
    public function removal(Request $request, Violation $violation)
    {
        $u = $request->user();
        $acc = Access::for();
        $d = $request->validate(['action' => 'required|in:propose,approve,reject,end', 'note' => 'required|string|max:1000']);
        $isHr = $u->isHr() && $acc->can('VIO', 'approve', ['employee' => $violation->employee]);
        $isCeo = $u->hasRole('super') || $acc->isCeoOf($violation->company_id);
        $ok = match ($d['action']) {
            'propose' => $isHr && in_array($violation->removal_status, ['none', 'rejected', 'ended'], true) && ! in_array($violation->status, ['active', 'cancelled'], true),
            'approve', 'reject' => $isCeo && $violation->removal_status === 'proposed' && $violation->employee_id !== $u->employee_id,
            'end' => ($isHr || $isCeo) && $violation->removal_status === 'approved',
        };
        abort_unless($ok, 403, 'الإبعاد الاحترازي تُعدّه الموارد البشرية ويعتمده الرئيس التنفيذي للشركة (§104)');
        $status = ['propose' => 'proposed', 'approve' => 'approved', 'reject' => 'rejected', 'end' => 'ended'][$d['action']];
        $upd = ['removal_status' => $status, 'removal_note' => trim(($violation->removal_note ? $violation->removal_note."\n" : '').now()->format('d/m/Y').' — '.$d['note'])];
        if ($d['action'] === 'propose') {
            $upd['removal_by'] = $u->id;
        }
        if ($d['action'] === 'approve') {
            $upd += ['removal_approved_by' => $u->id, 'removal_at' => now()];
        }
        $violation->update($upd);
        Audit::log('violations', $violation->id, 'removal_'.$d['action'], null, null, $d['note']);
        if ($d['action'] === 'propose') {
            $ceo = $violation->company?->ceo_employee_id ? User::where('employee_id', $violation->company->ceo_employee_id)->value('id') : null;
            Audit::notify($ceo ?: Access::usersWithRole('super'), 'إبعاد احترازي بانتظار اعتمادك', $violation->employee->name.' — '.$d['note'], 'approval', ['screen' => 'violations']);
        } elseif ($d['action'] === 'approve') {
            Audit::notify(User::where('employee_id', $violation->employee_id)->value('id'), 'إبعاد احترازي مؤقت أثناء التحقيق', $d['note'].' — يستمر صرف أجرك (§104)، ولا يُعد ذلك جزاءً', 'warn', ['screen' => 'violations']);
        }

        return $this->row($violation->fresh());
    }

    /** §96 step 2: HR investigates the statement and recommends the step/degree to the company CEO. */
    public function recommend(Request $request, Violation $violation)
    {
        $u = $request->user();
        $ok = $u->isHr() && Access::for()->can('VIO', 'approve', ['employee' => $violation->employee]) && ($violation->status === 'awaiting_recommendation' || ($violation->status === 'awaiting_statement' && $violation->statementDeadline()->isPast()));
        abort_unless($ok, 403, 'التوصية للموارد البشرية بعد الإفادة أو انقضاء مهلتها (يوما عمل على الأقل — §95)');
        $degrees = Lookup::grouped()['penalty_degrees'] ?? [];
        $d = $request->validate(['recommended_degree' => ['required', Rule::in(array_column($degrees, 'code'))], 'step' => 'nullable|string|max:20', 'note' => 'required|string|max:1000', 'minutes' => 'nullable|string|max:8000']);
        // §104: the investigator is HR or an independent investigator, never the person who recorded the violation
        $investigator = $violation->investigator_id ?: $u->id;
        abort_if($investigator === (int) $violation->recorded_by, 422, 'من سجّل المخالفة لا يحقق فيها — عيّن محققًا مستقلًا أولًا (§104)');
        $violation->update(['recommended_degree' => (int) $d['recommended_degree'], 'recommended_by' => $u->id, 'step' => $d['step'] ?? $violation->step, 'status' => 'awaiting_approval',
            'investigator_id' => $investigator, 'minutes' => $d['minutes'] ?? $violation->minutes, 'investigation_done_on' => now()->toDateString(), 'objection_decision' => 'توصية الموارد البشرية: '.$d['note']]);
        Audit::log('violations', $violation->id, 'recommend', null, $violation->only('recommended_degree', 'step'), $d['note']);
        $ceo = $violation->company?->ceo_employee_id ? User::where('employee_id', $violation->company->ceo_employee_id)->value('id') : null;
        Audit::notify($ceo ?: Access::usersWithRole('super'), 'مخالفة بانتظار اعتمادك', $violation->employee->name.' — '.Lookup::name('penalty_degrees', (string) $violation->recommended_degree).' · يُوقَّع خلال 30 يومًا من انتهاء التحقيق (§97)', 'approval', ['screen' => 'violations']);

        return $this->row($violation->fresh()->load('employee:id,name,emp_no'));
    }

    /** §99–103 ladder of a type (steps per occurrence). */
    private function steps(string $type): array
    {
        $meta = collect(Lookup::grouped()['violation_types'] ?? [])->firstWhere('code', $type)['meta'] ?? [];
        $steps = array_values(array_filter(array_map('trim', explode(',', (string) ($meta['steps'] ?? '')))));

        return $steps ?: ['warn', 'warn', 'warn', 'warn'];
    }

    /** CEO / super signs the degree (amount only for the financial degree), effective date and reason. */
    public function sign(Request $request, Violation $violation)
    {
        $u = $request->user();
        $acc = Access::for();
        $ok = ($u->hasRole('super') || $acc->isCeoOf($violation->company_id)) && $violation->status === 'awaiting_approval' && $violation->employee_id !== $u->employee_id;
        abort_unless($ok, 403, 'يعتمد الدرجة الرئيس التنفيذي للشركة بعد توصية الموارد البشرية');
        // §97: no sanction more than 30 days after the investigation ended and the violation was established
        $charge = (int) Settings::get('violationChargeDays', $violation->company_id, 30);
        $invDone = $violation->investigation_done_on ?? $violation->updated_at;
        abort_if($invDone->copy()->addDays($charge)->endOfDay()->isPast(), 422, "لا يجوز توقيع الجزاء بعد مضي $charge يومًا من انتهاء التحقيق (".$invDone->format('d/m/Y').') — §97');
        $degrees = Lookup::grouped()['penalty_degrees'] ?? [];
        $d = $request->validate(['signed_degree' => ['required', Rule::in(array_column($degrees, 'code'))], 'amount' => 'nullable|numeric|min:0', 'effective_from' => 'required|date', 'reason' => 'required|string|max:1000',
            'suspension_days' => 'nullable|integer|min:1|max:5', 'deprivation_months' => 'nullable|integer|min:1|max:12']);
        $deg = collect($degrees)->firstWhere('code', (string) $d['signed_degree']);
        if (! empty($deg['meta']['terminates'])) {
            abort_unless($u->hasRole('super'), 403, 'الفصل التأديبي يعتمده الرئيس التنفيذي للمجموعة بعد التحقيق (§96)');
        }
        $degree = (int) $d['signed_degree'];
        $financial = (bool) ($deg['meta']['financial'] ?? false);
        $e = $violation->employee;
        $period = substr($d['effective_from'], 0, 7);
        $extra = ['suspension_days' => null, 'deprivation_months' => null, 'deprivation_until' => null];
        // §98: fines are computed on the daily BASIC wage only — no fallback to the gross wage
        $basic = (float) $e->componentAmount('basic');
        if ($financial) {
            abort_if($basic <= 0, 422, 'لا يوجد أجر أساسي مسجّل للموظف — تُحسب الغرامة على الأجر الأساسي فقط (§98)؛ سجّل الأجر الأساسي أولًا');
        }
        $dailyBasic = round($basic / 30, 2);
        $amount = $financial ? (float) ($d['amount'] ?? Violation::stepAmount($violation->step, $dailyBasic)) : 0;
        $maxOne = round($dailyBasic * 5, 2);
        if ($financial) {
            abort_if($amount > $maxOne, 422, 'الغرامة لا تزيد على أجر 5 أيام من الأجر الأساسي ('.number_format($maxOne, 2).' ر.س) — §97');
            $monthSoFar = (float) Violation::where('employee_id', $e->id)->where('status', 'active')->where('period', $period)->where('id', '!=', $violation->id)->sum('amount');
            if ($amount > 0 && $monthSoFar + $amount > $maxOne) {
                $amount = round(max(0, $maxOne - $monthSoFar), 2); // what exceeds the monthly cap is not deducted (§97)
                $d['reason'] .= ' — خُفّضت الغرامة ليبقى مجموع غرامات الشهر ≤ أجر 5 أيام (§97)';
            }
        }
        if ($degree === Violation::DEG_SUSPENSION) {
            abort_unless(! empty($d['suspension_days']), 422, 'حدّد عدد أيام الإيقاف (1–5)');
            $used = Violation::suspensionDaysIn($e->id, $period, $violation->id);
            abort_if($used + (int) $d['suspension_days'] > 5, 422, "الإيقاف عن العمل دون أجر لا يزيد على 5 أيام في الشهر (§97) — المستخدم في $period: $used يوم");
            $extra['suspension_days'] = (int) $d['suspension_days'];
        }
        if ($degree === Violation::DEG_DEPRIVATION) {
            abort_unless(! empty($d['deprivation_months']), 422, 'حدّد مدة الحرمان من العلاوة أو الترقية بالأشهر (1–12)');
            $extra['deprivation_months'] = (int) $d['deprivation_months'];
            $extra['deprivation_until'] = Carbon::parse($d['effective_from'])->addMonthsNoOverflow((int) $d['deprivation_months'])->subDay()->toDateString();
        }
        $violation->update(['signed_degree' => $degree, 'penalty' => (string) $d['signed_degree'], 'amount' => $amount, 'basis' => $dailyBasic, 'effective_from' => $d['effective_from'],
            'period' => ($amount > 0 || $extra['suspension_days']) ? $period : null, 'signed_by' => $u->id, 'objection_decision' => $d['reason'], 'status' => 'awaiting_approval'] + $extra);
        Audit::log('violations', $violation->id, 'sign', null, $violation->only('signed_degree', 'amount', 'effective_from', 'suspension_days', 'deprivation_until'), $d['reason']);
        Audit::notify(Access::hrUsersFor($violation->company_id, 'VIO'), 'مخالفة موقَّعة بانتظار اعتماد الموارد البشرية', $violation->employee->name.' — '.($deg['name'] ?? ''), 'approval', ['screen' => 'violations']);

        return $this->row($violation->fresh()->load('employee:id,name,emp_no'));
    }

    /** HR approves the signed degree → active and notified (career entry, payroll deduction if financial, termination if degree terminates). */
    public function approve(Request $request, Violation $violation)
    {
        $u = $request->user();
        abort_unless($u->isHr() && Access::for()->can('VIO', 'approve', ['employee' => $violation->employee]) && $violation->status === 'awaiting_approval' && $violation->signed_degree !== null, 403);
        $deg = collect(Lookup::grouped()['penalty_degrees'])->firstWhere('code', (string) $violation->signed_degree);
        $violation->update(['status' => 'active', 'hr_approved_by' => $u->id, 'notified_at' => now()]);
        $e = $violation->employee;
        $detail = ($violation->amount ? " ({$violation->amount} ر.س)" : '').($violation->suspension_days ? " ({$violation->suspension_days} أيام إيقاف دون أجر)" : '').($violation->deprivation_until ? ' (حتى '.$violation->deprivation_until->format('d/m/Y').')' : '');
        $e->addCareer('مخالفة', Lookup::name('violation_types', $violation->type).' — '.($deg['name'] ?? '').$detail);
        $days = (int) Settings::get('violationObjectionDays', $e->company_id, 30);
        Audit::notify(User::where('employee_id', $e->id)->value('id'), 'صدر قرار في المخالفة', ($deg['name'] ?? '').($violation->amount ? " — خصم {$violation->amount} ر.س من راتب {$violation->period}" : '').$detail." · يحق لك التظلم خلال $days يومًا من اليوم (§105)", 'bad', ['screen' => 'violations']);
        if ($e->manager_id) {
            Audit::notify(User::where('employee_id', $e->manager_id)->value('id'), 'قرار مخالفة لأحد موظفيك', $e->name.' — '.($deg['name'] ?? ''), 'info', ['screen' => 'violations']);
        }
        if (! empty($deg['meta']['terminates'])) {
            app(TerminationController::class)->createCase($e, 'misconduct', now()->toDateString(), 'إنهاء خدمة بقرار مخالفة #'.$violation->id, $u->id);
        }
        Audit::log('violations', $violation->id, 'approve');

        return $this->row($violation->fresh()->load('employee:id,name,emp_no'));
    }

    public function cancel(Request $request, Violation $violation)
    {
        $u = $request->user();
        abort_unless(($u->isHr() && Access::for()->can('VIO', 'approve', ['employee' => $violation->employee])) || $u->hasRole('super') || Access::for()->isCeoOf($violation->company_id), 403);
        $d = $request->validate(['note' => 'required|string|max:1000']);
        $violation->update(['status' => 'cancelled', 'amount' => 0, 'suspension_days' => null, 'deprivation_until' => null, 'objection_decision' => $d['note']]);
        Audit::log('violations', $violation->id, 'cancel', null, null, $d['note']);
        Audit::notify(User::where('employee_id', $violation->employee_id)->value('id'), 'أُلغيت المخالفة المسجلة بحقك', $d['note'], 'ok', ['screen' => 'violations']);

        return $this->row($violation);
    }

    /** §105: grievance within 30 days of notification. */
    public function object(Request $request, Violation $violation)
    {
        abort_unless($violation->employee_id === $request->user()->employee_id && $violation->status === 'active' && $violation->objection === 'none', 403);
        $days = (int) Settings::get('violationObjectionDays', $violation->company_id, 30);
        $notified = $violation->notifiedDate();
        abort_unless($notified && self::grievanceEnd($violation->employee, $notified, $days)->isFuture(), 422, "انتهت مهلة التظلم ($days يومًا من تاريخ التبليغ — §105)");
        $d = $request->validate(['text' => 'required|string|max:2000']);
        $violation->update(['objection' => 'open', 'objection_text' => $d['text'], 'objection_at' => now()]);
        Audit::notify(Access::hrUsersFor($violation->company_id, 'VIO'), 'تظلم من جزاء — شكّل لجنة مستقلة (البت خلال 15 يومًا)', $violation->employee->name, 'approval', ['screen' => 'violations']);
        Audit::notify(Access::usersWithRole('super'), 'تظلم من جزاء', $violation->employee->name.' — يعتمد الرئيس التنفيذي للمجموعة قرار اللجنة (§105)', 'info', ['screen' => 'violations']);

        return $this->row($violation);
    }

    /** §105: HR (committee secretariat) forms an independent committee that excludes the investigator, recommender and signer. */
    public function committee(Request $request, Violation $violation)
    {
        $u = $request->user();
        abort_unless($u->isHr() && Access::for()->can('VIO', 'approve', ['employee' => $violation->employee]) && $violation->objection === 'open', 403);
        $d = $request->validate(['members' => 'required|array|min:1|max:5', 'members.*' => 'integer|exists:users,id']);
        $members = array_values(array_unique(array_map('intval', $d['members'])));
        $bad = array_intersect($members, $this->committeeExcluded($violation));
        abort_if($bad, 422, 'لا تضم لجنة التظلم المحقق ولا من سجّل المخالفة أو أوصى بالجزاء أو اعتمده (§105): '.User::whereIn('id', $bad)->pluck('name')->implode('، '));
        abort_if(User::whereIn('id', $members)->where('employee_id', $violation->employee_id)->exists(), 422, 'لا يكون المتظلم عضوًا في اللجنة');
        $violation->update(['committee' => $members]);
        Audit::log('violations', $violation->id, 'committee', null, ['members' => $members]);
        Audit::notify($members, 'عضوية لجنة تظلم من جزاء', $violation->employee->name.' — البت خلال 15 يومًا من تقديم التظلم', 'approval', ['screen' => 'violations']);

        return $this->row($violation);
    }

    /**
     * §105: a committee member records the committee's decision (proposed), then the group CEO approves it (final).
     */
    public function decideObjection(Request $request, Violation $violation)
    {
        $u = $request->user();
        $d = $request->validate(['decision' => 'required|in:accepted,rejected', 'note' => 'required|string|max:1000']);
        if ($violation->objection === 'open') {
            abort_unless(in_array($u->id, (array) ($violation->committee ?? []), true), 403, 'يبت في التظلم أعضاء اللجنة المستقلة (§105)');
            $violation->update(['objection' => 'proposed', 'objection_proposal' => $d['decision'], 'objection_proposal_note' => $d['note'], 'objection_proposed_by' => $u->id]);
            Audit::log('violations', $violation->id, 'objection_proposed_'.$d['decision'], null, null, $d['note']);
            Audit::notify(Access::usersWithRole('super'), 'قرار لجنة التظلم بانتظار اعتمادك', $violation->employee->name.' — '.($d['decision'] === 'accepted' ? 'قبول التظلم' : 'رفض التظلم'), 'approval', ['screen' => 'violations']);

            return $this->row($violation);
        }
        abort_unless($violation->objection === 'proposed' && $u->hasRole('super') && ! in_array($u->id, $this->committeeExcluded($violation), true), 403, 'يعتمد قرار لجنة التظلم الرئيس التنفيذي للمجموعة (§105)');
        $note = 'قرار اللجنة: '.$violation->objection_proposal_note.' — اعتماد الرئيس التنفيذي للمجموعة: '.$d['note'];
        $violation->update(['objection' => $d['decision'], 'objection_decision' => $note] + ($d['decision'] === 'accepted' ? ['status' => 'cancelled', 'amount' => 0, 'suspension_days' => null, 'deprivation_until' => null] : []));
        Audit::log('violations', $violation->id, 'objection_'.$d['decision'], null, null, $d['note']);
        Audit::notify(User::where('employee_id', $violation->employee_id)->value('id'), $d['decision'] === 'accepted' ? 'قُبل تظلمك وأُلغيت المخالفة وآثارها' : 'رُفض تظلمك — يحق لك الاعتراض أمام المحكمة العمالية خلال 30 يومًا', $d['note'], $d['decision'] === 'accepted' ? 'ok' : 'bad', ['screen' => 'violations']);

        return $this->row($violation);
    }

    /** §106: fines register — fines kept in a separate register, by company and period (read from the violations). */
    public function fines(Request $request)
    {
        $acc = Access::for();
        abort_unless($acc->canAny('VIO', 'view') || $acc->canAny('PAY', 'view'), 403);
        $from = $request->input('from', now()->startOfYear()->format('Y-m'));
        $to = $request->input('to', now()->format('Y-m'));
        $ids = $acc->employees('VIO')->pluck('id');
        $rows = Violation::with('employee:id,name,emp_no', 'company:id,name')->whereIn('employee_id', $ids)->where('status', 'active')->where('amount', '>', 0)
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->whereBetween('period', [substr($from, 0, 7), substr($to, 0, 7)])->orderBy('period')->orderBy('id')->get();
        $list = $rows->map(fn ($v) => ['id' => $v->id, 'period' => $v->period, 'employee' => $v->employee?->only('id', 'name', 'emp_no'), 'company_id' => $v->company_id, 'company' => $v->company?->name,
            'type_name' => Lookup::name('violation_types', $v->type), 'occurred_on' => $v->occurred_on?->toDateString(), 'effective_from' => $v->effective_from?->toDateString(), 'basis' => $v->basis, 'amount' => $v->amount,
            'objection' => $v->objection]);

        return ['from' => $from, 'to' => $to, 'rows' => $list->values(), 'total' => round($rows->sum('amount'), 2), 'count' => $rows->count(),
            'by_company' => $rows->groupBy('company_id')->map(fn ($g) => ['company' => $g->first()->company?->name, 'count' => $g->count(), 'total' => round($g->sum('amount'), 2)])->values(),
            'by_period' => $rows->groupBy('period')->map(fn ($g, $p) => ['period' => $p, 'count' => $g->count(), 'total' => round($g->sum('amount'), 2)])->values(),
            'note' => 'تُقيَّد الغرامات في سجل مستقل وتُصرف فيما يعود بالنفع على عمال المنشأة وفق المادة (73) من نظام العمل، ولا تُعامل إيرادًا تشغيليًا (§106)'];
    }

    /** Users who may not sit on the grievance committee nor approve it (§105). */
    private function committeeExcluded(Violation $v): array
    {
        return array_values(array_filter(array_map(fn ($x) => $x ? (int) $x : null, [$v->recorded_by, $v->investigator_id, $v->recommended_by, $v->signed_by])));
    }

    /**
     * §97: repetition is counted only against prior sanctions of the same type notified to the employee within the last
     * 180 days (lookback measured from the notification date of the prior sanction, not its occurrence).
     */
    private function priorCount(Employee $e, string $type, string $before, ?int $exceptId = null): int
    {
        $meta = collect(Lookup::grouped()['violation_types'] ?? [])->firstWhere('code', $type)['meta'] ?? [];
        $lookback = min(180, (int) ($meta['lookback_days'] ?? 180)) ?: 180;
        $start = Carbon::parse($before)->subDays($lookback)->startOfDay();

        return Violation::where('employee_id', $e->id)->where('type', $type)->where('status', 'active')->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('COALESCE(notified_at, effective_from, occurred_on) between ? and ?', [$start->toDateTimeString(), Carbon::parse($before)->endOfDay()->toDateTimeString()])->count();
    }

    private function row(Violation $v): array
    {
        $deg = $v->signed_degree !== null ? Lookup::name('penalty_degrees', (string) $v->signed_degree) : null;

        return $v->only('id', 'type', 'occurred_on', 'discovered_on', 'description', 'evidence', 'operational_impact', 'statement', 'statement_at', 'proposed_degree', 'recommended_degree', 'step', 'signed_degree', 'amount', 'basis', 'period',
            'effective_from', 'notified_at', 'investigation_done_on', 'suspension_days', 'deprivation_months', 'deprivation_until', 'minutes', 'removal_status', 'removal_note', 'removal_at',
            'status', 'objection', 'objection_text', 'objection_at', 'objection_proposal', 'objection_proposal_note', 'objection_decision', 'created_at') + [
            'type_name' => Lookup::name('violation_types', $v->type), 'proposed_name' => Lookup::name('penalty_degrees', (string) $v->proposed_degree), 'degree_name' => $deg, 'step_label' => Violation::stepLabel($v->step),
            'recommended_name' => $v->recommended_degree !== null ? Lookup::name('penalty_degrees', (string) $v->recommended_degree) : null,
            'status_label' => Violation::STATUSES[$v->status] ?? $v->status, 'objection_label' => Violation::OBJECTIONS[$v->objection] ?? $v->objection,
            'employee' => $v->relationLoaded('employee') ? $v->employee?->only('id', 'name', 'emp_no') : null, 'company' => $v->relationLoaded('company') ? $v->company?->name : null,
            'recorded_by' => $v->relationLoaded('recorder') ? $v->recorder?->name : null, 'investigator' => $v->relationLoaded('investigator') ? $v->investigator?->name : null, 'investigator_id' => $v->investigator_id,
        ];
    }

    /** §105: N days from notification, official holidays excluded (the window is extended by each holiday inside it). */
    private static function grievanceEnd($e, $notified, int $days): \Illuminate\Support\Carbon
    {
        $end = \Illuminate\Support\Carbon::parse($notified)->addDays($days);
        if ($e && class_exists(\App\Models\Holiday::class)) {
            $extra = count(\App\Models\Holiday::datesFor($e, \Illuminate\Support\Carbon::parse($notified)->toDateString(), $end->toDateString()));
            $end->addDays($extra);
        }

        return $end;
    }
}
