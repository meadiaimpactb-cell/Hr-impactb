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
use Illuminate\Validation\Rule;

class TerminationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('TRM', 'view'), 403);
        $ids = Access::for()->employees()->pluck('id');

        return Termination::with('employee:id,name,emp_no', 'company:id,name')->whereIn('employee_id', $ids)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->get()->map(fn ($t) => $this->row($t));
    }

    public function show(Request $request, Termination $termination)
    {
        $u = $request->user();
        abort_unless($termination->employee_id === $u->employee_id || Access::for()->canSeeEmployee($termination->employee), 403);
        $t = $termination->load('employee:id,name,emp_no,hire_date,manager_id,company_id', 'company:id,name');
        $stageRole = array_keys(Termination::STAGES)[$t->stage] ?? null;

        $last = $t->last_working_day ?? $t->employee?->last_working_day;
        $byEmployee = in_array($t->reason, ['resign', 'resignation'], true);

        return $this->row($t) + ['clearance' => $t->clearance, 'eosb' => $t->eosb, 'settlement' => $t->settlement, 'approvals' => $t->approvals ?? [],
            'settlement_due' => $last ? \Illuminate\Support\Carbon::parse($last)->addDays($byEmployee ? 14 : 7)->toDateString() : null, 'settlement_due_rule' => $byEmployee ? 'أسبوعان من انتهاء العقد (الموظف أنهاه) — §112' : 'أسبوع من انتهاء العقد — §112',
            'next_stage' => $stageRole ? Termination::STAGES[$stageRole] : null,
            'can_approve' => $t->status === 'open' && $stageRole && $this->isStageActor($u, $t, $stageRole),
            'can_manage' => $u->isHr() && Access::for()->can('TRM', 'edit', ['employee' => $t->employee]), 'can_pay' => $u->hasRole('fin', 'super') && $t->status === 'settled',
            'reasons' => Lookup::grouped()['termination_reasons'] ?? []];
    }

    /** EOSB simulator for any employee in scope. */
    public function simulate(Request $request, Employee $employee)
    {
        abort_unless(Access::for()->canSeeSalary($employee) || $employee->id === $request->user()->employee_id, 403);
        $d = $request->validate(['end' => 'nullable|date', 'reason' => 'nullable|string']);

        return TerminationService::eosb($employee, $d['end'] ?? now()->toDateString(), $d['reason'] ?? 'resign');
    }

    /** HR opens a case directly (any reason except resignation, which comes through the request). */
    public function store(Request $request)
    {
        abort_unless($request->user()->isHr() && Access::for()->canAny('TRM', 'add'), 403);
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'reason' => ['required', Rule::in(array_column(Lookup::grouped()['termination_reasons'] ?? [], 'code')), 'not_in:resign'],
            'last_working_day' => 'required|date', 'note' => 'required|string|max:500']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(Access::for()->can('TRM', 'add', ['employee' => $e]), 403);
        abort_if(Termination::where('employee_id', $e->id)->whereIn('status', ['open', 'settled'])->exists(), 422, 'توجد حالة إنهاء قائمة لهذا الموظف');

        return response()->json($this->row($this->createCase($e, $d['reason'], $d['last_working_day'], $d['note'], $request->user()->id)), 201);
    }

    /** Shared entry point: resignation approval, misconduct (violation degree 7), or HR. */
    public function createCase(Employee $e, string $reason, string $lastDay, ?string $note = null, ?int $by = null, ?int $requestId = null): Termination
    {
        $notice = (int) Settings::get($e->contract_type === 'full' ? 'noticeDaysMonthly' : 'noticeDaysOther', $e->company_id, 60);
        $t = Termination::create(['employee_id' => $e->id, 'company_id' => $e->company_id, 'reason' => $reason, 'requested_at' => now()->toDateString(), 'notice_days' => $notice,
            'last_working_day' => $lastDay, 'note' => $note, 'request_id' => $requestId, 'clearance' => TerminationService::clearanceTasks(),
            'eosb' => TerminationService::eosb($e, $lastDay, $reason), 'stage' => 0, 'approvals' => [], 'status' => 'open', 'created_by' => $by ?? auth()->id()]);
        $e->update(['status' => 'exiting', 'last_working_day' => $lastDay]); // before the settlement so the last-period proration uses it
        $t->update(['settlement' => TerminationService::settlement($t->load('employee'))]);
        $e->addCareer('إنهاء خدمة', Lookup::name('termination_reasons', $reason).' — آخر يوم عمل '.$lastDay);
        Audit::log('terminations', $t->id, 'create', null, $t->only('reason', 'last_working_day'));
        Audit::notify(User::where('employee_id', $e->id)->value('id'), 'فُتحت حالة إنهاء خدمتك', 'آخر يوم عمل '.$lastDay.' — تابع إخلاء الطرف والتصفية', 'info', ['screen' => 'terminations']);
        Audit::notify(Access::hrUsersFor($e->company_id, 'TRM'), 'حالة إنهاء خدمة جديدة', $e->name.' — '.Lookup::name('termination_reasons', $reason), 'info', ['screen' => 'terminations']);

        return $t->fresh()->load('employee:id,name,emp_no', 'company:id,name');
    }

    public function task(Request $request, Termination $termination)
    {
        $u = $request->user();
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

    /** Sequential approvals: manager → HR → CEO → finance. After the last, employee is terminated and the account deactivated. */
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
            Audit::notify(Access::usersWithRole('fin'), 'تصفية معتمدة بانتظار الصرف', $e->name.' — '.number_format($termination->settlement['total'], 2).' ر.س', 'approval', ['screen' => 'terminations']);
        } else {
            $next = array_keys(Termination::STAGES)[$termination->stage];
            $ids = match ($next) { 'hr' => Access::hrUsersFor($termination->company_id, 'TRM'), 'ceo' => ($c = $termination->company?->ceo_employee_id) ? [User::where('employee_id', $c)->value('id')] : Access::usersWithRole('super'), 'fin' => Access::usersWithRole('fin'), default => [] };
            Audit::notify($ids, 'تصفية نهاية خدمة بانتظار اعتمادك', $termination->employee->name, 'approval', ['screen' => 'terminations']);
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
        abort_unless($request->user()->hasRole('super', 'hr_mgr') && $termination->status === 'open', 403);
        $d = $request->validate(['note' => 'required|string|max:300']);
        $termination->update(['status' => 'cancelled', 'note' => $d['note']]);
        $termination->employee->update(['status' => 'active', 'last_working_day' => null]);
        Audit::log('terminations', $termination->id, 'cancel', null, null, $d['note']);

        return $this->row($termination);
    }

    private function isStageActor(User $u, Termination $t, string $role): bool
    {
        $acc = Access::for($u);

        return match ($role) {
            'mgr' => $acc->isManagerOf($t->employee) || (! $t->employee->manager_id && $u->isHr()),
            'hr' => $u->isHr() && $acc->can('TRM', 'approve', ['employee' => $t->employee]),
            'ceo' => $u->hasRole('super') || $acc->isCeoOf($t->company_id),
            'fin' => $u->hasRole('fin', 'super'),
            default => false,
        };
    }

    private function row(Termination $t): array
    {
        $tasks = collect($t->clearance);

        return $t->only('id', 'reason', 'requested_at', 'notice_days', 'last_working_day', 'note', 'stage', 'status', 'payment', 'paid_at', 'eosb_adjusted', 'eosb_adjust_reason', 'created_at') + [
            'reason_name' => Lookup::name('termination_reasons', $t->reason), 'status_label' => Termination::STATUSES[$t->status] ?? $t->status,
            'employee' => $t->relationLoaded('employee') ? $t->employee?->only('id', 'name', 'emp_no') : null, 'company' => $t->relationLoaded('company') ? $t->company?->name : null,
            'eosb_amount' => $t->eosb_adjusted ?? ($t->eosb['amount'] ?? 0), 'total' => $t->settlement['total'] ?? 0,
            'clearance_done' => $tasks->where('done', true)->count(), 'clearance_total' => $tasks->count(),
        ];
    }
}
