<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Lookup;
use App\Models\User;
use App\Models\Violation;
use App\Services\Access;
use App\Services\AttendanceService;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Violations. Types (with escalation ladder / lookback / deduction days) and penalty degrees are lookups.
 * Flow: record → employee statement (objectionDays deadline) → CEO/super signs degree → HR approves → active.
 * Degree 5 = financial (payroll deduction in the effective month); degree 7 = termination. Employee may object once.
 */
class ViolationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('VIO', 'view'), 403);
        $ids = Access::for()->employees()->pluck('id');

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
            'statement_days' => (int) Settings::get('violationStatementDays', null, 3), 'charge_days' => (int) Settings::get('violationChargeDays', null, 30), 'max_fine_days' => 5, 'max_month_days' => 5];
    }

    /** Auto-suggestions from last month's attendance: late ≥ 3 or absent ≥ 1, not yet recorded. */
    public function suggestions(Request $request)
    {
        abort_unless(Access::for()->canAny('VIO', 'view'), 403);
        $period = $request->input('period', now()->subMonthNoOverflow()->format('Y-m'));
        $out = [];
        foreach (Access::for()->employees()->where('status', '!=', 'terminated')->where('punch_required', true)->get() as $e) {
            $s = AttendanceService::summary($e, $period);
            foreach ([['late', $s['late'] >= 3, "تأخر {$s['late']} مرات في $period"], ['absence', $s['absent'] >= 1, "غياب {$s['absent']} يوم في $period"]] as [$type, $hit, $desc]) {
                if ($hit && ! Violation::where('employee_id', $e->id)->where('type', $type)->where('description', 'like', "%$period%")->exists()) {
                    $out[] = ['employee' => $e->only('id', 'name', 'emp_no'), 'type' => $type, 'type_name' => Lookup::name('violation_types', $type), 'description' => $desc, 'occurred_on' => $period.'-'.now()->parse($period.'-01')->endOfMonth()->format('d')];
                }
            }
        }

        return $out;
    }

    public function show(Request $request, Violation $violation)
    {
        $u = $request->user();
        $acc = Access::for();
        abort_unless($violation->employee_id === $u->employee_id || $acc->canSeeEmployee($violation->employee), 403);
        $days = (int) Settings::get('violationStatementDays', $violation->company_id, 3);
        $objDays = (int) Settings::get('violationObjectionDays', $violation->company_id, 30);
        $deadlinePassed = $violation->created_at->addDays($days)->isPast();
        $isCeo = $u->hasRole('super') || $acc->isCeoOf($violation->company_id);
        $isHr = $u->isHr() && $acc->can('VIO', 'approve', ['employee' => $violation->employee]);
        $prior = $this->priorCount($violation->employee, $violation->type, $violation->occurred_on->toDateString());
        $steps = $this->steps($violation->type);

        return $this->row($violation->load('employee:id,name,emp_no', 'company:id,name', 'recorder:id,name')) + [
            'prior' => $prior, 'ladder' => array_map(fn ($s) => Violation::stepLabel($s), $steps), 'step_index' => min($prior, max(0, count($steps) - 1)),
            'deadline' => $violation->created_at->addDays($days)->toDateString(), 'objection_deadline' => $violation->status === 'active' ? $violation->updated_at->addDays($objDays)->toDateString() : null,
            'can_state' => $violation->employee_id === $u->employee_id && $violation->status === 'awaiting_statement',
            'can_recommend' => $isHr && ($violation->status === 'awaiting_recommendation' || ($violation->status === 'awaiting_statement' && $deadlinePassed)),
            'can_sign' => $isCeo && $violation->status === 'awaiting_approval',
            'can_approve' => $isHr && $violation->status === 'awaiting_approval' && $violation->signed_degree !== null,
            'can_object' => $violation->employee_id === $u->employee_id && $violation->status === 'active' && $violation->objection === 'none' && $violation->effective_from && $violation->updated_at->addDays($objDays)->isFuture(),
            'can_decide_objection' => ($isHr || $isCeo) && $violation->objection === 'open',
            'can_cancel' => ($isHr || $isCeo) && ! in_array($violation->status, ['cancelled'], true),
        ];
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $types = Lookup::grouped()['violation_types'] ?? [];
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'type' => ['required', Rule::in(array_column($types, 'code'))],
            'occurred_on' => 'required|date|before_or_equal:today', 'description' => 'required|string|max:2000', 'evidence' => 'nullable|string|max:300', 'operational_impact' => 'boolean']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(Access::for()->isManagerOf($e) || Access::for()->can('VIO', 'add', ['employee' => $e]), 403);
        abort_if($e->id === $u->employee_id, 422, 'لا يمكن تسجيل مخالفة على نفسك');
        // §97: no charge for a violation discovered more than 30 days ago
        $charge = (int) Settings::get('violationChargeDays', $e->company_id, 30);
        abort_if(now()->parse($d['occurred_on'])->addDays($charge)->isPast(), 422, "لا يجوز اتهام الموظف بمخالفة مضى على كشفها أكثر من $charge يومًا (§97)");
        $steps = $this->steps($d['type']);
        $prior = $this->priorCount($e, $d['type'], $d['occurred_on']);
        $step = $steps[min($prior, max(0, count($steps) - 1))] ?? 'warn';
        $proposed = Violation::stepDegree($step);
        $v = Violation::create($d + ['company_id' => $e->company_id, 'proposed_degree' => $proposed, 'step' => $step, 'status' => 'awaiting_statement', 'recorded_by' => $u->id]);
        $days = (int) Settings::get('violationStatementDays', $e->company_id, 3);
        Audit::log('violations', $v->id, 'create', null, $v->only('type', 'occurred_on', 'proposed_degree'));
        Audit::notify(User::where('employee_id', $e->id)->value('id'), 'مخالفة مسجَّلة بحقك — مطلوب إفادتك خلال '.$days.' أيام', Lookup::name('violation_types', $v->type).' · '.$v->occurred_on->toDateString(), 'bad', ['screen' => 'violations']);
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

    /** §96 step 2: HR investigates the statement and recommends the step/degree to the company CEO. */
    public function recommend(Request $request, Violation $violation)
    {
        $u = $request->user();
        $days = (int) Settings::get('violationStatementDays', $violation->company_id, 3);
        $ok = $u->isHr() && Access::for()->can('VIO', 'approve', ['employee' => $violation->employee]) && ($violation->status === 'awaiting_recommendation' || ($violation->status === 'awaiting_statement' && $violation->created_at->addDays($days)->isPast()));
        abort_unless($ok, 403, 'التوصية للموارد البشرية بعد الإفادة أو انقضاء مهلتها');
        $degrees = Lookup::grouped()['penalty_degrees'] ?? [];
        $d = $request->validate(['recommended_degree' => ['required', Rule::in(array_column($degrees, 'code'))], 'step' => 'nullable|string|max:20', 'note' => 'required|string|max:1000']);
        $violation->update(['recommended_degree' => (int) $d['recommended_degree'], 'recommended_by' => $u->id, 'step' => $d['step'] ?? $violation->step, 'status' => 'awaiting_approval', 'objection_decision' => 'توصية الموارد البشرية: '.$d['note']]);
        Audit::log('violations', $violation->id, 'recommend', null, $violation->only('recommended_degree', 'step'), $d['note']);
        $ceo = $violation->company?->ceo_employee_id ? User::where('employee_id', $violation->company->ceo_employee_id)->value('id') : null;
        Audit::notify($ceo ?: Access::usersWithRole('super'), 'مخالفة بانتظار اعتمادك', $violation->employee->name.' — '.Lookup::name('penalty_degrees', (string) $violation->recommended_degree), 'approval', ['screen' => 'violations']);

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
        $ok = ($u->hasRole('super') || $acc->isCeoOf($violation->company_id)) && $violation->status === 'awaiting_approval';
        abort_unless($ok, 403, 'يعتمد الدرجة الرئيس التنفيذي للشركة بعد توصية الموارد البشرية');
        $degrees = Lookup::grouped()['penalty_degrees'] ?? [];
        $d = $request->validate(['signed_degree' => ['required', Rule::in(array_column($degrees, 'code'))], 'amount' => 'nullable|numeric|min:0', 'effective_from' => 'required|date', 'reason' => 'required|string|max:1000']);
        $deg = collect($degrees)->firstWhere('code', (string) $d['signed_degree']);
        if (! empty($deg['meta']['terminates'])) {
            abort_unless($u->hasRole('super'), 403, 'الفصل التأديبي يعتمده الرئيس التنفيذي للمجموعة بعد التحقيق (§96)');
        }
        $financial = (bool) ($deg['meta']['financial'] ?? false);
        // §98: fines are computed on the daily BASIC wage; §97: ≤ 5 days per violation and ≤ 5 days per month in total
        $e = $violation->employee;
        $dailyBasic = round(($e->componentAmount('basic') ?: $e->monthlyGross()) / 30, 2);
        $amount = $financial ? (float) ($d['amount'] ?? Violation::stepAmount($violation->step, $dailyBasic)) : 0;
        $maxOne = round($dailyBasic * 5, 2);
        abort_if($amount > $maxOne, 422, 'الغرامة لا تزيد على أجر 5 أيام من الأجر الأساسي ('.number_format($maxOne, 2).' ر.س) — §97');
        $period = substr($d['effective_from'], 0, 7);
        $monthSoFar = (float) Violation::where('employee_id', $e->id)->where('status', 'active')->where('period', $period)->where('id', '!=', $violation->id)->sum('amount');
        if ($amount > 0 && $monthSoFar + $amount > $maxOne) {
            $amount = round(max(0, $maxOne - $monthSoFar), 2); // what exceeds the monthly cap is not deducted (§97)
            $d['reason'] .= ' — خُفّضت الغرامة ليبقى مجموع غرامات الشهر ≤ أجر 5 أيام (§97)';
        }
        $violation->update(['signed_degree' => (int) $d['signed_degree'], 'penalty' => (string) $d['signed_degree'], 'amount' => $amount, 'basis' => $dailyBasic, 'effective_from' => $d['effective_from'],
            'period' => $amount > 0 ? $period : null, 'signed_by' => $u->id, 'objection_decision' => $d['reason'], 'status' => 'awaiting_approval']);
        Audit::log('violations', $violation->id, 'sign', null, $violation->only('signed_degree', 'amount', 'effective_from'), $d['reason']);
        Audit::notify(Access::hrUsersFor($violation->company_id, 'VIO'), 'مخالفة موقَّعة بانتظار اعتماد الموارد البشرية', $violation->employee->name.' — '.($deg['name'] ?? ''), 'approval', ['screen' => 'violations']);

        return $this->row($violation->fresh()->load('employee:id,name,emp_no'));
    }

    /** HR approves the signed degree → active (career entry, payroll deduction if financial, termination if degree terminates). */
    public function approve(Request $request, Violation $violation)
    {
        $u = $request->user();
        abort_unless($u->isHr() && Access::for()->can('VIO', 'approve', ['employee' => $violation->employee]) && $violation->status === 'awaiting_approval' && $violation->signed_degree !== null, 403);
        $deg = collect(Lookup::grouped()['penalty_degrees'])->firstWhere('code', (string) $violation->signed_degree);
        $violation->update(['status' => 'active', 'hr_approved_by' => $u->id]);
        $e = $violation->employee;
        $e->addCareer('مخالفة', Lookup::name('violation_types', $violation->type).' — '.($deg['name'] ?? '').($violation->amount ? " ({$violation->amount} ر.س)" : ''));
        $days = (int) Settings::get('violationObjectionDays', $e->company_id, 30);
        Audit::notify(User::where('employee_id', $e->id)->value('id'), 'صدر قرار في المخالفة', ($deg['name'] ?? '').($violation->amount ? " — خصم {$violation->amount} ر.س من راتب {$violation->period}" : '')." · يحق لك التظلم خلال $days يومًا (§105)", 'bad', ['screen' => 'violations']);
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
        $violation->update(['status' => 'cancelled', 'amount' => 0, 'objection_decision' => $d['note']]);
        Audit::log('violations', $violation->id, 'cancel', null, null, $d['note']);
        Audit::notify(User::where('employee_id', $violation->employee_id)->value('id'), 'أُلغيت المخالفة المسجلة بحقك', $d['note'], 'ok', ['screen' => 'violations']);

        return $this->row($violation);
    }

    public function object(Request $request, Violation $violation)
    {
        abort_unless($violation->employee_id === $request->user()->employee_id && $violation->status === 'active' && $violation->objection === 'none', 403);
        $days = (int) Settings::get('violationObjectionDays', $violation->company_id, 30);
        abort_unless($violation->updated_at->addDays($days)->isFuture(), 422, "انتهت مهلة التظلم ($days يومًا — §105)");
        $d = $request->validate(['text' => 'required|string|max:2000']);
        $violation->update(['objection' => 'open', 'objection_text' => $d['text']]);
        $ceo = $violation->company?->ceo_employee_id ? User::where('employee_id', $violation->company->ceo_employee_id)->value('id') : null;
        Audit::notify([...(array) $ceo, ...Access::hrUsersFor($violation->company_id, 'VIO')], 'اعتراض على مخالفة', $violation->employee->name, 'approval', ['screen' => 'violations']);

        return $this->row($violation);
    }

    public function decideObjection(Request $request, Violation $violation)
    {
        $u = $request->user();
        // §105: a committee that excludes whoever investigated, recommended or signed; the group CEO approves
        abort_unless($violation->objection === 'open' && ($u->hasRole('super') || (($u->isHr() || Access::for()->isCeoOf($violation->company_id)) && ! in_array($u->id, [$violation->recorded_by, $violation->recommended_by, $violation->signed_by], true))), 403, 'يبت في التظلم من لم يسجل المخالفة أو يوصِ بها أو يوقّعها، ويعتمده الرئيس التنفيذي للمجموعة');
        $d = $request->validate(['decision' => 'required|in:accepted,rejected', 'note' => 'required|string|max:1000']);
        $violation->update(['objection' => $d['decision'], 'objection_decision' => $d['note']] + ($d['decision'] === 'accepted' ? ['status' => 'cancelled', 'amount' => 0] : []));
        Audit::log('violations', $violation->id, 'objection_'.$d['decision'], null, null, $d['note']);
        Audit::notify(User::where('employee_id', $violation->employee_id)->value('id'), $d['decision'] === 'accepted' ? 'قُبل اعتراضك وأُلغيت المخالفة' : 'رُفض اعتراضك — المخالفة نافذة', $d['note'], $d['decision'] === 'accepted' ? 'ok' : 'bad', ['screen' => 'violations']);

        return $this->row($violation);
    }

    private function priorCount(Employee $e, string $type, string $before): int
    {
        $meta = collect(Lookup::grouped()['violation_types'] ?? [])->firstWhere('code', $type)['meta'] ?? [];
        $lookback = (int) ($meta['lookback_days'] ?? 365);

        return Violation::where('employee_id', $e->id)->where('type', $type)->where('status', 'active')
            ->whereBetween('occurred_on', [now()->parse($before)->subDays($lookback)->toDateString(), $before])->count();
    }

    private function row(Violation $v): array
    {
        $deg = $v->signed_degree !== null ? Lookup::name('penalty_degrees', (string) $v->signed_degree) : null;

        return $v->only('id', 'type', 'occurred_on', 'description', 'evidence', 'operational_impact', 'statement', 'statement_at', 'proposed_degree', 'recommended_degree', 'step', 'signed_degree', 'amount', 'basis', 'period', 'effective_from', 'status', 'objection', 'objection_text', 'objection_decision', 'created_at') + [
            'type_name' => Lookup::name('violation_types', $v->type), 'proposed_name' => Lookup::name('penalty_degrees', (string) $v->proposed_degree), 'degree_name' => $deg, 'step_label' => Violation::stepLabel($v->step),
            'recommended_name' => $v->recommended_degree !== null ? Lookup::name('penalty_degrees', (string) $v->recommended_degree) : null,
            'status_label' => Violation::STATUSES[$v->status] ?? $v->status,
            'employee' => $v->relationLoaded('employee') ? $v->employee?->only('id', 'name', 'emp_no') : null, 'company' => $v->relationLoaded('company') ? $v->company?->name : null,
            'recorded_by' => $v->relationLoaded('recorder') ? $v->recorder?->name : null,
        ];
    }
}
