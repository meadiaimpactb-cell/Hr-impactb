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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Payroll runs: generate (draft) → hr_review → hr_approved (HR manager) → fin_approved (finance manager) → approved (group CEO).
 * One person may not perform two stages of a run (§40/§46). The final approval needs every employee's verified IBAN (§38).
 * Approving locks the run, records the advance instalments actually deducted (deferring any reduced part, §42)
 * and publishes the payslips; the direct manager is informed only (appendix 5).
 */
class PayrollController extends Controller
{
    private const RUN_STATUSES = ['draft' => 'مسودة آلية', 'hr_review' => 'مراجعة أخصائي الموارد', 'hr_approved' => 'اعتماد مدير الموارد', 'fin_approved' => 'اعتماد المدير المالي', 'approved' => 'معتمد من الرئيس التنفيذي للمجموعة'];

    private const RUN_ORDER = ['draft', 'hr_review', 'hr_approved', 'fin_approved', 'approved'];

    // ---------------- runs ----------------

    public function companies(Request $request)
    {
        $u = $request->user();
        $ids = Access::for($u)->companyIds('PAY');
        if (! ($u->isHr() || $u->hasRole('fin'))) {
            $ids = array_values(array_filter($ids, fn ($id) => Access::for($u)->isCeoOf($id)));
        }

        if ($f = Access::for($u)->focus()) {
            $ids = array_values(array_intersect($ids, [$f]));
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
            'sod_note' => $this->sodNote($run, $request->user()),
            'bank_issues' => $this->bankIssues($run),
            'stage_log' => collect($run->stage_log ?? [])->map(fn ($s) => $s + ['stage_label' => self::RUN_STATUSES[$s['stage']] ?? 'إعادة التجميع', 'by_name' => \App\Models\User::whereKey($s['by'])->value('name')])->values(),
            'can_bank_file' => $request->user()->hasRole('fin', 'super', 'hr_mgr') || $request->user()->isHr(),
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
        // the review starts again: earlier stage holders are cleared (kept in the stage log)
        $run->logStage('regenerate', $request->user()->id);
        $run->update(['lines' => $emps->map(fn ($e) => PayrollService::computeLine($e, $run->period))->all(), 'version' => $run->version + 1, 'status' => 'draft',
            'hr_review_by' => null, 'hr_approved_by' => null, 'fin_approved_by' => null, 'stage_log' => $run->stage_log]);
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

    /**
     * Progress the run one stage. On reaching "approved": lock, snapshot advance instalments, publish payslips.
     * §40/§46: one person may not perform two stages of the same run (unless nobody else holds the role — logged).
     * §38/§46: the final approval is refused while any employee in the run has no verified IBAN.
     */
    public function stage(Request $request, PayrollRun $run)
    {
        $d = $request->validate(['to' => 'required|in:hr_review,hr_approved,fin_approved,approved']);
        $u = $request->user();
        $order = self::RUN_ORDER;
        abort_unless(array_search($d['to'], $order) === array_search($run->status, $order) + 1, 422, 'ترتيب المراحل غير صحيح');
        abort_unless($this->canStage($d['to'], $run, $u), 403, 'ليست لديك صلاحية هذه المرحلة');
        $sod = $this->sodState($d['to'], $run, $u);
        abort_if($sod['blocked'], 422, $sod['message']);
        if ($d['to'] === 'approved') {
            $issues = array_values(array_filter($this->bankIssues($run), fn ($i) => $i['blocking']));
            if ($issues) {
                return response()->json(['message' => 'لا يمكن الاعتماد النهائي: '.count($issues).' موظف بلا حساب بنكي (آيبان) موثّق باسمه — الصرف على حساب الموظف فقط (§38). '
                    .implode('، ', array_map(fn ($i) => $i['name'].' ('.$i['issue'].')', array_slice($issues, 0, 8))).(count($issues) > 8 ? '…' : ''),
                    'bank_issues' => $issues], 422);
            }
        }

        return DB::transaction(function () use ($run, $d, $u, $sod) {
            $before = $run->status;
            $run->status = $d['to'];
            $run->{$d['to'].'_by'} = $u->id;
            $run->logStage($d['to'], $u->id, $sod['warning']);
            if ($d['to'] === 'approved') {
                $run->locked_at = now();
                $this->finalizeRun($run);
            }
            $run->save();
            Audit::log('payroll_runs', $run->id, $d['to'], ['status' => $before], ['status' => $run->status], $sod['warning']);
            if ($d['to'] !== 'approved') { // tell whoever decides the next stage
                $next = self::RUN_ORDER[array_search($d['to'], self::RUN_ORDER) + 1];
                $notify = match ($next) { 'hr_approved' => Access::usersWithRole('hr_mgr'), 'fin_approved' => Access::usersWithRole('fin'), default => Access::usersWithRole('super') };
                Audit::notify($notify ?: Access::usersWithRole('super'), 'مسير راتب بانتظار '.self::RUN_STATUSES[$next], Company::find($run->company_id)?->name.' — '.$run->period, 'approval', ['screen' => 'payroll']);
            }

            return $run;
        });
    }

    /**
     * Separation of duties for a stage: blocked when this user already did an earlier human stage of the run and
     * someone else could do this one; allowed with a logged warning when nobody else holds the role.
     */
    private function sodState(string $to, PayrollRun $run, $u): array
    {
        $toIdx = array_search($to, self::RUN_ORDER);
        $prior = array_filter($run->stageActors(), fn ($id, $s) => array_search($s, self::RUN_ORDER) < $toIdx, ARRAY_FILTER_USE_BOTH);
        $mine = array_keys(array_filter($prior, fn ($id) => $id === (int) $u->id));
        if (! $mine) {
            return ['blocked' => false, 'warning' => null, 'message' => null];
        }
        $done = implode('، ', array_map(fn ($s) => self::RUN_STATUSES[$s], $mine));
        $others = Access::activeUsers()->filter(fn ($x) => ! in_array((int) $x->id, $prior, true) && $this->canStage($to, $run, $x));
        if ($others->isNotEmpty()) {
            return ['blocked' => true, 'warning' => null,
                'message' => "الفصل بين المهام (§40/§46): سبق أن نفّذتَ مرحلة «{$done}» في هذا المسير، ولا يجوز أن يجمع شخص واحد مرحلتين. تُنفَّذ مرحلة «".self::RUN_STATUSES[$to].'» من: '.$others->pluck('name')->take(4)->implode('، ')];
        }
        $warning = "تنبيه فصل المهام: نفّذ المستخدم نفسه مرحلة «{$done}» ثم «".self::RUN_STATUSES[$to].'» لعدم وجود صاحب صلاحية آخر';
        Log::warning('payroll SoD fallback', ['run' => $run->id, 'user' => $u->id, 'stage' => $to, 'prior' => $mine]);

        return ['blocked' => false, 'warning' => $warning, 'message' => null];
    }

    /** §38: every line with a net to pay needs the employee's own IBAN, verified (iban_verified_at) when that column exists. */
    private function bankIssues(PayrollRun $run): array
    {
        $lines = collect($run->lines);
        $hasVerified = Schema::hasColumn('employees', 'iban_verified_at');
        $emps = Employee::withTrashed()->whereIn('id', $lines->pluck('employee_id'))->get()->keyBy('id');
        $ibanCount = $emps->pluck('bank_iban')->filter()->map(fn ($i) => strtoupper(preg_replace('/\s+/', '', $i)))->countBy();
        $out = [];
        foreach ($lines as $l) {
            if ($this->lineNet($l) <= 0) {
                continue;
            }
            $e = $emps[$l['employee_id']] ?? null;
            $iban = strtoupper(preg_replace('/\s+/', '', (string) $e?->bank_iban));
            $issue = null;
            $blocking = true;
            if ($iban === '') {
                $issue = 'لا يوجد آيبان مسجل';
            } elseif (! preg_match('/^SA\d{22}$/', $iban)) {
                $issue = 'صيغة الآيبان غير صحيحة';
            } elseif ($hasVerified && ! $e->iban_verified_at) {
                $issue = 'الآيبان غير موثّق (يلزم تحقق الموارد البشرية واعتماد المالية)';
            } elseif (($ibanCount[$iban] ?? 0) > 1) {
                $issue = 'الآيبان نفسه مسجل لموظف آخر في المسير — تحقق أنه باسم الموظف';
                $blocking = false;
            }
            if ($issue) {
                $out[] = ['employee_id' => $l['employee_id'], 'name' => $l['employee_name'], 'emp_no' => $l['emp_no'], 'iban' => $iban ?: null, 'issue' => $issue, 'blocking' => $blocking];
            }
        }

        return $out;
    }

    /** Bank transfer file of a run (§38): name, employee no., IBAN, net. JSON for the Excel export, or ?format=csv. */
    public function bankTransfer(Request $request, PayrollRun $run)
    {
        $u = $request->user();
        abort_unless(Access::for($u)->can('PAY', 'view', ['company_id' => $run->company_id]) && ($u->hasRole('fin', 'super', 'hr_mgr') || $u->isHr()), 403);
        $emps = Employee::withTrashed()->whereIn('id', collect($run->lines)->pluck('employee_id'))->get()->keyBy('id');
        $issues = collect($this->bankIssues($run))->keyBy('employee_id');
        $rows = collect($run->lines)->map(fn ($l) => ['name' => $l['employee_name'], 'emp_no' => $l['emp_no'],
            'iban' => strtoupper(preg_replace('/\s+/', '', (string) ($emps[$l['employee_id']]->bank_iban ?? ''))), 'net' => $this->lineNet($l),
            'issue' => $issues[$l['employee_id']]['issue'] ?? null])->filter(fn ($r) => $r['net'] > 0)->values();
        $company = Company::find($run->company_id);
        Audit::log('payroll_runs', $run->id, 'bankFile', null, ['rows' => $rows->count(), 'total' => round($rows->sum('net'), 2)]);
        if ($request->input('format') === 'csv') {
            $csv = "\xEF\xBB\xBF".implode(',', ['اسم الموظف', 'الرقم الوظيفي', 'الآيبان', 'صافي الراتب', 'ملاحظة'])."\r\n";
            foreach ($rows as $r) {
                $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', [$r['name'], $r['emp_no'], $r['iban'], number_format($r['net'], 2, '.', ''), $r['issue'] ?? '']))."\r\n";
            }

            return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="bank-transfer-'.$run->period.'-'.($company?->code ?? $run->company_id).'.csv"']);
        }

        return ['company' => $company?->only('id', 'code', 'name'), 'period' => $run->period, 'status' => $run->status, 'status_label' => self::RUN_STATUSES[$run->status],
            'final' => $run->status === 'approved', 'rows' => $rows, 'total' => round($rows->sum('net'), 2), 'issues' => $issues->values()];
    }

    private function finalizeRun(PayrollRun $run): void
    {
        $company = Company::find($run->company_id);
        $managers = [];
        foreach ($run->lines as $l) {
            $slip = Payslip::firstOrNew(['employee_id' => $l['employee_id'], 'period' => $run->period]);
            $slip->company_id = $run->company_id;
            $slip->payroll_run_id = $run->id;
            $slip->no = $slip->no ?: 'PS-'.$run->period.'-'.$l['emp_no'];
            $slip->line = $l;
            $slip->adj_late = $l['adj_late'] ?? null;
            $slip->adj_reason = $l['adj_reason'] ?? null;
            $slip->version = ($slip->exists ? $slip->version + 1 : 1);
            // §40 + appendix 5: the payslip is reviewed within the run's chain (HR review → HR manager → finance →
            // group CEO); once the run is approved the employee sees it. The direct manager is informed, not an approver.
            $slip->mgr_approved = false;
            $slip->hr_approved = true;
            $slip->hr_approved_by = $run->hr_approved_by ?: $run->hr_review_by;
            $slip->published = true;
            $slip->published_at = now();
            $slip->save();
            Audit::notify(\App\Models\User::where('employee_id', $l['employee_id'])->value('id'), "صدرت قسيمة راتب {$run->period}", 'يمكنك عرضها وتنزيلها من قسائمي', 'ok', ['screen' => 'payroll']);
            if ($mgr = Employee::whereKey($l['employee_id'])->value('manager_id')) {
                $managers[$mgr][] = $l['employee_name'];
            }
            $this->settleAdvances($run, $l);
            // §41–42: a fine cut by the half-wage rule is carried to the next month on the violation itself
            foreach ($l['advance_deferrals'] ?? [] as $df) {
                if (($df['kind'] ?? null) === 'penalty' && ! empty($df['ref']) && ($v = \App\Models\Violation::find($df['ref']))) {
                    $v->forceFill(['deferred_amount' => $df['deferred'], 'deferred_period' => \Illuminate\Support\Carbon::parse($run->period.'-01')->addMonthNoOverflow()->format('Y-m')])->save();
                }
            }
        }
        foreach ($managers as $mgrEmp => $names) { // for information only
            Audit::notify(\App\Models\User::where('employee_id', $mgrEmp)->value('id'), "اعتُمد مسير رواتب {$run->period} — للعلم",
                ($company?->name ? $company->name.' — ' : '').'صدرت قسائم '.count($names).' من فريقك: '.implode('، ', array_slice($names, 0, 6)).(count($names) > 6 ? '…' : ''), 'info', ['screen' => 'payroll']);
        }
    }

    /**
     * §42: record what was actually deducted for each instalment of the period; a part held back by the
     * half-wage rule is not forgiven — it becomes a new instalment in the month after the last one.
     */
    private function settleAdvances(PayrollRun $run, array $l): void
    {
        $deducted = collect($l['deductions'])->where('key', 'advance')->filter(fn ($x) => ! empty($x['ref']))->groupBy('ref')->map(fn ($g) => (float) $g->sum('amount'));
        $ids = $deducted->keys()->merge(collect($l['advance_deferrals'] ?? [])->filter(fn ($x) => ($x['kind'] ?? 'advance') === 'advance')->pluck('ref'))->unique()->filter();
        foreach ($ids as $id) {
            $adv = Advance::find($id);
            if (! $adv) {
                continue;
            }
            $actual = (float) ($deducted[$id] ?? 0);
            $deferred = 0.0;
            $sched = collect($adv->schedule)->map(function ($s) use ($run, &$actual, &$deferred) {
                if ($s['period'] === $run->period && ($s['status'] ?? '') !== 'deducted') {
                    $take = round(min($actual, (float) $s['amount']), 2);
                    $actual -= $take;
                    $s['status'] = 'deducted';
                    $s['run_id'] = $run->id;
                    $s['paid_amount'] = $take;
                    if ($take < (float) $s['amount']) {
                        $s['deferred'] = round((float) $s['amount'] - $take, 2);
                        $deferred += $s['deferred'];
                    }
                }

                return $s;
            })->all();
            $installments = $adv->installments;
            if ($deferred > 0.004) {
                $last = collect($sched)->pluck('period')->max();
                $sched[] = ['period' => Carbon::parse($last.'-01')->addMonthNoOverflow()->format('Y-m'), 'amount' => round($deferred, 2), 'status' => 'due', 'deferred_from' => $run->period];
                $installments++;
            }
            $remaining = round($adv->amount - $adv->recovered($sched), 2);
            $adv->update(['schedule' => $sched, 'installments' => $installments, 'paid' => collect($sched)->where('status', 'deducted')->filter(fn ($s) => (float) ($s['paid_amount'] ?? $s['amount']) > 0)->count(),
                'remaining' => max(0, $remaining), 'status' => $remaining <= 0 ? 'settled' : 'repaying']);
            if ($deferred > 0.004) {
                Audit::log('advances', $adv->id, 'deferInstalment', null, ['period' => $run->period, 'deferred' => round($deferred, 2)], '§42 نصف الأجر');
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

        return $next && $this->canStage($next, $run, $user) && ! $this->sodState($next, $run, $user)['blocked'] ? $next : null;
    }

    /** Why this user cannot act on the next stage although they hold its role (separation of duties), or the fallback warning. */
    private function sodNote(PayrollRun $run, $user): ?string
    {
        $i = array_search($run->status, self::RUN_ORDER);
        $next = $i === false ? null : (self::RUN_ORDER[$i + 1] ?? null);
        if (! $next || ! $this->canStage($next, $run, $user)) {
            return null;
        }
        $s = $this->sodState($next, $run, $user);

        return $s['message'] ?? $s['warning'];
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
        // Self: only once published. Otherwise the usual salary-view permission, or the direct manager (informed, read-only).
        $acc = Access::for($u);
        abort_unless($isSelf ? $payslip->published : ($acc->canSeeSalary($payslip->employee) || $acc->isManagerOf($payslip->employee)), 403);

        return [
            'payslip' => $payslip, 'employee' => $payslip->employee, 'net' => $payslip->net(), 'ded_total' => $payslip->deductionsTotal(),
            'balances' => \App\Services\LeaveService::balance($payslip->employee, 'annual'),
            // appendix 5: the direct manager is informed only — no manager approval gate
            'can_mgr_approve' => false,
            'can_hr_approve' => ! $isSelf && ! $payslip->published && ($u->hasRole('super', 'hr_mgr') || ($u->hasRole('hr_staff') && $acc->can('PAY', 'approve', ['company_id' => $payslip->company_id]))),
        ];
    }

    /** Publishes a payslip that was not published with its run (legacy drafts). Manager approval is no longer a gate. */
    public function approve(Request $request, Payslip $payslip)
    {
        $d = $request->validate(['role' => 'required|in:mgr,hr']);
        abort_if($d['role'] === 'mgr', 422, 'المدير المباشر يُبلَّغ بالقسيمة للعلم ولا يعتمدها (الملحق الخامس)');
        $u = $request->user();
        $payslip->load('employee');
        abort_unless($u->hasRole('super', 'hr_mgr') || ($u->hasRole('hr_staff') && Access::for($u)->can('PAY', 'approve', ['company_id' => $payslip->company_id])), 403);
        abort_if($payslip->published, 422, 'القسيمة منشورة مسبقًا');
        $payslip->hr_approved = true;
        $payslip->hr_approved_by = $u->id;
        $payslip->published = true;
        $payslip->published_at = now();
        $userId = \App\Models\User::where('employee_id', $payslip->employee_id)->value('id');
        Audit::notify($userId, "نُشرت قسيمة راتب {$payslip->period}", 'يمكنك عرضها وتنزيلها من قسائمي', 'ok', ['screen' => 'payroll']);
        $payslip->save();
        Audit::log('payslips', $payslip->id, 'approve_hr', null, ['published' => true]);

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
        $ids = Access::for($request->user())->employees('PAY')->pluck('id');

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
