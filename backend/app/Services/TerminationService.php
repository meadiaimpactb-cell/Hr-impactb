<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\CustodyItem;
use App\Models\Employee;
use App\Models\Lookup;
use App\Models\Termination;
use Illuminate\Support\Carbon;

/** EOSB (Saudi labour law art. 84/85 style, all factors data) and final settlement lines. */
class TerminationService
{
    public static function serviceYears(Employee $e, string $end): float
    {
        return max(0, Carbon::parse($e->hire_date)->diffInDays(Carbon::parse($end)) / 365.25);
    }

    /** Wage considered for EOSB = basic + housing + transport (keys configurable via setting eosbWageKeys). */
    public static function consideredWage(Employee $e): float
    {
        $keys = (array) Settings::get('eosbWageKeys', $e->company_id, ['basic', 'housing', 'transport']);

        return round($e->activeComponents()->whereIn('key', $keys)->sum('amount'), 2);
    }

    public static function eosb(Employee $e, string $end, string $reason): array
    {
        $yrs = self::serviceYears($e, $end);
        $wage = self::consideredWage($e);
        $half = (float) Settings::get('eosbHalfYears', $e->company_id, 5);
        $t1 = min($yrs, $half) * 0.5 * $wage;
        $t2 = max(0, $yrs - $half) * $wage;
        $gross = $t1 + $t2;
        $f = (array) Settings::get('eosbResignFactors', $e->company_id, ['lt2' => 0, 'lt5' => 1 / 3, 'lt10' => 2 / 3, 'gte10' => 1]);
        $reasonMeta = collect(Lookup::grouped()['termination_reasons'] ?? [])->firstWhere('code', $reason)['meta'] ?? [];
        $factor = 1;
        $why = 'كامل المكافأة';
        if (! $e->eosb_saudi) {
            [$factor, $why] = [0, 'لا تنطبق قواعد نهاية الخدمة السعودية على هذا الموظف'];
        } elseif ($reason === 'resign' || ($reasonMeta['eosb_factor'] ?? '') === '') {
            if ($yrs < 2) {
                [$factor, $why] = [(float) $f['lt2'], 'استقالة والخدمة أقل من سنتين'];
            } elseif ($yrs < 5) {
                [$factor, $why] = [(float) $f['lt5'], 'استقالة والخدمة من سنتين إلى أقل من خمس (ثلث)'];
            } elseif ($yrs < 10) {
                [$factor, $why] = [(float) $f['lt10'], 'استقالة والخدمة من خمس إلى أقل من عشر (ثلثان)'];
            } else {
                [$factor, $why] = [(float) $f['gte10'], 'استقالة والخدمة عشر سنوات فأكثر'];
            }
        } else {
            $factor = (float) $reasonMeta['eosb_factor'];
            $why = $factor == 0 ? 'لا استحقاق وفق سبب الإنهاء' : ($factor == 1 ? 'كامل المكافأة' : "معامل $factor وفق سبب الإنهاء");
        }
        $months = (int) floor(($yrs - floor($yrs)) * 12);

        return ['years' => round($yrs, 2), 'pretty' => floor($yrs).' سنة و'.$months.' شهر', 'wage' => $wage, 'half_years' => $half, 't1' => round($t1, 2), 't2' => round($t2, 2),
            'gross' => round($gross, 2), 'factor' => round($factor, 4), 'why' => $why, 'amount' => round($gross * $factor, 2)];
    }

    /** Settlement lines: last-period prorated net, leave encashment, EOSB, minus open advances; lost custody listed at 0. */
    public static function settlement(Termination $t): array
    {
        $e = $t->employee;
        $last = $t->last_working_day->toDateString();
        $period = substr($last, 0, 7);
        $line = PayrollService::computeLine($e, $period);
        // computeLine() already prorates gross to the employee's last_working_day; advance instalments are settled below in full, so exclude them here
        $worked = $line['service_days'] ?? (int) $t->last_working_day->day;
        $dedNoAdvance = round(array_sum(array_column(array_filter($line['deductions'], fn ($x) => ($x['key'] ?? '') !== 'advance'), 'amount')), 2);
        $lastNet = round($line['gross'] - $dedNoAdvance, 2);
        $annual = LeaveService::balance($e, 'annual');
        $encash = round($e->monthlyGross() / 30 * max(0, $annual['available']), 2);
        $lines = [
            ['key' => 'last_wage', 'name' => "صافي أجر آخر فترة ($period — $worked يوم)", 'amount' => $lastNet],
            ['key' => 'leave', 'name' => "بدل إجازة سنوية ({$annual['available']} يوم)", 'amount' => $encash],
            ['key' => 'eosb', 'name' => 'مكافأة نهاية الخدمة', 'amount' => $t->eosb_adjusted ?? $t->eosb['amount']],
        ];
        foreach (Advance::where('employee_id', $e->id)->where('status', 'repaying')->get() as $a) {
            $lines[] = ['key' => 'advance', 'name' => 'رصيد سلفة متبقٍ', 'amount' => -round($a->remaining, 2), 'ref' => $a->id];
        }
        foreach (CustodyItem::where('employee_id', $e->id)->whereIn('status', ['in_use', 'pending_ack', 'return_requested', 'inspecting', 'lost', 'damaged'])->get() as $c) {
            $lines[] = ['key' => 'custody', 'name' => 'عهدة غير مُعادة: '.$c->ident.' (تُقرر بقرار)', 'amount' => 0, 'ref' => $c->id];
        }

        return ['lines' => $lines, 'total' => round(array_sum(array_column($lines, 'amount')), 2), 'version' => ($t->settlement['version'] ?? 0) + 1, 'built_at' => now()->toDateTimeString()];
    }

    /**
     * §108 notice: the employee gives noticeDaysMonthly (30) in a monthly-paid open contract; the employer gives
     * noticeDaysEmployer (60). Non-monthly contracts: noticeDaysOther (30) for either party. Art. 80 dismissal: none.
     */
    public static function noticeDays(Employee $e, string $initiatedBy, ?string $reason = null): int
    {
        if (in_array($reason, ['misconduct', 'probation'], true)) { // §21: either party may end the contract during probation without notice
            return 0;
        }
        $monthly = $e->contract_type === 'full';
        if (! $monthly) {
            return (int) Settings::get('noticeDaysOther', $e->company_id, 30);
        }

        return $initiatedBy === 'employee' ? (int) Settings::get('noticeDaysMonthly', $e->company_id, 30) : (int) Settings::get('noticeDaysEmployer', $e->company_id, 60);
    }

    /** Leaders (appendix 5 "تعيين مدير إدارة أو إنهاء خدمته"): employees with direct reports, or a company CEO. */
    public static function isLeader(Employee $e): bool
    {
        return Employee::where('manager_id', $e->id)->where('status', '!=', 'terminated')->exists() || \App\Models\Company::where('ceo_employee_id', $e->id)->exists();
    }

    /** A fresh approval chain record. */
    public static function newFlow(string $key): array
    {
        return ['chain' => Termination::FLOWS[$key], 'stage' => 0, 'approvals' => [], 'done' => false];
    }

    public static function clearanceTasks(): array
    {
        return array_map(fn ($l) => ['key' => $l['code'], 'label' => $l['name'], 'owner' => $l['meta']['owner'] ?? 'hr', 'done' => false, 'evidence' => null, 'by' => null, 'at' => null], Lookup::grouped()['offboarding_tasks'] ?? []);
    }
}
