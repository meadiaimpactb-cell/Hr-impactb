<?php

namespace App\Services;

use App\Models\DevRecord;
use App\Models\Employee;
use App\Models\EvalTemplate;
use App\Models\Evaluation;
use App\Models\Lookup;
use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Models\Violation;
use App\Models\WeeklyReport;
use Illuminate\Support\Carbon;

/**
 * Weighted evaluation engine. Four axes (achievement / attendance / development / discipline);
 * weights, sub-weights, grades and pass score are data (eval_templates, per group/company/job).
 * Attendance axis is skipped (and its weight removed from the denominator) for employees not required to punch.
 */
class EvaluationService
{
    private const BASE = ['achieve' => 50, 'attend' => 20, 'dev' => 20, 'disc' => 10];

    public static function templateFor(Employee $e): EvalTemplate
    {
        $all = EvalTemplate::where('active', true)->get();

        return $all->first(fn ($t) => $t->scope === 'company' && $t->scope_id == $e->company_id)
            ?? $all->first(fn ($t) => $t->scope === 'job' && $t->scope_id == $e->job_title_id)
            ?? $all->first(fn ($t) => $t->scope === 'group')
            ?? $all->first();
    }

    public static function reportTemplateFor(Employee $e): ?ReportTemplate
    {
        $all = ReportTemplate::where('active', true)->get();

        return $all->first(fn ($t) => $t->scope === 'employee' && $t->scope_id == $e->id)
            ?? $all->first(fn ($t) => $t->scope === 'job' && $t->scope_id == $e->job_title_id)
            ?? $all->first(fn ($t) => $t->scope === 'company' && $t->scope_id == $e->company_id)
            ?? $all->first(fn ($t) => $t->scope === 'group')
            ?? $all->first();
    }

    public static function grade(float $score, EvalTemplate $tpl): array
    {
        foreach ($tpl->grades as [$min, $g, $label]) {
            if ($score >= $min) {
                return ['grade' => $g, 'label' => $label, 'pass' => true];
            }
        }

        return ['grade' => '—', 'label' => 'غير مقبول — يحتاج خطة تطوير', 'pass' => false];
    }

    /**
     * Development points of a month (§78): verified records whose points count in the month, repeatable activities
     * capped, plus the carry-over from the previous month (what exceeded 150, up to 50). Level per §79.
     */
    public static function devPoints(Employee $e, string $period): array
    {
        $raw = self::rawDevPoints($e->id, $period);
        $prev = Carbon::parse($period.'-01')->subMonth()->format('Y-m');
        $carry = (int) min(50, max(0, self::rawDevPoints($e->id, $prev) - 150));
        $total = min(150, $raw + $carry);
        $level = $total >= 150 ? 'gold' : ($total >= 125 ? 'silver' : ($total >= 100 ? 'bronze' : ($total >= 50 ? 'below' : 'alert')));

        return ['points' => $raw, 'carry' => $carry, 'total' => $total, 'target' => 100, 'max' => 150, 'level' => $level,
            'level_label' => ['gold' => 'ذهبي', 'silver' => 'فضي', 'bronze' => 'برونزي', 'below' => 'دون المستهدف', 'alert' => 'تنبيه'][$level],
            'records' => DevRecord::where('employee_id', $e->id)->where('status', 'verified')->where('points_month', $period)->count()];
    }

    /** Sum of verified points in a month with the per-activity monthly caps applied. */
    public static function rawDevPoints(int $employeeId, string $period): int
    {
        $caps = collect(\App\Models\Lookup::where('group', 'dev_activities')->get())->mapWithKeys(fn ($l) => [$l->code => (int) ($l->meta['cap'] ?? 0)]);
        $byAct = DevRecord::where('employee_id', $employeeId)->where('status', 'verified')->where('points_month', $period)->get(['activity', 'points'])->groupBy('activity');
        $sum = 0;
        foreach ($byAct as $act => $rows) {
            $pts = (int) $rows->sum('points');
            $cap = (int) ($caps[$act] ?? 0);
            $sum += $cap > 0 ? min($cap, $pts) : $pts;
        }

        return $sum;
    }

    /** Points of one activity (§78); the course family is chosen by hours when the activity is not explicit. */
    public static function pointsFor(?string $activity, ?float $hours): array
    {
        $acts = \App\Models\Lookup::where('group', 'dev_activities')->where('active', true)->orderBy('sort')->get();
        $a = $activity ? $acts->firstWhere('code', $activity) : null;
        if (! $a && $hours !== null) {
            $a = $acts->first(fn ($l) => isset($l->meta['min_hours'], $l->meta['max_hours']) && $hours >= $l->meta['min_hours'] && $hours < $l->meta['max_hours']);
        }

        return $a ? ['activity' => $a->code, 'points' => (int) ($a->meta['points'] ?? 0)] : ['activity' => $activity, 'points' => 0];
    }

    /** Whether weekly reports are expected from this employee (§34: the manager enables it per employee, else the company setting). */
    public static function weeklyRequired(Employee $e): bool
    {
        return $e->weekly_report_required ?? (Settings::get('weeklyReportEnabled', $e->company_id, true) !== false);
    }

    /** Due date of a week's report (§34): the first day of the following week, end of day. */
    public static function weekDue(WeeklyReport $w): Carbon
    {
        return $w->week_start->copy()->addDays(7)->endOfDay();
    }

    /** Weekly reporting discipline for a month: only weeks already ended count. */
    public static function weeklyStats(int $employeeId, string $period): array
    {
        $rows = WeeklyReport::where('employee_id', $employeeId)->where('week_start', 'like', "$period-%")->where('week_end', '<=', now()->toDateString())->get();

        return ['total' => $rows->count(), 'on_time' => $rows->where('status', 'submitted')->count(), 'late' => $rows->where('status', 'late')->count(), 'missing' => $rows->where('status', 'missing')->count()];
    }

    /**
     * Compute the evaluation for one employee/period. $overrides: weights (simulation), bonus, tpl.
     * Returns axes (scaled to template weights), applicable weight total, earned, score, grade, parts (detail).
     */
    public static function compute(Employee $e, string $period, array $overrides = []): array
    {
        $tpl = $overrides['tpl'] ?? self::templateFor($e);
        $W = $overrides['weights'] ?? $tpl->weights;
        $sub = $tpl->sub;
        $rep = MonthlyReport::where('employee_id', $e->id)->where('period', $period)->first();
        $parts = [];
        $r1 = fn ($x) => round((float) $x, 1);

        // achievement: targets by weighted % (manager score preferred), plus quality score (CEO/HR)
        $ach = 0;
        if ($rep && count($rep->items)) {
            $tw = array_sum(array_map(fn ($i) => (float) ($i['weight'] ?? 0), $rep->items)) ?: 1;
            $pct = array_sum(array_map(fn ($i) => (float) ($i['mgr_score'] ?? $i['self_score'] ?? $i['pct'] ?? 0) * (float) ($i['weight'] ?? 0), $rep->items)) / $tw;
            $targets = $pct / 100 * $sub['achieve']['targets'];
            $quality = min($sub['achieve']['quality'], (float) ($rep->quality ?? 0));
            $ach = $targets + $quality;
            $parts['achieve'] = ['pct' => $r1($pct), 'targets' => $r1($targets), 'quality' => $r1($quality), 'report_status' => $rep->status];
        } else {
            // no report items yet: the manager's quality rating still counts
            $quality = min($sub['achieve']['quality'], (float) ($rep?->quality ?? 0));
            $ach = $quality;
            $parts['achieve'] = ['pct' => 0, 'targets' => 0, 'quality' => $r1($quality), 'report_status' => $rep?->status ?? 'none'];
        }

        // attendance: presence, no-late, daily note — only for punching employees
        $att = null;
        if ($e->punch_required) {
            $a = AttendanceService::summary($e, $period);
            $presence = $a['required'] ? (($a['required'] - $a['absent']) / $a['required']) * $sub['attend']['presence'] : $sub['attend']['presence'];
            $noLate = $a['late'] === 0 ? $sub['attend']['noLate'] : max(0, $sub['attend']['noLate'] - $a['late'] * 1);
            $noteReq = $e->daily_note_required || Settings::get('dailyNoteEnabled', $e->company_id, false);
            $pendingNotes = $noteReq ? $e->attendance()->where('work_date', 'like', "$period-%")->where('note_required', true)->whereNull('note')->count() : 0;
            $dn = $noteReq ? max(0, $sub['attend']['dailyNote'] - $pendingNotes) : $sub['attend']['dailyNote'];
            $att = $presence + $noLate + $dn;
            $parts['attend'] = ['presence' => $r1($presence), 'no_late' => $r1($noLate), 'daily_note' => $r1($dn), 'absent' => $a['absent'], 'late' => $a['late']];
        }

        // development (§78): (points ÷ 100) × 20, up to 30 at 150 points — the surplus compensates the other axes
        $dp = self::devPoints($e, $period);
        $dev = $dp['total'] / 100 * self::BASE['dev'];
        $parts['dev'] = ['points' => $dp['points'], 'carry' => $dp['carry'], 'total' => $dp['total'], 'level' => $dp['level_label'], 'records' => $dp['records'], 'score' => $r1($dev)];

        // discipline: weekly reports on time, cooperation (manager), conduct (no active non-financial violation)
        $ws = self::weeklyStats($e->id, $period);
        $weeklyOn = self::weeklyRequired($e);
        $weekly = $weeklyOn && $ws['total'] ? max(0, $sub['disc']['weekly'] * ($ws['on_time'] + $ws['late'] * 0.5) / $ws['total']) : $sub['disc']['weekly'];
        $coop = min($sub['disc']['coop'], (float) ($rep?->coop ?? $sub['disc']['coop']));
        $hasPen = Violation::where('employee_id', $e->id)->where('status', 'active')->where('amount', 0)->where('effective_from', '<=', $period.'-31')->exists();
        $conduct = $hasPen ? 0 : $sub['disc']['conduct'];
        $disc = $weekly + $coop + $conduct;
        $parts['disc'] = ['weekly' => $r1($weekly), 'coop' => $r1($coop), 'conduct' => $r1($conduct), 'weeks' => $ws];

        // scale each axis from its base to the template weight
        $sc = fn ($k) => ($W[$k] ?? self::BASE[$k]) / self::BASE[$k];
        $a1 = $ach * $sc('achieve');
        $a2 = $att === null ? null : $att * $sc('attend');
        $a3 = $dev * $sc('dev');
        $a4 = $disc * $sc('disc');
        $applicable = ($W['achieve'] + $W['dev'] + $W['disc']) + ($att === null ? 0 : $W['attend']);
        $earned = $a1 + $a3 + $a4 + ($a2 ?? 0);
        $total = $applicable ? $earned / $applicable * 100 : 0;
        $bonus = (float) ($overrides['bonus'] ?? $rep?->hr_bonus ?? 0);
        // §35: late submission −3; not submitted by the end of the month −10 (instead of −3) and a red flag
        $monthOver = now()->gt(\Illuminate\Support\Carbon::parse($period.'-01')->endOfMonth());
        $submitted = $rep && in_array($rep->status, ['submitted', 'reviewed', 'hr_checked', 'approved'], true);
        $penalty = $submitted ? ($rep->late ? (float) Settings::get('reportLatePenalty', $e->company_id, 3) : 0) : ($monthOver ? (float) Settings::get('reportMissingPenalty', $e->company_id, 10) : 0);
        $parts['report'] = ['submitted' => $submitted, 'late' => (bool) ($rep?->late), 'penalty' => $penalty, 'red_flag' => ! $submitted && $monthOver];
        $score = max(0, min(100, $total + $bonus - $penalty));
        // §74: monthly colour status with its reasons
        $reasons = [];
        $achPct = $W['achieve'] ? $a1 / $W['achieve'] : 1;
        $attPct = ($a2 === null || ! $W['attend']) ? 1 : $a2 / $W['attend'];
        if ($score < 70) $reasons['red'][] = 'الدرجة أقل من 70';
        if ($achPct < 0.6) $reasons['red'][] = 'أقل من 60% من محور الإنجاز';
        if ($attPct < 0.6) $reasons['red'][] = 'أقل من 60% من محور الالتزام';
        if (! $submitted && $monthOver) $reasons['red'][] = 'لم يُرفع التقرير الشهري';
        if ($ws['missing'] >= 2) $reasons['red'][] = 'أسبوعان فأكثر دون تقرير';
        if ($score >= 70 && $score < 80) $reasons['orange'][] = 'الدرجة بين 70 و80';
        if ($rep?->late) $reasons['orange'][] = 'تأخر رفع التقرير الشهري';
        if ($ws['missing'] === 1) $reasons['orange'][] = 'أسبوع واحد دون تقرير';
        if ($ws['late'] > 0) $reasons['orange'][] = 'تأخر تقرير أسبوعي';
        if ($dp['total'] < 50 && $monthOver) $reasons['orange'][] = 'نقاط التطوير أقل من 50';
        if (($parts['attend']['late'] ?? 0) >= 3) $reasons['orange'][] = 'تأخر متكرر في الحضور';
        $flag = ['color' => ! empty($reasons['red']) ? 'red' : (! empty($reasons['orange']) ? 'orange' : 'green'), 'reasons' => array_values(array_merge($reasons['red'] ?? [], $reasons['orange'] ?? []))];

        return ['axes' => ['achieve' => $r1($a1), 'attend' => $a2 === null ? null : $r1($a2), 'dev' => $r1($a3), 'disc' => $r1($a4)], 'weights' => $W,
            'applicable' => $applicable, 'earned' => $r1($earned), 'total' => $r1($total), 'bonus' => $bonus, 'penalty' => $penalty, 'score' => round($score, 2), 'flag' => $flag,
            'grade' => self::grade($score, $tpl), 'exempt_attendance' => $att === null, 'parts' => $parts, 'template' => $tpl->only('id', 'name', 'pass_score', 'version')];
    }

    /** Persist the monthly evaluation (called when a monthly report is approved, or explicitly by HR). */
    public static function publish(Employee $e, string $period, ?int $by = null): Evaluation
    {
        $ev = self::compute($e, $period);

        return Evaluation::updateOrCreate(['employee_id' => $e->id, 'period' => $period, 'type' => 'monthly'], [
            'company_id' => $e->company_id, 'axes' => $ev['axes'], 'weights' => $ev['weights'], 'score' => $ev['score'], 'grade' => $ev['grade']['grade'],
            'grade_label' => $ev['grade']['label'], 'details' => $ev['parts'] + ['bonus' => $ev['bonus'], 'penalty' => $ev['penalty'], 'flag' => $ev['flag'], 'exempt_attendance' => $ev['exempt_attendance']],
            'template_id' => $ev['template']['id'], 'published_at' => now(), 'published_by' => $by ?? auth()->id(),
        ]);
    }

    /**
     * Annual evaluation (§73): 70% mean of the published monthly evaluations (months with fewer than 10 worked days are
     * left out) + 30% the annual-goals rating. Two kinds: fiscal (Jan–Dec) and contract (from the hire anniversary).
     */
    public static function annual(Employee $e, int $year, string $kind = 'fiscal'): ?array
    {
        if ($kind === 'contract') {
            $start = $e->hire_date->copy()->year($year);
            $periods = collect(range(0, 11))->map(fn ($i) => $start->copy()->addMonths($i)->format('Y-m'))->all();
            $rows = Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->whereIn('period', $periods)->orderBy('period')->get();
        } else {
            $rows = Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->where('period', 'like', "$year-%")->orderBy('period')->get();
        }
        $excluded = [];
        if ($e->punch_required) {
            $rows = $rows->filter(function ($r) use ($e, &$excluded) {
                $a = AttendanceService::summary($e, $r->period);
                $worked = $a['required'] - $a['absent'];
                if ($a['required'] > 0 && $worked < 10) { $excluded[] = $r->period; return false; }

                return true;
            })->values();
        }
        $min = (int) Settings::get('annualMinMonths', $e->company_id, 6);
        $goals = Evaluation::where('employee_id', $e->id)->where('type', 'goals')->where('period', $year.'-'.($kind === 'contract' ? '99' : '00'))->first();
        $series = $rows->map(fn ($r) => ['period' => $r->period, 'score' => $r->score, 'grade' => $r->grade])->values();
        if ($rows->isEmpty() || $rows->count() < $min) {
            return $rows->isEmpty() ? null : ['year' => $year, 'kind' => $kind, 'months' => $rows->count(), 'min_months' => $min, 'score' => null, 'grade' => null, 'axes' => [], 'series' => $series, 'excluded' => $excluded, 'insufficient' => true, 'goals' => $goals?->only('score', 'details', 'published_at')];
        }
        $avg = round($rows->avg('score'), 2);
        $score = $goals ? round($avg * 0.7 + (float) $goals->score * 0.3, 2) : $avg;
        $tpl = self::templateFor($e);

        return ['year' => $year, 'kind' => $kind, 'months' => $rows->count(), 'score' => $score, 'monthly_avg' => $avg, 'goals' => $goals?->only('score', 'details', 'published_at'), 'grade' => self::grade($score, $tpl),
            'formula' => $goals ? '70% متوسط الأشهر + 30% الأهداف السنوية' : 'متوسط الأشهر (لم تُقيَّم الأهداف السنوية بعد)', 'excluded' => $excluded,
            'axes' => collect(['achieve', 'attend', 'dev', 'disc'])->mapWithKeys(fn ($k) => [$k => round($rows->avg(fn ($r) => $r->axes[$k] ?? 0), 1)]),
            'series' => $series];
    }

    /**
     * Ensure weekly report rows exist for a month (missing until submitted). The week runs Sunday–Thursday, or
     * Saturday–Thursday for six-day employees (§5). A week still missing after its due date is noted in the file (§34).
     */
    public static function ensureWeeks(Employee $e, string $period): void
    {
        if (! self::weeklyRequired($e)) {
            return;
        }
        $six = (string) $e->week_pattern === '6';
        $start = Carbon::parse($period.'-01');
        $end = $start->copy()->endOfMonth();
        $hire = Carbon::parse($e->hire_date);
        for ($w = $start->copy()->startOfWeek($six ? Carbon::SATURDAY : Carbon::SUNDAY); $w->lte($end); $w->addWeek()) {
            if ($w->lt($start) || $w->lt($hire) || $w->gt(now())) {
                continue;
            }
            WeeklyReport::firstOrCreate(['employee_id' => $e->id, 'week_start' => $w->toDateString()], ['week_end' => $w->copy()->addDays($six ? 5 : 4)->toDateString(), 'status' => 'missing']);
        }
        foreach (WeeklyReport::where('employee_id', $e->id)->where('week_start', 'like', "$period-%")->where('status', 'missing')->whereNull('noted_at')->get() as $wk) {
            if (now()->gt(self::weekDue($wk))) {
                $e->career()->create(['type' => 'تقرير أسبوعي غير مرفوع', 'detail' => "أسبوع {$wk->week_start->toDateString()} لم يُرفع تقريره في موعده (§34)", 'occurred_on' => now()->toDateString()]);
                $wk->update(['noted_at' => now()]);
            }
        }
    }
}
