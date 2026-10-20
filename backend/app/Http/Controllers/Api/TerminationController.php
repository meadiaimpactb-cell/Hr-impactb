<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Lookup;
use App\Models\Termination;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Integrations\OdooSync;
use App\Services\Settings;
use App\Services\TerminationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * End of service (chapter 19 + appendix 5):
 * - §108 resignation: HR records it with its filing date → accepted by the chain (manager → HR → company CEO), or
 *   automatically 30 days after filing; the company CEO / HR may postpone acceptance (written reason, before day 30,
 *   up to 60 days); the employee may withdraw within 7 days unless already accepted.
 * - §109 employer termination of a non-leader: pre-termination checklist, then manager → finance → company CEO →
 *   group CEO before it takes effect (leaders: finance → group CEO).
 * - §111 clearance: parallel tasks; sign-off manager → HR → support services → finance → company CEO.
 * - Appendix 5 settlement: manager → HR prepares → company CEO → finance approves and pays. Dues are never held for the clearance.
 */
class TerminationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('TRM', 'view'), 403);
        $ids = Access::for()->employees()->pluck('id');

        return Termination::with('employee:id,name,emp_no', 'company:id,name')->whereIn('employee_id', $ids)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->get()->map(fn ($t) => $this->row($t));
    }

    /** The employee's own cases (resignation withdrawal, settlement statement review). */
    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return Termination::with('company:id,name')->where('employee_id', $e->id)->whereNotIn('status', ['cancelled'])->orderByDesc('id')->get()
            ->map(fn ($t) => $this->row($t) + $this->resignInfo($t, $request->user()) + ['settlement' => in_array($t->status, ['open', 'settled', 'paid'], true) ? $t->settlement : null, 'settlement_due' => $t->settlementDue()?->toDateString()]);
    }

    public function show(Request $request, Termination $termination)
    {
        $u = $request->user();
        abort_unless($termination->employee_id === $u->employee_id || Access::for()->canSeeEmployee($termination->employee), 403);
        $t = $termination->load('employee:id,name,emp_no,hire_date,manager_id,company_id,contract_type', 'company:id,name');
        if ($this->flowActive($t, 'clearance') && ! $t->flow('clearance')) { // cases opened before the §111 chain existed
            $t->update(['flows' => array_merge($t->flows ?? [], ['clearance' => TerminationService::newFlow('clearance')])]);
        }
        $stageRole = array_keys(Termination::STAGES)[$t->stage] ?? null;
        $flows = [];
        foreach (['decision', 'resign', 'clearance'] as $k) {
            if ($f = $t->flow($k)) {
                $next = $f['done'] ? null : ($f['chain'][$f['stage']] ?? null);
                $flows[$k] = $f + ['label' => Termination::FLOW_LABELS[$k], 'steps' => array_map(fn ($r) => ['role' => $r, 'label' => Termination::ROLES[$r] ?? $r], $f['chain']),
                    'next' => $next ? (Termination::ROLES[$next] ?? $next) : null, 'can_act' => $next && $this->flowActive($t, $k) && $this->isStageActor($u, $t, $next) && $this->flowReady($t, $k, $next) === null,
                    'blocked' => $next ? $this->flowReady($t, $k, $next) : null];
            }
        }
        $due = $t->settlementDue();

        return $this->row($t) + $this->resignInfo($t, $u) + ['clearance' => $t->clearance, 'eosb' => $t->eosb, 'settlement' => $t->settlement, 'approvals' => $t->approvals ?? [], 'checklist' => $t->checklist, 'flows' => $flows,
            'settlement_due' => $due?->toDateString(), 'settlement_due_rule' => $t->byEmployee() ? 'أسبوعان من انتهاء العقد (الموظف أنهاه) — §112' : 'أسبوع من انتهاء العقد — §112',
            'next_stage' => $stageRole && $t->status === 'open' ? Termination::STAGES[$stageRole] : null,
            'can_approve' => $t->status === 'open' && $stageRole && $this->isStageActor($u, $t, $stageRole),
            'can_manage' => $u->isHr() && Access::for()->can('TRM', 'edit', ['employee' => $t->employee]), 'can_pay' => $u->hasRole('fin', 'super') && $t->status === 'settled',
            'reasons' => Lookup::grouped()['termination_reasons'] ?? []];
    }

    /** EOSB simulator for any employee in scope. */
    public function simulate(Request $request, Employee $employee)
    {
        abort_unless(Access::for()->canSeeSalary($employee) || $employee->id === $request->user()->employee_id, 403);
        $d = $request->validate(['end' => 'nullable|date', 'reason' => 'nullable|string']);

        return TerminationService::eosb($employee, $d['end'] ?? now()->toDateString(), $d['reason'] ?? 'resign') + [
            'notice_days' => TerminationService::noticeDays($employee, ($d['reason'] ?? 'resign') === 'resign' ? 'employee' : 'employer', $d['reason'] ?? null)];
    }

    /**
     * HR opens a case directly (any reason except resignation, recorded through resignation()). An employer termination
     * (§109) needs the pre-termination checklist and goes through the decision chain before it takes effect.
     */
    public function store(Request $request)
    {
        abort_unless($request->user()->isHr() && Access::for()->canAny('TRM', 'add'), 403);
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'reason' => ['required', Rule::in(array_column(Lookup::grouped()['termination_reasons'] ?? [], 'code')), 'not_in:resign'],
            'last_working_day' => 'required|date', 'note' => 'required|string|max:500',
            'checklist' => 'nullable|array', 'checklist.contract_type' => 'nullable|string|max:60', 'checklist.reason_detail' => 'nullable|string|max:1000', 'checklist.notice' => 'nullable|in:served,compensated',
            'checklist.compensation' => 'nullable|string|max:500', 'checklist.alternatives' => 'nullable|string|max:1000', 'checklist.operational' => 'boolean', 'checklist.protected_checked' => 'boolean']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(Access::for()->can('TRM', 'add', ['employee' => $e]), 403);
        abort_if($e->id === $request->user()->employee_id, 422, 'لا يفتح أحد حالة إنهاء تخصه');
        abort_if(Termination::where('employee_id', $e->id)->whereIn('status', Termination::LIVE)->exists(), 422, 'توجد حالة إنهاء قائمة لهذا الموظف');
        if (in_array($d['reason'], Termination::DECISION_REASONS, true)) {
            $c = $d['checklist'] ?? [];
            // §109: before notifying the termination HR checks the contract type, the reason, the notice, compensation and (operational reasons) the alternatives
            $missing = array_filter(['نوع العقد' => $c['contract_type'] ?? null, 'سبب الإنهاء ومسوغه' => $c['reason_detail'] ?? null, 'الإشعار' => $c['notice'] ?? null, 'التعويض' => $c['compensation'] ?? null], fn ($v) => blank($v));
            abort_if($missing, 422, 'قائمة الفحص قبل الإنهاء غير مكتملة (§109): '.implode('، ', array_keys($missing)));
            abort_if(! empty($c['operational']) && blank($c['alternatives'] ?? null), 422, 'السبب تشغيلي: وثّق البدائل التي رُوجعت (إعادة توزيع الدور، النقل بموافقة…) — §109');
            $notice = TerminationService::noticeDays($e, 'employer', $d['reason']);
            if (($c['notice'] ?? '') === 'served') {
                $min = now()->addDays($notice)->toDateString();
                abort_if($d['last_working_day'] < $min, 422, "إشعار صاحب العمل $notice يومًا (§108): آخر يوم عمل لا يسبق ".Carbon::parse($min)->format('d/m/Y').'، أو اختر التعويض عن مدة الإشعار');
            }
            $leader = TerminationService::isLeader($e);
            $flow = TerminationService::newFlow($leader ? 'decision_leader' : 'decision');
            $t = $this->createCase($e, $d['reason'], $d['last_working_day'], $d['note'], $request->user()->id, null, 'pending_decision',
                ['checklist' => $c + ['checked_by' => $request->user()->name, 'checked_at' => now()->toDateTimeString(), 'leader' => $leader], 'flows' => ['decision' => $flow]]);
            $this->notifyNext($t, 'decision');

            return response()->json($this->row($t), 201);
        }

        return response()->json($this->row($this->createCase($e, $d['reason'], $d['last_working_day'], $d['note'], $request->user()->id)), 201);
    }

    /** §108: HR records a resignation submitted in writing (signed letter or corporate email) with its filing date. */
    public function resignation(Request $request)
    {
        $u = $request->user();
        abort_unless($u->isHr() && Access::for()->canAny('TRM', 'add'), 403);
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'filed_on' => 'required|date|before_or_equal:today', 'last_working_day' => 'nullable|date|after_or_equal:filed_on',
            'note' => 'required|string|max:1000']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(Access::for()->can('TRM', 'add', ['employee' => $e]), 403);
        abort_if(Termination::where('employee_id', $e->id)->whereIn('status', Termination::LIVE)->exists(), 422, 'توجد حالة إنهاء قائمة لهذا الموظف');
        $notice = TerminationService::noticeDays($e, 'employee', 'resign');
        $last = $d['last_working_day'] ?? Carbon::parse($d['filed_on'])->addDays($notice)->toDateString();
        $t = $this->createCase($e, 'resign', $last, $d['note'], $u->id, null, 'resign_pending', ['filed_on' => $d['filed_on'], 'flows' => ['resign' => TerminationService::newFlow('resign')]]);
        $filed = Carbon::parse($d['filed_on']);
        Audit::notify(User::where('employee_id', $e->id)->value('id'), 'قُيّدت استقالتك بتاريخ '.$filed->format('d/m/Y'),
            'تُعد مقبولة بمضي 30 يومًا ('.$filed->copy()->addDays(30)->format('d/m/Y').') ما لم تُقبل أو يُؤجَّل قبولها كتابةً، ويحق لك العدول عنها حتى '.$filed->copy()->addDays(7)->format('d/m/Y').' ما لم تُقبل (§108)', 'info', ['screen' => 'my-termination']);
        $this->notifyNext($t, 'resign');
        if ($filed->copy()->addDays(30)->lte(now()->startOfDay())) {
            $this->acceptResignation($t, 'auto'); // recorded late: already accepted by law
        }

        return response()->json($this->row($t->fresh()->load('employee:id,name,emp_no', 'company:id,name')), 201);
    }

    /** §108 resignation actions: approve (chain step), postpone (company CEO / HR, before day 30, ≤ 60 days, written reason), withdraw (employee, 7 days). */
    public function resignAction(Request $request, Termination $termination)
    {
        $u = $request->user();
        $t = $termination;
        abort_unless($t->reason === 'resign' && in_array($t->status, ['resign_pending', 'resign_postponed'], true), 422, 'ليست استقالة بانتظار القبول');
        $d = $request->validate(['action' => 'required|in:approve,postpone,withdraw', 'note' => 'nullable|string|max:1000', 'until' => 'nullable|date']);
        $filed = $t->filed_on ?? $t->requested_at;
        if ($d['action'] === 'withdraw') {
            $own = $t->employee_id === $u->employee_id;
            abort_unless($own || ($u->isHr() && Access::for()->can('TRM', 'edit', ['employee' => $t->employee])), 403);
            abort_if(! $own && blank($d['note'] ?? null), 422, 'سجّل ما يثبت عدول الموظف (خطاب أو بريد)');
            abort_if($filed->copy()->addDays(7)->endOfDay()->isPast(), 422, 'انقضت مهلة العدول (7 أيام من تقديم الاستقالة — §108)');
            $t->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'note' => trim($t->note."\nعدول: ".($d['note'] ?? 'من الموظف عبر المنصة'))]);
            $t->employee->update(['status' => 'active', 'last_working_day' => null]);
            Audit::log('terminations', $t->id, 'resign_withdraw', null, null, $d['note'] ?? null);
            Audit::notify([...Access::hrUsersFor($t->company_id, 'TRM'), ...array_filter([User::where('employee_id', $t->employee->manager_id)->value('id')])], 'عدول عن استقالة', $t->employee->name, 'info', ['screen' => 'terminations']);
            Audit::notify(User::where('employee_id', $t->employee_id)->value('id'), 'سُجّل عدولك عن الاستقالة', 'يستمر عقدك دون انقطاع', 'ok', ['screen' => 'my-termination']);

            return $this->show($request, $t->fresh());
        }
        if ($d['action'] === 'postpone') {
            $acc = Access::for();
            abort_unless(($u->hasRole('super') || $acc->isCeoOf($t->company_id) || ($u->isHr() && $acc->can('TRM', 'approve', ['employee' => $t->employee]))) && $u->employee_id !== $t->employee_id, 403, 'التأجيل للرئيس التنفيذي للشركة أو الموارد البشرية');
            abort_if($t->status !== 'resign_pending', 422, 'سبق تأجيل قبول هذه الاستقالة');
            abort_if($filed->copy()->addDays(30)->startOfDay()->lte(now()->startOfDay()), 422, 'يُقدَّم إيضاح التأجيل قبل نهاية الثلاثين يومًا من تقديم الاستقالة (§108)');
            abort_if(blank($d['note'] ?? null) || mb_strlen(trim($d['note'])) < 10, 422, 'التأجيل بإيضاح مكتوب مسبَّب لمصلحة العمل (§108)');
            $max = now()->addDays(60)->toDateString();
            $until = $d['until'] ?? $max;
            abort_if($until > $max || $until <= now()->toDateString(), 422, 'مدة التأجيل حتى 60 يومًا من تاريخ الإيضاح ('.Carbon::parse($max)->format('d/m/Y').') — §108');
            $t->update(['status' => 'resign_postponed', 'postponed_until' => $until, 'postpone_reason' => $d['note'], 'postponed_by' => $u->id,
                'last_working_day' => max($t->last_working_day->toDateString(), $until)]);
            $t->employee->update(['last_working_day' => $t->last_working_day]);
            Audit::log('terminations', $t->id, 'resign_postpone', null, ['until' => $until], $d['note']);
            Audit::notify(User::where('employee_id', $t->employee_id)->value('id'), 'أُجّل قبول استقالتك حتى '.Carbon::parse($until)->format('d/m/Y'), $d['note'].' — يستمر العقد خلال المدة (§108)', 'warn', ['screen' => 'my-termination']);

            return $this->show($request, $t->fresh());
        }
        // approve: one step of the resign chain
        $this->advance($request, $t, 'resign', $d['note'] ?? null);
        $t->refresh();
        if ($t->flow('resign')['done'] ?? false) {
            $this->acceptResignation($t, 'approval');
        }

        return $this->show($request, $t->fresh());
    }

    /** Resignation accepted (by the chain, or automatically at day 30 / end of postponement) → exit procedures start. */
    public function acceptResignation(Termination $t, string $how): Termination
    {
        if (! in_array($t->status, ['resign_pending', 'resign_postponed'], true)) {
            return $t;
        }
        $last = $t->last_working_day && $t->last_working_day->gte(now()->startOfDay()) ? $t->last_working_day->toDateString() : now()->toDateString();
        $flows = $t->flows ?? [];
        if (isset($flows['resign']) && ! $flows['resign']['done']) {
            $flows['resign']['done'] = true;
            $flows['resign']['approvals'][] = ['role' => 'auto', 'label' => 'قبول نظامي بمضي المدة (§108)', 'by' => 'المنصة', 'at' => now()->toDateTimeString(), 'note' => null];
        }
        $t->update(['accepted_at' => now(), 'accepted_how' => $how, 'last_working_day' => $last, 'flows' => $flows]);
        $this->activate($t->fresh(), $how === 'auto' ? 'قُبلت الاستقالة نظامًا بمضي المدة (§108)' : 'قُبلت الاستقالة');
        Audit::log('terminations', $t->id, 'resign_accept', null, ['how' => $how]);

        return $t->fresh();
    }

    /** §109 decision chain: approve / reject. On the final approval the termination takes effect. */
    public function decision(Request $request, Termination $termination)
    {
        $t = $termination;
        abort_unless($t->status === 'pending_decision', 422, 'لا يوجد قرار إنهاء بانتظار الاعتماد');
        $d = $request->validate(['action' => 'required|in:approve,reject', 'note' => 'nullable|string|max:1000']);
        if ($d['action'] === 'reject') {
            $f = $t->flow('decision');
            $role = $f['chain'][$f['stage']] ?? null;
            abort_unless($role && $this->isStageActor($request->user(), $t, $role), 403, 'ليست مرحلتك');
            abort_if(blank($d['note'] ?? null), 422, 'سبب الرفض إلزامي');
            $t->update(['status' => 'rejected', 'note' => trim($t->note."\nرفض ".(Termination::ROLES[$role] ?? $role).': '.$d['note'])]);
            Audit::log('terminations', $t->id, 'decision_reject', null, null, $d['note']);
            Audit::notify(Access::hrUsersFor($t->company_id, 'TRM'), 'رُفض قرار إنهاء الخدمة', $t->employee->name.' — '.$d['note'], 'bad', ['screen' => 'terminations']);

            return $this->show($request, $t->fresh());
        }
        $this->advance($request, $t, 'decision', $d['note'] ?? null);
        $t->refresh();
        if ($t->flow('decision')['done'] ?? false) {
            $this->activate($t, 'اعتُمد قرار الإنهاء');
        }

        return $this->show($request, $t->fresh());
    }

    /** §111 clearance sign-off by each party in order (tasks themselves are done in parallel through task()). */
    public function clearanceApprove(Request $request, Termination $termination)
    {
        $t = $termination;
        abort_unless(in_array($t->status, ['open', 'settled', 'paid'], true), 422, 'إخلاء الطرف يبدأ بعد نفاذ الإنهاء');
        $d = $request->validate(['note' => 'nullable|string|max:500']);
        $this->advance($request, $t, 'clearance', $d['note'] ?? null);
        $t->refresh();
        if ($t->flow('clearance')['done'] ?? false) {
            $tasks = collect($t->clearance)->map(fn ($x) => $x['key'] === 'clearance' && ! $x['done'] ? array_merge($x, ['done' => true, 'evidence' => 'اعتماد الرئيس التنفيذي للشركة', 'by' => $request->user()->name, 'at' => now()->toDateTimeString()]) : $x)->all();
            $t->update(['clearance' => $tasks]);
            Audit::notify([...Access::hrUsersFor($t->company_id, 'TRM'), ...array_filter([User::where('employee_id', $t->employee_id)->value('id')])], 'اكتمل إخلاء الطرف', $t->employee->name, 'ok', ['screen' => 'terminations']);
        }

        return $this->show($request, $t->fresh());
    }

    /**
     * Shared entry point: resignation request approval, misconduct (violation degree 7), HR. $status 'open' takes effect
     * immediately; 'resign_pending' / 'pending_decision' wait for their chain (the employee is not touched yet).
     */
    public function createCase(Employee $e, string $reason, string $lastDay, ?string $note = null, ?int $by = null, ?int $requestId = null, string $status = 'open', array $extra = []): Termination
    {
        $initiated = $reason === 'resign' ? 'employee' : 'employer';
        $notice = TerminationService::noticeDays($e, $initiated, $reason);
        $flows = ($extra['flows'] ?? []);
        unset($extra['flows']);
        $t = Termination::create(['employee_id' => $e->id, 'company_id' => $e->company_id, 'reason' => $reason, 'initiated_by' => $initiated, 'requested_at' => now()->toDateString(), 'notice_days' => $notice,
            'last_working_day' => $lastDay, 'note' => $note, 'request_id' => $requestId, 'clearance' => TerminationService::clearanceTasks(),
            'eosb' => TerminationService::eosb($e, $lastDay, $reason), 'stage' => 0, 'approvals' => [], 'flows' => $flows, 'status' => $status, 'created_by' => $by ?? auth()->id()] + $extra);
        Audit::log('terminations', $t->id, 'create', null, $t->only('reason', 'last_working_day', 'status'));
        if ($status === 'open') {
            if ($reason === 'resign' && $requestId) {
                $t->update(['filed_on' => $t->requested_at, 'accepted_at' => now(), 'accepted_how' => 'request']);
            }
            $this->activate($t, null);
        } else {
            Audit::notify(Access::hrUsersFor($e->company_id, 'TRM'), $status === 'resign_pending' ? 'قُيّدت استقالة' : 'قرار إنهاء خدمة بانتظار الاعتماد', $e->name.' — '.Lookup::name('termination_reasons', $reason), 'info', ['screen' => 'terminations']);
        }

        return $t->fresh()->load('employee:id,name,emp_no', 'company:id,name');
    }

    /** The case takes effect: employee exiting, settlement built, clearance chain starts, notifications. */
    private function activate(Termination $t, ?string $why): void
    {
        $e = $t->employee;
        $flows = $t->flows ?? [];
        $flows['clearance'] ??= TerminationService::newFlow('clearance');
        $t->update(['status' => 'open', 'flows' => $flows, 'eosb' => TerminationService::eosb($e, $t->last_working_day->toDateString(), $t->reason)]);
        $e->update(['status' => 'exiting', 'last_working_day' => $t->last_working_day]); // before the settlement so the last-period proration uses it
        $t->update(['settlement' => TerminationService::settlement($t->fresh()->load('employee'))]);
        $last = $t->last_working_day->toDateString();
        $e->addCareer('إنهاء خدمة', Lookup::name('termination_reasons', $t->reason).' — آخر يوم عمل '.$t->last_working_day->format('d/m/Y').($why ? ' — '.$why : ''));
        Audit::notify(User::where('employee_id', $e->id)->value('id'), $t->reason === 'resign' ? 'قُبلت استقالتك' : 'فُتحت حالة إنهاء خدمتك', 'آخر يوم عمل '.$t->last_working_day->format('d/m/Y').' — تابع إخلاء الطرف والتصفية', 'info', ['screen' => 'my-termination']);
        Audit::notify(Access::hrUsersFor($e->company_id, 'TRM'), 'حالة إنهاء خدمة نافذة', $e->name.' — '.Lookup::name('termination_reasons', $t->reason).' · آخر يوم '.$last, 'info', ['screen' => 'terminations']);
        $this->notifyNext($t->fresh(), 'clearance');
    }

    /** One step of a chain (decision / resign / clearance) by the current stage actor. */
    private function advance(Request $request, Termination $t, string $key, ?string $note): void
    {
        $u = $request->user();
        $f = $t->flow($key);
        abort_unless($f && ! $f['done'], 422, 'لا توجد مرحلة اعتماد قائمة');
        $role = $f['chain'][$f['stage']] ?? null;
        abort_unless($role && $this->isStageActor($u, $t, $role), 403, 'ليست مرحلتك');
        if ($why = $this->flowReady($t, $key, $role)) {
            abort(422, $why);
        }
        $f['approvals'][] = ['role' => $role, 'label' => Termination::ROLES[$role] ?? $role, 'by' => $u->name, 'at' => now()->toDateTimeString(), 'note' => $note];
        $f['stage']++;
        $f['done'] = $f['stage'] >= count($f['chain']);
        $t->update(['flows' => array_merge($t->flows ?? [], [$key => $f])]);
        Audit::log('terminations', $t->id, $key.'_'.$role, null, ['stage' => $f['stage']], $note);
        if (! $f['done']) {
            $this->notifyNext($t->fresh(), $key);
        }
    }

    /** Null when the stage may act; otherwise why not. Clearance: each party signs once its own tasks are done. */
    private function flowReady(Termination $t, string $key, string $role): ?string
    {
        if ($key !== 'clearance') {
            return null;
        }
        $owner = ['mgr' => ['mgr'], 'hr' => ['hr'], 'custodian' => ['it'], 'fin' => ['fin'], 'ceo' => ['mgr', 'hr', 'it', 'fin']][$role] ?? [];
        $open = collect($t->clearance)->filter(fn ($x) => in_array($x['owner'], $owner, true) && ! $x['done'] && $x['key'] !== 'clearance');

        return $open->isNotEmpty() ? 'أكمل مهام الإخلاء الخاصة بهذه المرحلة أولًا: '.$open->pluck('label')->implode('، ') : null;
    }

    private function flowActive(Termination $t, string $key): bool
    {
        return match ($key) {
            'decision' => $t->status === 'pending_decision',
            'resign' => in_array($t->status, ['resign_pending', 'resign_postponed'], true),
            'clearance' => in_array($t->status, ['open', 'settled', 'paid'], true),
            default => false,
        };
    }

    private function notifyNext(Termination $t, string $key): void
    {
        $f = $t->flow($key);
        $role = $f ? ($f['chain'][$f['stage']] ?? null) : null;
        if (! $role || $f['done']) {
            return;
        }
        Audit::notify($this->actorIds($t, $role), (Termination::FLOW_LABELS[$key] ?? 'اعتماد').' بانتظارك', $t->employee->name.' — '.Lookup::name('termination_reasons', $t->reason), 'approval', ['screen' => 'terminations']);
    }

    private function actorIds(Termination $t, string $role): array
    {
        $ceo = ($c = $t->company?->ceo_employee_id) ? User::where('employee_id', $c)->value('id') : null;

        return array_values(array_filter(match ($role) {
            'mgr' => $t->employee->manager_id ? [User::where('employee_id', $t->employee->manager_id)->value('id')] : Access::hrUsersFor($t->company_id, 'TRM'),
            'hr' => Access::hrUsersFor($t->company_id, 'TRM'),
            'ceo' => $ceo ? [$ceo] : Access::usersWithRole('super'),
            'gceo' => Access::usersWithRole('super'),
            'fin' => Access::usersWithRole('fin'),
            'custodian' => Access::usersWithRole('custodian') ?: Access::hrUsersFor($t->company_id, 'TRM'),
            default => [],
        }));
    }

    public function task(Request $request, Termination $termination)
    {
        $u = $request->user();
        abort_unless(in_array($termination->status, ['open', 'settled', 'paid'], true), 422, 'مهام الإخلاء تبدأ بعد نفاذ الإنهاء');
        $d = $request->validate(['key' => 'required|string', 'evidence' => 'required|string|max:500']);
        $tasks = $termination->clearance;
        $i = collect($tasks)->search(fn ($x) => $x['key'] === $d['key']);
        abort_if($i === false, 404);
        $owner = $tasks[$i]['owner'];
        $ok = $u->hasRole('super') || match ($owner) { 'it' => $u->hasRole('custodian') || $u->isHr(), 'fin' => $u->hasRole('fin'), 'mgr' => Access::for()->isManagerOf($termination->employee) || $u->isHr(), default => $u->isHr() };
        abort_unless($ok, 403, 'هذه المهمة من اختصاص جهة أخرى');
        $tasks[$i] = array_merge($tasks[$i], ['done' => true, 'evidence' => $d['evidence'], 'by' => $u->name, 'at' => now()->toDateTimeString()]);
        $termination->update(['clearance' => $tasks]);

        return ['clearance' => $tasks];
    }

    public function adjustEosb(Request $request, Termination $termination)
    {
        abort_unless($request->user()->isHr() && Access::for()->can('TRM', 'edit', ['employee' => $termination->employee]) && $termination->status === 'open', 403);
        $d = $request->validate(['amount' => 'nullable|numeric|min:0', 'reason' => 'required|string|max:300']);
        $termination->update(['eosb_adjusted' => $d['amount'], 'eosb_adjust_reason' => $d['reason']]);
        Audit::log('terminations', $termination->id, 'eosb_adjust', ['auto' => $termination->eosb['amount']], ['adjusted' => $d['amount']], $d['reason']);

        return $this->rebuild($request, $termination);
    }

    public function rebuild(Request $request, Termination $termination)
    {
        abort_unless($request->user()->isHr() && Access::for()->can('TRM', 'edit', ['employee' => $termination->employee]) && $termination->status === 'open' && $termination->stage < 4, 403);
        $termination->update(['settlement' => TerminationService::settlement($termination->load('employee')), 'stage' => 0, 'approvals' => []]);

        return $this->show($request, $termination->fresh());
    }

    /**
     * Settlement approvals (appendix 5): manager m1 → HR prepares → company CEO m2 → finance final (then executes the
     * payment). After the last, the employee is terminated and the account deactivated. Clearance is not a precondition (§111).
     */
    public function approve(Request $request, Termination $termination)
    {
        $u = $request->user();
        $stageRole = array_keys(Termination::STAGES)[$termination->stage] ?? null;
        abort_unless($termination->status === 'open' && $stageRole && $this->isStageActor($u, $termination, $stageRole), 403, 'ليست مرحلتك');
        $d = $request->validate(['note' => 'nullable|string|max:300']);
        $open = collect($termination->clearance)->where('done', false)->count();
        // §111: undisputed statutory dues are not held until clearance completes — open items are only flagged in the approval note
        if ($stageRole === 'fin' && $open) {
            $d['note'] = trim(($d['note'] ?? '')." — اعتُمد الصرف مع $open مهام إخلاء مفتوحة (تُتابع على حدة، §111)");
        }
        $appr = [...($termination->approvals ?? []), ['role' => $stageRole, 'label' => Termination::STAGES[$stageRole], 'by' => $u->name, 'at' => now()->toDateTimeString(), 'note' => $d['note'] ?? null]];
        $termination->update(['stage' => $termination->stage + 1, 'approvals' => $appr]);
        if ($termination->stage >= 4) {
            $e = $termination->employee;
            $termination->update(['status' => 'settled']);
            $e->update(['status' => 'terminated']);
            User::where('employee_id', $e->id)->update(['active' => false]);
            Access::flushCache();
            OdooSync::queue('settlement', $termination->id);
            $due = $termination->settlementDue();
            Audit::notify(Access::usersWithRole('fin'), 'تصفية معتمدة بانتظار الصرف', $e->name.' — '.number_format($termination->settlement['total'], 2).' ر.س'.($due ? ' · الموعد النظامي '.$due->format('d/m/Y').' (§112)' : ''), 'approval', ['screen' => 'terminations']);
        } else {
            $next = array_keys(Termination::STAGES)[$termination->stage];
            Audit::notify($this->actorIds($termination, $next), 'تصفية نهاية خدمة بانتظار اعتمادك', $termination->employee->name, 'approval', ['screen' => 'terminations']);
        }
        Audit::log('terminations', $termination->id, 'approve_'.$stageRole, null, ['stage' => $termination->stage], $d['note'] ?? null);

        return $this->show($request, $termination->fresh());
    }

    public function pay(Request $request, Termination $termination)
    {
        abort_unless($request->user()->hasRole('fin', 'super') && $termination->status === 'settled', 403);
        $d = $request->validate(['action' => 'required|in:sent,paid', 'ref' => 'nullable|string|max:80']);
        $termination->update($d['action'] === 'paid' ? ['payment' => 'paid', 'status' => 'paid', 'paid_at' => now()] : ['payment' => 'sent']);
        if ($d['action'] === 'sent') {
            OdooSync::queue('settlement', $termination->id);
        }
        Audit::log('terminations', $termination->id, 'payment_'.$d['action'], null, ['ref' => $d['ref'] ?? null]);

        return $this->show($request, $termination->fresh());
    }

    public function cancel(Request $request, Termination $termination)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr') && in_array($termination->status, ['open', 'pending_decision', 'resign_pending', 'resign_postponed'], true), 403);
        $d = $request->validate(['note' => 'required|string|max:300']);
        $termination->update(['status' => 'cancelled', 'note' => $d['note']]);
        $termination->employee->update(['status' => 'active', 'last_working_day' => null]);
        Audit::log('terminations', $termination->id, 'cancel', null, null, $d['note']);

        return $this->row($termination);
    }

    private function isStageActor(User $u, Termination $t, string $role): bool
    {
        if ($u->employee_id && $u->employee_id === $t->employee_id) {
            return false; // appendix 5: nobody approves a case concerning himself
        }
        $acc = Access::for($u);

        return match ($role) {
            'mgr' => $acc->isManagerOf($t->employee) || (! $t->employee->manager_id && $u->isHr() && $acc->can('TRM', 'approve', ['employee' => $t->employee])),
            'hr' => $u->isHr() && $acc->can('TRM', 'approve', ['employee' => $t->employee]),
            'ceo' => $u->hasRole('super') || $acc->isCeoOf($t->company_id),
            'gceo' => $u->hasRole('super'),
            'fin' => $u->hasRole('fin', 'super'),
            'custodian' => $u->hasRole('custodian', 'super'),
            default => false,
        };
    }

    /** §108 resignation timeline for the UI. */
    private function resignInfo(Termination $t, User $u): array
    {
        if ($t->reason !== 'resign' || ! $t->filed_on) {
            return [];
        }
        $pending = in_array($t->status, ['resign_pending', 'resign_postponed'], true);
        $acc = Access::for($u);

        return ['filed_on' => $t->filed_on->toDateString(), 'auto_accept_on' => ($t->status === 'resign_postponed' ? $t->postponed_until : $t->filed_on->copy()->addDays(30))?->toDateString(),
            'withdraw_until' => $t->filed_on->copy()->addDays(7)->toDateString(), 'postponed_until' => $t->postponed_until?->toDateString(), 'postpone_reason' => $t->postpone_reason,
            'accepted_at' => $t->accepted_at?->toDateTimeString(), 'accepted_how' => $t->accepted_how, 'withdrawn_at' => $t->withdrawn_at?->toDateTimeString(),
            'postpone_max' => now()->addDays(60)->toDateString(),
            'can_withdraw' => $pending && $t->filed_on->copy()->addDays(7)->endOfDay()->isFuture() && ($t->employee_id === $u->employee_id || ($u->isHr() && $acc->can('TRM', 'edit', ['employee' => $t->employee]))),
            'can_postpone' => $t->status === 'resign_pending' && $t->filed_on->copy()->addDays(30)->startOfDay()->gt(now()->startOfDay()) && $u->employee_id !== $t->employee_id
                && ($u->hasRole('super') || $acc->isCeoOf($t->company_id) || ($u->isHr() && $acc->can('TRM', 'approve', ['employee' => $t->employee])))];
    }

    private function row(Termination $t): array
    {
        $tasks = collect($t->clearance);

        return $t->only('id', 'reason', 'initiated_by', 'requested_at', 'filed_on', 'notice_days', 'last_working_day', 'note', 'stage', 'status', 'payment', 'paid_at', 'eosb_adjusted', 'eosb_adjust_reason', 'created_at') + [
            'reason_name' => Lookup::name('termination_reasons', $t->reason), 'status_label' => Termination::STATUSES[$t->status] ?? $t->status,
            'employee' => $t->relationLoaded('employee') ? $t->employee?->only('id', 'name', 'emp_no') : null, 'company' => $t->relationLoaded('company') ? $t->company?->name : null,
            'eosb_amount' => $t->eosb_adjusted ?? ($t->eosb['amount'] ?? 0), 'total' => $t->settlement['total'] ?? 0,
            'clearance_done' => $tasks->where('done', true)->count(), 'clearance_total' => $tasks->count(),
            'clearance_signed' => ($t->flow('clearance')['done'] ?? false), 'settlement_due' => $t->settlementDue()?->toDateString(),
        ];
    }
}
