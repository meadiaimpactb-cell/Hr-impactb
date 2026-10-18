<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\Lookup;
use App\Models\Violation;
use Illuminate\Support\Carbon;

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
        $days = (int) Settings::get('closeDays', $companyId, 5);

        return Carbon::parse($period.'-01')->addMonthNoOverflow()->addDays($days)->toDateString();
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

    /** Late/absence deduction for the month, capped at the company's policy ceiling. */
    public static function lateDeduction(Employee $e, string $period): array
    {
        $a = AttendanceService::summary($e, $period);
        $factor = (float) Settings::get('lateFactor', $e->company_id, 1);
        $mv = self::minuteValue($e, $period);
        $lateAmt = round(($a['late_minutes'] + $a['early_minutes']) * $mv * $factor, 2);
        $absAmt = round($e->monthlyGross($period) / 30 * $a['absent'], 2); // §41: a day of absence = one day of the wage (÷30)
        $cap = $e->monthlyGross($period) * (float) Settings::get('maxDeductionPct', $e->company_id, 25) / 100;
        $total = min($cap, $lateAmt + $absAmt);

        return ['late_amt' => $lateAmt, 'abs_amt' => $absAmt, 'total' => round($total, 2), 'capped' => ($lateAmt + $absAmt) > $cap,
            'minutes' => $a['late_minutes'] + $a['early_minutes'], 'absent' => $a['absent']];
    }

    /** Unpaid-leave days approved within the period (already excluded from pay by the earning being prorated? No — deducted explicitly). */
    public static function unpaidDays(Employee $e, string $period): float
    {
        return (float) ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->where('status', 'approved')
            ->where('data->type', 'unpaid')->where('data->from', 'like', $period.'%')->get(['data'])
            ->sum(fn ($r) => $r->data['days'] ?? 0);
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
        $gross = round(array_sum(array_column($earnings, 'amount')), 2);

        $deductions = [];
        $ld = self::lateDeduction($e, $period);
        if ($ld['late_amt'] > 0) {
            $deductions[] = ['key' => 'late', 'name' => "خصم تأخير ({$ld['minutes']} دقيقة)", 'amount' => $ld['late_amt'], 'auto' => true];
        }
        if ($ld['abs_amt'] > 0) {
            $deductions[] = ['key' => 'absence', 'name' => "غياب بلا إذن ({$ld['absent']} يوم)", 'amount' => $ld['abs_amt'], 'auto' => true];
        }
        foreach (Advance::where('employee_id', $e->id)->where('status', 'repaying')->get() as $adv) {
            $inst = collect($adv->schedule)->firstWhere('period', $period);
            if ($inst && ($inst['status'] ?? '') !== 'deducted') {
                $deductions[] = ['key' => 'advance', 'name' => 'قسط سلفة', 'amount' => (float) $inst['amount'], 'ref' => $adv->id];
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
        // §42: the instalment together with the other deductions may not exceed half the wage due — the instalment is reduced and the rest stays due
        $half = round($gross / 2, 2);
        $sumAll = round(array_sum(array_column($deductions, 'amount')), 2);
        if ($sumAll > $half) {
            $excess = $sumAll - $half;
            foreach ($deductions as &$dd) {
                if ($dd['key'] === 'advance' && $excess > 0) {
                    $cut = min($excess, $dd['amount']);
                    $dd['amount'] = round($dd['amount'] - $cut, 2);
                    $dd['name'] .= ' (خُفّض القسط ليبقى مجموع الحسومات ≤ نصف الأجر — §42)';
                    $dd['deferred'] = round($cut, 2);
                    $excess -= $cut;
                }
            }
            unset($dd);
            $deductions = array_values(array_filter($deductions, fn ($x) => $x['amount'] > 0));
        }
        $dedTotal = round(array_sum(array_column($deductions, 'amount')), 2);

        return ['employee_id' => $e->id, 'employee_name' => $e->name, 'emp_no' => $e->emp_no, 'company_id' => $e->company_id,
            'period' => $period, 'service_days' => $pr['days'], 'prorate' => $pr, 'earnings' => $earnings, 'gross' => $gross,
            'deductions' => $deductions, 'ded_total' => $dedTotal, 'net' => round($gross - $dedTotal, 2),
            'gosi' => $gosi, 'employer_cost' => round($gross + ($gosi['employer'] ?? 0), 2)];
    }
}
