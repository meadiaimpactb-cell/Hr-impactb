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
        $start = $e->hire_date ? max($e->hire_date->copy(), \Illuminate\Support\Carbon::create($year, 1, 1)) : \Illuminate\Support\Carbon::create($year, 1, 1);
        $end = $e->last_working_day && $e->last_working_day->year === $year ? $e->last_working_day : \Illuminate\Support\Carbon::create($year, 12, 31);
        $days = max(0, $start->diffInDays($end) + 1);
        $unpaid = (float) \App\Models\ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->where('status', 'approved')->where('data->type', 'unpaid')->where('data->from', 'like', "$year-%")->get(['data'])->sum(fn ($r) => $r->data['days'] ?? 0);
        $ratio = min(1, max(0, ($days - $unpaid) / 365)) * ($e->contract_type === 'part' ? ($e->part_ratio ?: 0.5) : 1);
        $factor = (float) Settings::get('bonusFundingFactor', $e->company_id, 1);
        $eligible = $days >= 182 && $grade !== null && ! ($annual['insufficient'] ?? false);
        $amount = $eligible ? round($months * $wage * $ratio * $factor, 2) : 0;

        return ['grade' => $grade, 'tier' => $tier, 'months' => $months, 'wage' => $wage, 'service_ratio' => round($ratio, 4), 'funding_factor' => $factor, 'eligible' => $eligible,
            'amount' => $amount, 'raise_range' => $grade ? self::RAISE[$grade] ?? [0, 0] : [0, 0], 'promotion_eligible' => in_array($grade, ['A+', 'A', 'B+', 'B'], true)];
    }

    /** §88: excellence points of a month (do not enter the evaluation score). */
    public static function excellencePoints(Employee $e, string $period): array
    {
        $pts = [];
        $rep = MonthlyReport::where('employee_id', $e->id)->where('period', $period)->first();
        if ($rep && $rep->submitted_at && ! $rep->late) {
            $pts[] = ['label' => 'التقرير الشهري في موعده', 'points' => 10];
        }
        $weeks = WeeklyReport::where('employee_id', $e->id)->where('week_start', 'like', "$period-%")->where('status', 'submitted')->count();
        if ($weeks) {
            $pts[] = ['label' => "تقارير أسبوعية في موعدها ($weeks)", 'points' => 10 * $weeks];
        }
        if ($e->punch_required) {
            $a = AttendanceService::summary($e, $period);
            if ($a['required'] > 0 && $a['absent'] == 0 && $a['late'] == 0 && now()->gt(\Illuminate\Support\Carbon::parse($period.'-01')->endOfMonth())) {
                $pts[] = ['label' => 'انضباط كامل في الحضور', 'points' => 15];
            }
        }
        $streak = 0;
        for ($i = 0; $i < 3; $i++) {
            $p = \Illuminate\Support\Carbon::parse($period.'-01')->subMonths($i)->format('Y-m');
            if (EvaluationService::devPoints($e, $p)['total'] >= 100) { $streak++; } else { break; }
        }
        if ($streak >= 3) {
            $pts[] = ['label' => 'تحقيق مستهدف التطوير 3 أشهر متتالية', 'points' => 30];
        }
        $kudos = DB::table('kudos')->where('employee_id', $e->id)->where('period', $period)->count();
        if ($kudos) {
            $pts[] = ['label' => "إشادات الزملاء ($kudos)", 'points' => 5 * $kudos];
        }
        $praise = Incentive::where('employee_id', $e->id)->where('period', $period)->sum('points');
        if ($praise) {
            $pts[] = ['label' => 'إشادات موثقة وتكريمات', 'points' => (int) $praise];
        }

        return ['period' => $period, 'items' => $pts, 'total' => array_sum(array_column($pts, 'points'))];
    }
}
