<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\Lookup;
use App\Models\PayrollRun;
use App\Models\Violation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll line computation: prorated earnings for the period, minus automatic
 * deductions (late/absence from attendance, unpaid leave, advance instalments).
 * Nothing here writes to the database — see PayrollController for the staged
 * generate → review → approve workflow that snapshots these lines.
 */
class PayrollService
{
    public static function daysInMonth(string $period): int
    {
        return Carbon::parse($period.'-01')->daysInMonth;
    }

    public static function monthCloseDate(string $period, ?int $companyId = null): string
    {
        // closeDays are WORKING days of the following month — the same rule as the monthly-report lock (ReportController::lockAt)
        $days = max(1, (int) Settings::get('closeDays', $companyId, 5));
        $before = Carbon::parse($period.'-01')->addMonthNoOverflow()->startOfMonth()->subDay();

        return WorkCalendar::addDays(null, $before, $days)->toDateString();
    }

    public static function monthLocked(string $period, ?int $companyId = null): bool
    {
        return now()->toDateString() > self::monthCloseDate($period, $companyId);
    }

    /** Days of active service within the period, and the pro-rata factor per the company's rule. */
    public static function prorate(Employee $e, string $period): array
    {
        $dim = self::daysInMonth($period);
        $first = $period.'-01';
        $last = $period.'-'.str_pad((string) $dim, 2, '0', STR_PAD_LEFT);
        $start = max($e->hire_date->toDateString(), $first);
        $end = ($e->last_working_day && $e->last_working_day->toDateString() < $last) ? $e->last_working_day->toDateString() : $last;
        if ($start > $last || $end < $first) {
            return ['days' => 0, 'factor' => 0, 'dim' => $dim, 'denom' => $dim, 'rule' => 'actual', 'partial' => true];
        }
        $days = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
        $rule = $e->company?->prorate_rule ?? 'actual';
        $denom = $rule === '30' ? 30 : $dim;

        return ['days' => $days, 'factor' => min(1, $days / $denom), 'dim' => $dim, 'denom' => $denom, 'rule' => $rule, 'partial' => $days < $dim];
    }

    public static function workDaysInMonth(Employee $e, string $period): int
    {
        $n = 0;
        $d = Carbon::parse($period.'-01');
        $end = $d->copy()->endOfMonth();
        for (; $d->lte($end); $d->addDay()) {
            if (AttendanceService::isWorkDay($e, $d)) {
                $n++;
            }
        }

        return max(1, $n);
    }

    public static function minuteValue(Employee $e, string $period): float
    {
        // §41: daily wage = monthly wage ÷ 30; minute = daily wage ÷ (daily work hours × 60)
        $gross = $e->monthlyGross($period);
        $hours = (float) Settings::get('dailyHours', $e->company_id, 8);

        return ($gross / 30) / (max(1, $hours) * 60);
    }

    /** Raw (uncapped) late/early and absence amounts of the month (§41: minute value and a full day per absence). */
    public static function attendanceRaw(Employee $e, string $period): array
    {
        $a = AttendanceService::summary($e, $period);
        $factor = (float) Settings::get('lateFactor', $e->company_id, 1);
        $mv = self::minuteValue($e, $period);

        return ['late_amt' => round(($a['late_minutes'] + $a['early_minutes']) * $mv * $factor, 2),
            'abs_amt' => round($e->monthlyGross($period) / 30 * $a['absent'], 2),
            'minutes' => $a['late_minutes'] + $a['early_minutes'], 'absent' => $a['absent']];
    }

    /** §41 ceiling: attendance deductions of a month may not exceed maxDeductionPct (25%) of the actual wage of that month. */
    public static function attendanceCap(Employee $e, string $period): float
    {
        $actual = $e->monthlyGross($period) * self::prorate($e, $period)['factor'];

        return round($actual * (float) Settings::get('maxDeductionPct', $e->company_id, 25) / 100, 2);
    }

    /**
     * Amount carried into $period from earlier months (§41: what exceeds the ceiling moves to the next month).
     * Deterministic: the previous month's snapshot (approved/generated run line) when there is one, otherwise
     * recomputed from attendance — only for months that have attendance records, at most $depth months back.
     */
    public static function attendanceCarryIn(Employee $e, string $period, int $depth = 6): float
    {
        if ($depth <= 0) {
            return 0.0;
        }
        $prev = Carbon::parse($period.'-01')->subMonthNoOverflow();
        if ($prev->copy()->endOfMonth()->lt($e->hire_date)) {
            return 0.0;
        }
        $p = $prev->format('Y-m');
        foreach (PayrollRun::where('period', $p)->get(['id', 'lines']) as $run) {
            $line = collect($run->lines)->firstWhere('employee_id', $e->id);
            if ($line && array_key_exists('att_overflow', $line)) {
                return (float) $line['att_overflow'];
            }
        }
        // a month with no attendance data at all was not tracked in the platform: nothing to carry from it
        if (! $e->attendance()->whereBetween('work_date', [$prev->toDateString(), $prev->copy()->endOfMonth()->toDateString()])->exists()) {
            return 0.0;
        }

        return self::lateDeduction($e, $p, $depth - 1)['overflow'];
    }

    /**
     * Late/absence deduction for the month with the §41 ceiling applied: the carried-in balance is taken first,
     * then absence, then lateness, up to the ceiling; the rest is carried to the next month.
     */
    public static function lateDeduction(Employee $e, string $period, int $depth = 6): array
    {
        $raw = self::attendanceRaw($e, $period);
        $carry = self::attendanceCarryIn($e, $period, $depth);
        $cap = self::attendanceCap($e, $period);
        $room = $cap;
        $take = function (float $amt) use (&$room) {
            $x = round(min($amt, max(0, $room)), 2);
            $room -= $x;

            return $x;
        };
        $carryAmt = $take($carry);
        $absAmt = $take($raw['abs_amt']);
        $lateAmt = $take($raw['late_amt']);
        $requested = round($carry + $raw['abs_amt'] + $raw['late_amt'], 2);
        $total = round($carryAmt + $absAmt + $lateAmt, 2);

        return ['late_amt' => $lateAmt, 'abs_amt' => $absAmt, 'carry_amt' => $carryAmt, 'carry_in' => round($carry, 2),
            'late_raw' => $raw['late_amt'], 'abs_raw' => $raw['abs_amt'], 'cap' => $cap,
            'total' => $total, 'capped' => $requested > $cap, 'overflow' => round(max(0, $requested - $total), 2),
            'minutes' => $raw['minutes'], 'absent' => $raw['absent']];
    }

    /** §26: approved overtime of the period — hours × (basic ÷ 30 ÷ daily hours) × overtimeRate (1.5); work on a rest day/holiday at the same rate. */
    public static function overtime(Employee $e, string $period): array
    {
        $reqs = ApprovalRequest::where('employee_id', $e->id)->where('type', 'overtime')->where('status', 'approved')
            ->where('data->date', 'like', $period.'%')->get(['id', 'data']);
        $hours = round($reqs->sum(fn ($r) => (float) ($r->data['hours'] ?? 0)), 2);
        $holiday = round($reqs->filter(fn ($r) => ! empty($r->data['holiday']))->sum(fn ($r) => (float) ($r->data['hours'] ?? 0)), 2);
        $daily = max(1, (float) Settings::get('dailyHours', $e->company_id, 8));
        $hourly = $e->componentAmount('basic', $period) / 30 / $daily;
        $rate = (float) Settings::get('overtimeRate', $e->company_id, 1.5);

        return ['hours' => $hours, 'holiday_hours' => $holiday, 'hourly' => round($hourly, 4), 'rate' => $rate,
            'amount' => round($hours * $hourly * $rate, 2), 'refs' => $reqs->pluck('id')->all()];
    }

    /** Approved expense claims (request type 'expense') reimbursed in the payroll of the approval month. */
    public static function expenseReimbursements(Employee $e, string $period)
    {
        return ApprovalRequest::where('employee_id', $e->id)->where('type', 'expense')->where('status', 'approved')
            ->where(fn ($q) => $q->where('decided_at', 'like', $period.'%')->orWhere(fn ($w) => $w->whereNull('decided_at')->where('updated_at', 'like', $period.'%')))
            ->get(['id', 'data'])->filter(fn ($r) => empty($r->data['paid_separately']) && (float) ($r->data['amount'] ?? 0) > 0)->values();
    }

    private static ?bool $hasSuspension = null;

    /** Unpaid disciplinary suspension days falling in the period (violations.suspension_days). */
    public static function suspensions(Employee $e, string $period)
    {
        self::$hasSuspension ??= Schema::hasColumn('violations', 'suspension_days');
        if (! self::$hasSuspension) {
            return collect();
        }

        return Violation::where('employee_id', $e->id)->where('status', 'active')->where('period', $period)->where('suspension_days', '>', 0)->get();
    }

    /** Unpaid-leave days approved within the period (already excluded from pay by the earning being prorated? No — deducted explicitly). */
    public static function unpaidDays(Employee $e, string $period): float
    {
        return (float) ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->where('status', 'approved')
            ->whereIn('data->type', ['unpaid', 'maternity_ext'])->where('data->from', 'like', $period.'%')->get(['data'])
            ->sum(fn ($r) => $r->data['days'] ?? 0);
    }

    /**
     * §59 sick leave pay: approved sick leaves starting in the period carry data.sick_pay = {full, three_quarters, unpaid}
     * (days). A three-quarter-pay day deducts 25% of the daily wage (monthly ÷ 30), an unpaid day 100%.
     */
    public static function sickDeduction(Employee $e, string $period): array
    {
        $tq = 0.0;
        $unpaid = 0.0;
        foreach (ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->where('status', 'approved')
            ->where('data->type', 'sick')->where('data->from', 'like', $period.'%')->get(['id', 'data']) as $r) {
            $sp = (array) ($r->data['sick_pay'] ?? []);
            $tq += (float) ($sp['three_quarters'] ?? 0);
            $unpaid += (float) ($sp['unpaid'] ?? 0);
        }
        $daily = $e->monthlyGross($period) / 30;

        return ['three_quarters' => $tq, 'unpaid' => $unpaid, 'amount' => round($daily * (0.25 * $tq + $unpaid), 2)];
    }

    public const GOSI_SCHEMES = ['old' => 'سعودي — النظام القديم', 'new' => 'سعودي — النظام الجديد', 'expat' => 'غير سعودي', 'exempt' => 'غير مشمول'];

    /**
     * Social insurance for an employee in a period: which scheme applies and the employee / employer rates.
     * Scheme: the employee's own setting, else automatic — non-Saudi → expat; Saudi → old system (most have an
     * earlier subscription; HR marks first-time subscribers as "new"); task-based collaborators are not covered.
     */
    public static function gosiFor(Employee $e, ?string $period = null): array
    {
        $cfg = (array) Settings::get('gosiRates', $e->company_id, []);
        $saudi = in_array(strtoupper((string) $e->nationality), ['SA', 'SAU'], true) || str_contains((string) $e->nationality, 'سعود');
        $auto = $saudi ? 'old' : 'expat';
        if ($e->contract_type === 'collab') {
            $auto = 'exempt';
        }
        $scheme = $e->gosi_scheme ?: $auto;
        $date = ($period ?: now()->format('Y-m')).'-01';
        $rates = match ($scheme) {
            'old' => $cfg['old'] ?? ['employee' => 9.75, 'employer' => 11.75],
            'expat' => $cfg['expat'] ?? ['employee' => 0, 'employer' => 2],
            'new' => collect($cfg['new'] ?? [])->sortBy('from')->filter(fn ($r) => ($r['from'] ?? '') <= $date)->last() ?? ['employee' => 9.75, 'employer' => 11.75],
            default => ['employee' => 0, 'employer' => 0],
        };

        return ['scheme' => $scheme, 'scheme_label' => self::GOSI_SCHEMES[$scheme] ?? $scheme, 'automatic' => ! $e->gosi_scheme, 'enabled' => (bool) ($cfg['enabled'] ?? false),
            'employee_rate' => (float) $rates['employee'], 'employer_rate' => (float) $rates['employer'], 'wage_cap' => (float) ($cfg['wage_cap'] ?? 45000)];
    }

    /** Full payslip line for one employee/period: earnings, deductions, net. Nothing persisted. */
    public static function computeLine(Employee $e, string $period): array
    {
        $pr = self::prorate($e, $period);
        $comps = $e->activeComponents($period);
        $earnings = $comps->map(fn ($c) => ['key' => $c->key, 'name' => $c->name, 'amount' => round((float) $c->amount * $pr['factor'], 2)])->values()->all();
        // §87: approved instant bonuses of the period
        foreach (\App\Models\Incentive::where('employee_id', $e->id)->where('kind', 'bonus')->where('period', $period)->where('amount', '>', 0)->get() as $b) {
            $earnings[] = ['key' => 'bonus', 'name' => 'مكافأة إنجاز فورية', 'amount' => round((float) $b->amount, 2), 'ref' => $b->id];
        }
        // §26: approved overtime of the period
        $ot = self::overtime($e, $period);
        if ($ot['amount'] > 0) {
            $earnings[] = ['key' => 'overtime', 'name' => "عمل إضافي ({$ot['hours']} ساعة × ".rtrim(rtrim(number_format($ot['rate'], 2), '0'), '.').')',
                'amount' => $ot['amount'], 'hours' => $ot['hours'], 'refs' => $ot['refs'], 'auto' => true];
        }
        // §45: approved expense reimbursements — not wage: excluded from the insurance base and the deduction ceilings
        $reimb = 0.0;
        foreach (self::expenseReimbursements($e, $period) as $x) {
            $amt = round((float) $x->data['amount'], 2);
            $reimb += $amt;
            $earnings[] = ['key' => 'expense_reimb', 'name' => 'تعويض مصروفات'.(! empty($x->data['purpose']) ? ' — '.mb_substr((string) $x->data['purpose'], 0, 60) : ''),
                'amount' => $amt, 'ref' => $x->id, 'non_wage' => true, 'taxable' => false, 'gosi' => false];
        }
        $gross = round(array_sum(array_column($earnings, 'amount')), 2);
        $wageDue = round($gross - $reimb, 2);

        $deductions = [];
        // §41: attendance deductions capped at 25% of the actual wage; the excess is carried to the next month
        $ld = self::lateDeduction($e, $period);
        $capNote = $ld['capped'] ? ' — خُفّض لسقف '.rtrim(rtrim(number_format((float) Settings::get('maxDeductionPct', $e->company_id, 25), 2), '0'), '.').'% من الأجر الفعلي (§41)' : '';
        if ($ld['carry_amt'] > 0) {
            $deductions[] = ['key' => 'att_carry', 'name' => 'حسم مُرحّل من الشهر السابق', 'amount' => $ld['carry_amt'], 'auto' => true];
        }
        if ($ld['late_amt'] > 0) {
            $deductions[] = ['key' => 'late', 'name' => "خصم تأخير ({$ld['minutes']} دقيقة)".($ld['late_amt'] < $ld['late_raw'] ? $capNote : ''), 'amount' => $ld['late_amt'], 'raw' => $ld['late_raw'], 'auto' => true];
        }
        if ($ld['abs_amt'] > 0) {
            $deductions[] = ['key' => 'absence', 'name' => "غياب بلا إذن ({$ld['absent']} يوم)".($ld['abs_amt'] < $ld['abs_raw'] ? $capNote : ''), 'amount' => $ld['abs_amt'], 'raw' => $ld['abs_raw'], 'auto' => true];
        }
        foreach (self::suspensions($e, $period) as $v) {
            $days = (int) $v->suspension_days;
            $deductions[] = ['key' => 'suspension', 'name' => "إيقاف عن العمل بدون أجر ({$days} يوم)", 'amount' => round($e->componentAmount('basic', $period) / 30 * $days, 2), 'days' => $days, 'ref' => $v->id, 'auto' => true];
        }
        foreach (Advance::where('employee_id', $e->id)->where('status', 'repaying')->get() as $adv) {
            foreach (collect($adv->schedule)->where('period', $period) as $inst) {
                if (($inst['status'] ?? '') !== 'deducted') {
                    $deductions[] = ['key' => 'advance', 'name' => 'قسط سلفة'.(! empty($inst['deferred_from']) ? ' (مؤجل من '.$inst['deferred_from'].')' : ''), 'amount' => (float) $inst['amount'], 'scheduled' => (float) $inst['amount'], 'ref' => $adv->id];
                }
            }
        }
        foreach (Violation::where('employee_id', $e->id)->where('status', 'active')->where('period', $period)->where('amount', '>', 0)->get() as $v) {
            $deductions[] = ['key' => 'penalty', 'name' => 'جزاء مخالفة — '.Lookup::name('violation_types', $v->type), 'amount' => (float) $v->amount, 'ref' => $v->id];
        }
        // social insurance on the contributory wage (basic + housing of the period, capped)
        $g = self::gosiFor($e, $period);
        $gosi = null;
        if ($g['enabled'] && $g['scheme'] !== 'exempt') {
            $base = min($g['wage_cap'], round(collect($earnings)->whereIn('key', ['basic', 'housing'])->sum('amount'), 2));
            $gosi = $g + ['base' => $base, 'employee' => round($base * $g['employee_rate'] / 100, 2), 'employer' => round($base * $g['employer_rate'] / 100, 2)];
            if ($gosi['employee'] > 0) {
                $deductions[] = ['key' => 'gosi', 'name' => 'التأمينات الاجتماعية ('.rtrim(rtrim(number_format($g['employee_rate'], 2), '0'), '.').'% من '.number_format($base, 2).')', 'amount' => $gosi['employee'], 'auto' => true];
            }
        }
        $unpaid = self::unpaidDays($e, $period);
        if ($unpaid > 0) {
            $deductions[] = ['key' => 'unpaid', 'name' => "إجازة بدون راتب ({$unpaid} يوم)", 'amount' => round($e->monthlyGross($period) / self::daysInMonth($period) * $unpaid, 2), 'auto' => true];
        }
        $sick = self::sickDeduction($e, $period);
        if ($sick['amount'] > 0) {
            $parts = array_filter([$sick['three_quarters'] ? "{$sick['three_quarters']} يوم بثلاثة أرباع الأجر" : null, $sick['unpaid'] ? "{$sick['unpaid']} يوم بدون أجر" : null]);
            $deductions[] = ['key' => 'sick', 'name' => 'خصم إجازة مرضية (§59) — '.implode('، ', $parts), 'amount' => $sick['amount'], 'auto' => true];
        }
        // §42: the instalment together with the other deductions may not exceed half the wage due — the instalment
        // is reduced and the difference is NOT forgiven: it is rescheduled after the last instalment on finalising.
        $half = round($wageDue / 2, 2);
        $sumAll = round(array_sum(array_column($deductions, 'amount')), 2);
        $deferrals = [];
        if ($sumAll > $half) {
            $excess = $sumAll - $half;
            foreach ($deductions as &$dd) {
                if ($dd['key'] === 'advance' && $excess > 0) {
                    $cut = round(min($excess, $dd['amount']), 2);
                    $dd['amount'] = round($dd['amount'] - $cut, 2);
                    $dd['name'] .= ' (خُفّض القسط ليبقى مجموع الحسومات ≤ نصف الأجر — §42؛ يُرحَّل الفرق '.number_format($cut, 2).' بعد آخر قسط)';
                    $dd['deferred'] = $cut;
                    $deferrals[] = ['ref' => $dd['ref'], 'scheduled' => $dd['scheduled'], 'deducted' => $dd['amount'], 'deferred' => $cut];
                    $excess -= $cut;
                }
            }
            unset($dd);
            $deductions = array_values(array_filter($deductions, fn ($x) => $x['amount'] > 0));
        }
        $dedTotal = round(array_sum(array_column($deductions, 'amount')), 2);

        return ['employee_id' => $e->id, 'employee_name' => $e->name, 'emp_no' => $e->emp_no, 'company_id' => $e->company_id,
            'period' => $period, 'service_days' => $pr['days'], 'prorate' => $pr, 'earnings' => $earnings, 'gross' => $gross, 'wage_due' => $wageDue,
            'deductions' => $deductions, 'ded_total' => $dedTotal, 'net' => round($gross - $dedTotal, 2),
            'att_cap' => $ld['cap'], 'att_overflow' => $ld['overflow'], 'advance_deferrals' => $deferrals,
            'overtime' => $ot['hours'] > 0 ? $ot : null,
            'gosi' => $gosi, 'employer_cost' => round($gross + ($gosi['employer'] ?? 0), 2)];
    }
}
