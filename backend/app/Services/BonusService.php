<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Incentive;
use App\Models\MonthlyReport;
use App\Models\WeeklyReport;
use Illuminate\Support\Facades\DB;

/** §84–88: raise guidance, annual bonus months, and the excellence points of the incentives register. */
class BonusService
{
    /** §85: bonus months by annual grade × company tier. */
    public const BONUS_MONTHS = [
        'tasis' => ['A+' => 1, 'A' => 0.5, 'B+' => 0.25, 'B' => 0, 'C+' => 0, 'C' => 0],
        'tamkeen' => ['A+' => 3, 'A' => 2, 'B+' => 1.5, 'B' => 1, 'C+' => 0.5, 'C' => 0.5],
        'athar' => ['A+' => 5, 'A' => 3, 'B+' => 2, 'B' => 1.5, 'C+' => 1, 'C' => 1],
    ];

    /** §84: indicative raise range (% of the basic wage) by annual grade. */
    public const RAISE = ['A+' => [12, 15], 'A' => [9, 12], 'B+' => [7, 9], 'B' => [5, 7], 'C+' => [0, 5], 'C' => [0, 5]];

    /** §85–86: bonus = months × December comprehensive wage × qualifying-service ratio × funding factor. */
    public static function annualBonus(Employee $e, int $year, ?array $annual = null): array
    {
        $annual ??= EvaluationService::annual($e, $year);
        $grade = $annual['grade']['grade'] ?? null;
        $tier = $e->company?->tier?->code ?? 'tasis';
        $months = $grade ? (self::BONUS_MONTHS[$tier][$grade] ?? 0) : 0;
        $cap = (float) ($e->benefit('bonusMaxMonths') ?? $months);
        $months = min($months, $cap);
        $dec = "$year-12";
        $wage = round($e->activeComponents($dec)->sum('amount'), 2); // basic + fixed allowances of December (§5 الأجر الشامل للمزايا)
        $svc = self::qualifyingDays($e, \Illuminate\Support\Carbon::create($year, 1, 1), \Illuminate\Support\Carbon::create($year, 12, 31));
        $ratio = min(1, max(0, $svc['days'] / 365)) * ($e->contract_type === 'part' ? ($e->part_ratio ?: 0.5) : 1);
        $factor = (float) Settings::get('bonusFundingFactor', $e->company_id, 1);
        // §86: at least 6 months of qualifying service in the year — unpaid leave excluded, exactly as in the ratio
        $eligible = $svc['days'] >= 182 && $grade !== null && ! ($annual['insufficient'] ?? false);
        // §84: promotion nomination needs B or better, no running improvement plan and no penalty in force
        $inPlan = \App\Models\ImprovementPlan::where('employee_id', $e->id)->whereIn('status', ['active', 'extended'])->exists();
        $penalty = EvaluationService::hasActivePenalty($e);
        $promo = in_array($grade, ['A+', 'A', 'B+', 'B'], true) && ! $inPlan && ! $penalty;
        $amount = $eligible ? round($months * $wage * $ratio * $factor, 2) : 0;

        return ['grade' => $grade, 'tier' => $tier, 'months' => $months, 'wage' => $wage, 'service_ratio' => round($ratio, 4), 'service_days' => $svc['days'], 'unpaid_days' => $svc['unpaid'], 'funding_factor' => $factor, 'eligible' => $eligible,
            'amount' => $amount, 'raise_range' => $grade && ! $inPlan && ! (method_exists(\App\Models\Violation::class, 'activeDeprivation') && \App\Models\Violation::activeDeprivation($e->id)) ? self::RAISE[$grade] ?? [0, 0] : [0, 0], 'promotion_eligible' => $promo,
            'promotion_blockers' => array_values(array_filter([$inPlan ? 'في خطة تحسين أداء' : null, $penalty ? 'عليه جزاء نافذ' : null]))];
    }

    /**
     * §86 qualifying service between two dates: calendar days employed (from hire, to the last working day) minus
     * approved unpaid-leave days that fall inside the window. Paid statutory leave is NOT excluded.
     */
    public static function qualifyingDays(Employee $e, \Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): array
    {
        $start = $e->hire_date && $e->hire_date->gt($from) ? $e->hire_date->copy() : $from->copy();
        $end = $e->last_working_day && $e->last_working_day->lt($to) ? $e->last_working_day->copy() : $to->copy();
        $days = $end->lt($start) ? 0 : $start->diffInDays($end) + 1;
        $unpaid = 0;
        foreach (\App\Models\ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->where('status', 'approved')->where('data->type', 'unpaid')->get(['data']) as $r) {
            $a = \Illuminate\Support\Carbon::parse($r->data['from'] ?? $start);
            $b = \Illuminate\Support\Carbon::parse($r->data['to'] ?? $a);
            $a = $a->lt($start) ? $start->copy() : $a;
            $b = $b->gt($end) ? $end->copy() : $b;
            if ($b->gte($a)) {
                $unpaid += $a->diffInDays($b) + 1;
            }
        }

        return ['days' => max(0, (int) round($days - $unpaid)), 'calendar' => (int) round($days), 'unpaid' => $unpaid];
    }

    /** §88: kinds of the incentives register that are a «documented praise» (manager / management / external body) = 20 points each. */
    public const PRAISE_KINDS = ['praise', 'thanks_letter', 'certificate', 'honor', 'achievement'];

    /**
     * §88: excellence points of a month (they do not enter the evaluation score). Only the policy table counts:
     * report on time 10 each (monthly + weekly) · full attendance 15 · development target 3 consecutive months 30 ·
     * peer praise 5 (one per colleague per month) · documented praise 20.
     */
    public static function excellencePoints(Employee $e, string $period): array
    {
        $pts = [];
        $monthEnd = \Illuminate\Support\Carbon::parse($period.'-01')->endOfMonth();
        $rep = MonthlyReport::where('employee_id', $e->id)->where('period', $period)->first();
        if ($rep && in_array($rep->status, MonthlyReport::SUBMITTED, true) && ($rep->first_submitted_at ?? $rep->submitted_at) && ! $rep->late) {
            $pts[] = ['label' => 'التقرير الشهري في موعده', 'points' => 10];
        }
        $weeks = WeeklyReport::where('employee_id', $e->id)->where('week_start', 'like', "$period-%")->where('status', 'submitted')->count();
        if ($weeks) {
            $pts[] = ['label' => "تقارير أسبوعية في موعدها ($weeks)", 'points' => 10 * $weeks];
        }
        if (now()->gt($monthEnd) && (! $e->hire_date || $e->hire_date->lte($monthEnd))) {
            if ($e->punch_required) {
                $a = AttendanceService::summary($e, $period);
                $full = $a['required'] > 0 && $a['absent'] == 0 && $a['late'] == 0;
            } else {
                // punch-exempt: full attendance unless an absence or a lateness was recorded for the month
                $full = ! $e->attendance()->whereBetween('work_date', [$period.'-01', $monthEnd->toDateString()])
                    ->where(fn ($q) => $q->where('status', 'absent')->orWhere('late_minutes', '>', (int) Settings::get('graceMinutes', $e->company_id, 0)))->exists();
            }
            if ($full) {
                $pts[] = ['label' => 'انضباط كامل في الحضور', 'points' => 15];
            }
        }
        // development target (100) in 3 consecutive months: 30 points in the month completing each run of three
        $run = 0;
        for ($i = 0; $i < 12; $i++) {
            $p = \Illuminate\Support\Carbon::parse($period.'-01')->subMonthsNoOverflow($i)->format('Y-m');
            if (EvaluationService::devPoints($e, $p)['total'] >= 100) { $run++; } else { break; }
        }
        if ($run >= 3 && $run % 3 === 0) {
            $pts[] = ['label' => 'تحقيق مستهدف التطوير 3 أشهر متتالية', 'points' => 30];
        }
        // one praise per colleague per month (the register already refuses a second one; distinct senders as a guard)
        $kudos = DB::table('kudos')->where('employee_id', $e->id)->where('period', $period)->distinct()->count('from_user_id');
        if ($kudos) {
            $pts[] = ['label' => "إشادات الزملاء ($kudos)", 'points' => 5 * $kudos];
        }
        $praise = Incentive::where('employee_id', $e->id)->where('period', $period)->whereIn('kind', self::PRAISE_KINDS)->count();
        if ($praise) {
            $pts[] = ['label' => "إشادات موثقة ($praise)", 'points' => 20 * $praise];
        }

        return ['period' => $period, 'items' => $pts, 'total' => array_sum(array_column($pts, 'points'))];
    }
}
