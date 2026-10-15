<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Access;
use App\Services\Audit;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll runs: generate (draft) → hr_review → hr_approved (HR manager) → fin_approved (finance manager) → approved (group CEO).
 * Approving locks the run, snapshots advance instalments as deducted, and drafts payslips.
 * Payslips then need the direct manager + HR to both approve before they publish to the employee.
 */
class PayrollController extends Controller
{
    private const RUN_STATUSES = ['draft' => 'مسودة آلية', 'hr_review' => 'مراجعة أخصائي الموارد', 'hr_approved' => 'اعتماد مدير الموارد', 'fin_approved' => 'اعتماد المدير المالي', 'approved' => 'معتمد من المدير العام'];

    private const RUN_ORDER = ['draft', 'hr_review', 'hr_approved', 'fin_approved', 'approved'];

    // ---------------- runs ----------------

    public function companies(Request $request)
    {
        $u = $request->user();
        $ids = Access::for($u)->companyIds();
        if (! ($u->isHr() || $u->hasRole('fin'))) {
            $ids = array_values(array_filter($ids, fn ($id) => Access::for($u)->isCeoOf($id)));
        }

        return Company::whereIn('id', $ids)->orderBy('id')->get(['id', 'code', 'name']);
    }

    public function runs(Request $request)
    {
        $period = $request->input('period', now()->format('Y-m'));
        $companies = $this->companies($request);
        $runs = PayrollRun::whereIn('company_id', $companies->pluck('id'))->where('period', $period)->get()->keyBy('company_id');

        return $companies->map(function ($c) use ($period, $runs, $request) {
            $run = $runs[$c->id] ?? null;
            $emps = Employee::where('company_id', $c->id)->where('status', '!=', 'terminated')->whereDate('hire_date', '<=', $this->periodEnd($period))->get();
            $lines = $run ? $run->lines : $emps->map(fn ($e) => PayrollService::computeLine($e, $period))->all();

            return [
                'company' => $c->only('id', 'code', 'name'), 'period' => $period, 'run_id' => $run?->id,
                'status' => $run?->status, 'status_label' => self::RUN_STATUSES[$run?->status ?? ''] ?? 'لم يُجمَّع بعد',
                'version' => $run?->version, 'updated_at' => $run?->updated_at, 'locked' => (bool) $run?->locked_at,
                'employees_count' => $emps->count(), 'gross' => round(array_sum(array_column($lines, 'gross')), 2),
                'net' => round(array_sum(array_map(fn ($l) => $this->lineNet($l), $lines)), 2),
                'can_generate' => ! $run && Access::for($request->user())->can('PAY', 'edit', ['company_id' => $c->id]),
                'can_regenerate' => $run && in_array($run->status, ['draft', 'hr_review', 'hr_approved'], true) && Access::for($request->user())->can('PAY', 'edit', ['company_id' => $c->id]),
            ];
        })->values();
    }

    public function showRun(Request $request, PayrollRun $run)
    {
        abort_unless(Access::for($request->user())->can('PAY', 'view', ['company_id' => $run->company_id]), 403);
        $lines = collect($run->lines)->map(function ($l) use ($run) {
            $l['net'] = $this->lineNet($l);
            $l['ded_total'] = round($l['gross'] - $l['net'], 2);

            return $l;
        })->values();

        return [
            'run' => $run->only('id', 'company_id', 'period', 'status', 'version', 'locked_at', 'created_at', 'updated_at'),
            'status_label' => self::RUN_STATUSES[$run->status], 'lines' => $lines,
            'can_edit' => in_array($run->status, ['draft', 'hr_review'], true) && Access::for($request->user())->can('PAY', 'edit', ['company_id' => $run->company_id]),
            'next_action' => $this->nextAction($run, $request->user()),
            'next_stage_label' => $this->nextStageLabel($run),
            'can_regenerate' => in_array($run->status, ['draft', 'hr_review', 'hr_approved'], true) && Access::for($request->user())->can('PAY', 'edit', ['company_id' => $run->company_id]),
        ];
    }

    public function generate(Request $request)
    {
        $d = $request->validate(['company_id' => 'required|exists:companies,id', 'period' => 'required|date_format:Y-m']);
        abort_unless(Access::for($request->user())->can('PAY', 'edit', ['company_id' => $d['company_id']]), 403);
        if (PayrollRun::where('company_id', $d['company_id'])->where('period', $d['period'])->exists()) {
            throw ValidationException::withMessages(['period' => 'يوجد مسير مجمَّع لهذه الفترة بالفعل']);
        }
        $run = $this->buildRun($d['company_id'], $d['period'], $request->user());
        Audit::log('payroll_runs', $run->id, 'generate', null, ['gross' => $run->gross]);

        return response()->json($this->showRun($request, $run), 201);
    }

    public function regenerate(Request $request, PayrollRun $run)
    {
        // until the finance approval a run can be recompiled; it then goes back to the start of the review
        abort_unless(in_array($run->status, ['draft', 'hr_review', 'hr_approved'], true), 422, 'لا يمكن إعادة التجميع بعد اعتماد المدير المالي');
        abort_unless(Access::for($request->user())->can('PAY', 'edit', ['company_id' => $run->company_id]), 403);
        $emps = Employee::where('company_id', $run->company_id)->where('status', '!=', 'terminated')->whereDate('hire_date', '<=', $this->periodEnd($run->period))->get();
        $run->update(['lines' => $emps->map(fn ($e) => PayrollService::computeLine($e, $run->period))->all(), 'version' => $run->version + 1, 'status' => 'draft']);
        $run->update(['gross' => round(array_sum(array_column($run->lines, 'gross')), 2), 'net' => round(array_sum(array_map(fn ($l) => $this->lineNet($l), $run->lines)), 2)]);
        Audit::log('payroll_runs', $run->id, 'regenerate', null, ['version' => $run->version]);

        return $this->showRun($request, $run);
    }

    /** Manual override of the automatic late/absence deduction for one employee's line, with a mandatory reason. */
    public function adjustLate(Request $request, PayrollRun $run)
    {
        abort_unless(in_array($run->status, ['draft', 'hr_review'], true), 422, 'لا يمكن التعديل بعد هذه المرحلة');
        abort_unless(Access::for($request->user())->can('PAY', 'edit', ['company_id' => $run->company_id]), 403);
        $d = $request->validate(['employee_id' => 'required|integer', 'adj' => 'required|numeric|min:0', 'reason' => 'required|string|max:500']);

        $lines = collect($run->lines);
        $idx = $lines->search(fn ($l) => $l['employee_id'] == $d['employee_id']);
        abort_if($idx === false, 404);
        $line = $lines[$idx];
        $auto = collect($line['deductions'])->firstWhere('key', 'late')['amount'] ?? 0;
        $line['adj_late'] = (float) $d['adj'];
        $line['adj_reason'] = $d['reason'];
        $lines[$idx] = $line;
        $run->update(['lines' => $lines->all(), 'net' => round(array_sum(array_map(fn ($l) => $this->lineNet($l), $lines->all())), 2)]);
        Audit::log('payroll_runs', $run->id, 'adjustLate', ['auto' => $auto, 'employee' => $d['employee_id']], ['adj' => $d['adj']], $d['reason']);

        return $this->showRun($request, $run);
    }

    /** Progress the run one stage. On reaching "approved": lock, snapshot advance instalments, draft payslips. */
    public function stage(Request $request, PayrollRun $run)
    {
        $d = $request->validate(['to' => 'required|in:hr_review,hr_approved,fin_approved,approved']);
        $u = $request->user();
        $order = self::RUN_ORDER;
        abort_unless(array_search($d['to'], $order) === array_search($run->status, $order) + 1, 422, 'ترتيب المراحل غير صحيح');
        abort_unless($this->canStage($d['to'], $run, $u), 403, 'ليست لديك صلاحية هذه المرحلة');

        return DB::transaction(function () use ($run, $d, $u) {
            $before = $run->status;
            $run->status = $d['to'];
            $run->{$d['to'].'_by'} = $u->id;
            if ($d['to'] === 'approved') {
                $run->locked_at = now();
                $this->finalizeRun($run);
            }
            $run->save();
            Audit::log('payroll_runs', $run->id, $d['to'], ['status' => $before], ['status' => $run->status]);
            if ($d['to'] !== 'approved') { // tell whoever decides the next stage
                $next = self::RUN_ORDER[array_search($d['to'], self::RUN_ORDER) + 1];
                $notify = match ($next) { 'hr_approved' => Access::usersWithRole('hr_mgr'), 'fin_approved' => Access::usersWithRole('fin'), default => Access::usersWithRole('super') };
                Audit::notify($notify ?: Access::usersWithRole('super'), 'مسير راتب بانتظار '.self::RUN_STATUSES[$next], Company::find($run->company_id)?->name.' — '.$run->period, 'approval', ['screen' => 'payroll']);
            }

            return $run;
        });
    }

    private function finalizeRun(PayrollRun $run): void
    {
        foreach ($run->lines as $l) {
            $slip = Payslip::firstOrNew(['employee_id' => $l['employee_id'], 'period' => $run->period]);
            $slip->company_id = $run->company_id;
            $slip->payroll_run_id = $run->id;
            $slip->no = $slip->no ?: 'PS-'.$run->period.'-'.$l['emp_no'];
            $slip->line = $l;
            $slip->adj_late = $l['adj_late'] ?? null;
            $slip->adj_reason = $l['adj_reason'] ?? null;
            $slip->version = ($slip->exists ? $slip->version + 1 : 1);
            $slip->mgr_approved = false;
            $slip->hr_approved = false;
            $slip->save();

            foreach ($l['deductions'] as $ded) {
                if ($ded['key'] === 'advance' && ! empty($ded['ref'])) {
                    $adv = Advance::find($ded['ref']);
                    if (! $adv) {
                        continue;
                    }
                    $sched = collect($adv->schedule)->map(function ($s) use ($run) {
                        if ($s['period'] === $run->period && $s['status'] !== 'deducted') {
                            $s['status'] = 'deducted';
                            $s['run_id'] = $run->id;
                        }

                        return $s;
                    })->all();
                    $paid = collect($sched)->where('status', 'deducted')->count();
                    $remaining = round($adv->amount - collect($sched)->where('status', 'deducted')->sum('amount'), 2);
                    $adv->update(['schedule' => $sched, 'paid' => $paid, 'remaining' => max(0, $remaining), 'status' => $remaining <= 0 ? 'settled' : 'repaying']);
                }
            }
        }
    }

    /** Net for one snapshot line, honouring a manual late-deduction override if present. */
    private function lineNet(array $l): float
    {
        $ded = 0.0;
        foreach ($l['deductions'] as $d) {
            $ded += ($d['key'] === 'late' && isset($l['adj_late'])) ? $l['adj_late'] : $d['amount'];
        }

        return round($l['gross'] - $ded, 2);
    }

    private function buildRun(int $companyId, string $period, $user): PayrollRun
    {
        $emps = Employee::where('company_id', $companyId)->where('status', '!=', 'terminated')->whereDate('hire_date', '<=', $this->periodEnd($period))->get();
        $lines = $emps->map(fn ($e) => PayrollService::computeLine($e, $period))->all();

        return PayrollRun::create(['company_id' => $companyId, 'period' => $period, 'status' => 'draft', 'version' => 1,
            'gross' => round(array_sum(array_column($lines, 'gross')), 2), 'net' => round(array_sum(array_map(fn ($l) => $this->lineNet($l), $lines)), 2),
            'lines' => $lines, 'generated_by' => $user->id]);
    }

    private function periodEnd(string $period): string
    {
        return Carbon::parse($period.'-01')->endOfMonth()->toDateString();
    }

    /** Who may move a run into a stage: HR review, HR manager, finance manager, then the general manager. */
    private function canStage(string $to, PayrollRun $run, $u): bool
    {
        return match ($to) {
            'hr_review' => Access::for($u)->can('PAY', 'edit', ['company_id' => $run->company_id]),
            'hr_approved' => $u->hasRole('hr_mgr') || ($u->hasRole('super') && ! Access::usersWithRole('hr_mgr')),
            'fin_approved' => $u->hasRole('fin') || ($u->hasRole('super') && ! Access::usersWithRole('fin')),
            'approved' => $u->hasRole('super'),
            default => false,
        };
    }

    /** The next stage, only when this user decides it (otherwise the screen says who it waits for). */
    private function nextAction(PayrollRun $run, $user): ?string
    {
        $i = array_search($run->status, self::RUN_ORDER);
        $next = $i === false ? null : (self::RUN_ORDER[$i + 1] ?? null);

        return $next && $this->canStage($next, $run, $user) ? $next : null;
    }

    private function nextStageLabel(PayrollRun $run): ?string
    {
        $i = array_search($run->status, self::RUN_ORDER);
        $next = $i === false ? null : (self::RUN_ORDER[$i + 1] ?? null);

        return $next ? self::RUN_STATUSES[$next] : null;
    }

    // ---------------- payslips ----------------

    public function payslips(Request $request)
    {
        $period = $request->input('period', now()->format('Y-m'));
        $acc = Access::for($request->user());
        $ids = array_values(array_filter($acc->companyIds(), fn ($c) => $acc->can('PAY', 'view', ['company_id' => $c])));

        return Payslip::with('employee:id,name,emp_no')->whereIn('company_id', $ids)->where('period', $period)->get()
            ->map(fn ($s) => $this->slipRow($s));
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return Payslip::where('employee_id', $e->id)->where('published', true)->orderByDesc('period')->get()->map(fn ($s) => $this->slipRow($s));
    }

    public function show(Request $request, Payslip $payslip)
    {
        $payslip->load('employee.company:id,name,name_en,tier_id', 'employee.jobTitle:id,name');
        $u = $request->user();
        $isSelf = $u->employee_id === $payslip->employee_id;
        // Self: only once published. Otherwise: the usual salary-view permission, OR the direct
        // manager reviewing this specific payslip as part of approving it (not a general salary grant).
        $acc = Access::for($u);
        abort_unless($isSelf ? $payslip->published : ($acc->canSeeSalary($payslip->employee) || $acc->isManagerOf($payslip->employee)), 403);

        return [
            'payslip' => $payslip, 'employee' => $payslip->employee, 'net' => $payslip->net(), 'ded_total' => $payslip->deductionsTotal(),
            'balances' => \App\Services\LeaveService::balance($payslip->employee, 'annual'),
            'can_mgr_approve' => ! $isSelf && ! $payslip->mgr_approved && ($acc->isManagerOf($payslip->employee) || $u->hasRole('super')),
            'can_hr_approve' => ! $isSelf && ! $payslip->hr_approved && ($u->hasRole('super', 'hr_mgr') || ($u->hasRole('hr_staff') && $acc->can('PAY', 'approve', ['company_id' => $payslip->company_id]))),
        ];
    }

    public function approve(Request $request, Payslip $payslip)
    {
        $d = $request->validate(['role' => 'required|in:mgr,hr']);
        $u = $request->user();
        $payslip->load('employee');
        if ($d['role'] === 'mgr') {
            abort_unless(Access::for($u)->isManagerOf($payslip->employee) || $u->hasRole('super'), 403);
            abort_if($payslip->mgr_approved, 422, 'اعتمدها المدير مسبقًا');
            $payslip->mgr_approved = true;
            $payslip->mgr_approved_by = $u->id;
        } else {
            abort_unless($u->hasRole('super', 'hr_mgr') || ($u->hasRole('hr_staff') && Access::for($u)->can('PAY', 'approve', ['company_id' => $payslip->company_id])), 403);
            abort_if($payslip->hr_approved, 422, 'اعتمدتها الموارد البشرية مسبقًا');
            $payslip->hr_approved = true;
            $payslip->hr_approved_by = $u->id;
        }
        if ($payslip->mgr_approved && $payslip->hr_approved && ! $payslip->published) {
            $payslip->published = true;
            $payslip->published_at = now();
            $userId = \App\Models\User::where('employee_id', $payslip->employee_id)->value('id');
            Audit::notify($userId, "نُشرت قسيمة راتب {$payslip->period}", 'يمكنك عرضها وتنزيلها من قسائمي', 'ok', ['screen' => 'payroll']);
        }
        $payslip->save();
        Audit::log('payslips', $payslip->id, "approve_{$d['role']}", null, ['published' => $payslip->published]);

        return $this->show($request, $payslip);
    }

    public function annualSummary(Request $request, Employee $employee)
    {
        $u = $request->user();
        abort_unless($u->employee_id === $employee->id || Access::for($u)->canSeeSalary($employee), 403);
        $year = $request->input('year', now()->year);
        $slips = Payslip::where('employee_id', $employee->id)->where('published', true)->where('period', 'like', "$year-%")->orderBy('period')->get();

        return $slips->map(fn ($s) => ['period' => $s->period, 'gross' => $s->line['gross'], 'ded' => $s->deductionsTotal(), 'net' => $s->net()]);
    }

    private function slipRow(Payslip $s): array
    {
        return ['id' => $s->id, 'no' => $s->no, 'period' => $s->period, 'employee' => $s->employee?->only('id', 'name', 'emp_no'),
            'gross' => $s->line['gross'], 'ded_total' => $s->deductionsTotal(), 'net' => $s->net(), 'version' => $s->version,
            'mgr_approved' => $s->mgr_approved, 'hr_approved' => $s->hr_approved, 'published' => $s->published];
    }

    // ---------------- advances ----------------

    public function advances(Request $request)
    {
        $ids = Access::for($request->user())->employees()->pluck('id');

        return Advance::with('employee:id,name,emp_no')->whereIn('employee_id', $ids)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->latest()->get();
    }

    public function myAdvances(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return Advance::where('employee_id', $e->id)->latest()->get();
    }

    public function advanceShow(Request $request, Advance $advance)
    {
        $advance->load('employee:id,name,emp_no,company_id');
        $u = $request->user();
        abort_unless($u->employee_id === $advance->employee_id || Access::for($u)->canSeeEmployee($advance->employee), 403);

        return $advance;
    }
}
