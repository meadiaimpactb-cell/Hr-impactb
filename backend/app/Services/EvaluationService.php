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

    /**
     * Grade of a score (§72). «Acceptable performance» = 70 (the template pass score) or more AND no standing red flag,
     * so the monthly caller passes the month's flag colour; the annual result has no flag.
     */
    public static function grade(float $score, EvalTemplate $tpl, ?string $flag = null): array
    {
        $passScore = (float) ($tpl->pass_score ?? 70);
        foreach ($tpl->grades as [$min, $g, $label]) {
            if ($score >= $min) {
                $pass = $score >= $passScore && $flag !== 'red';

                return ['grade' => $g, 'label' => $pass || $flag !== 'red' ? $label : $label.' — علامة حمراء قائمة', 'pass' => $pass];
            }
        }

        return ['grade' => '—', 'label' => 'غير مقبول — يحتاج خطة تحسين', 'pass' => false];
    }

    /** Category of a violation type (meta.category); the original seed types have none and are mapped here. */
    public static function violationCategory(?string $type): ?string
    {
        static $map = null;
        $map ??= Lookup::where('group', 'violation_types')->get(['code', 'meta'])->mapWithKeys(fn ($l) => [$l->code => $l->meta ?? []])->all();
        $legacy = ['conduct' => 'conduct', 'policy' => 'instructions', 'report' => 'instructions', 'custody' => 'instructions', 'late' => 'attendance', 'absence' => 'absence'];

        return $map[$type]['category'] ?? ($legacy[$type] ?? null);
    }

    /** Active (نافذة) violations of an employee that took effect in the month: occurred or became effective in it. */
    public static function monthViolations(int $employeeId, string $period)
    {
        $from = $period.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();

        return Violation::where('employee_id', $employeeId)->where('status', 'active')
            ->where(fn ($q) => $q->whereBetween('occurred_on', [$from, $to])->orWhereBetween('effective_from', [$from, $to]))->get();
    }

    /** Behavioural violation (§71 «السلوك وحسن الخلق»): a conduct or work-instructions type. */
    public static function isBehavioural(Violation $v): bool
    {
        return in_array(self::violationCategory($v->type), ['conduct', 'instructions'], true);
    }

    /**
     * Serious violation (§74 «مخالفة جسيمة ثابتة»): an ACTIVE (proven) violation whose ladder step is referral to
     * investigation or dismissal, or whose signed degree is suspension (6) or dismissal (7), or whose type is flagged
     * meta.serious. Any category qualifies (e.g. 7+ days absence reaching dismissal is as serious as misconduct).
     */
    public static function isSerious(Violation $v): bool
    {
        $meta = Lookup::where('group', 'violation_types')->where('code', $v->type)->value('meta') ?? [];

        return in_array(trim((string) $v->step), ['investigate', 'dismiss'], true) || (int) $v->signed_degree >= 6 || ! empty($meta['serious']);
    }

    /**
     * A penalty still in force (§84 «جزاء نافذ»): an active violation within its type's look-back window
     * (meta.lookback_days, default 180 days) before the given date.
     */
    public static function hasActivePenalty(Employee $e, ?Carbon $at = null): bool
    {
        $at ??= now();

        return Violation::where('employee_id', $e->id)->where('status', 'active')->where('occurred_on', '<=', $at->toDateString())->get(['type', 'occurred_on'])
            ->contains(function ($v) use ($at) {
                $days = (int) (Lookup::where('group', 'violation_types')->where('code', $v->type)->value('meta')['lookback_days'] ?? 180);

                return $v->occurred_on->copy()->addDays($days)->gte($at->copy()->startOfDay());
            });
    }

    /** Add N working days of the employee's week pattern (company weekend) to a date (§75, §76). */
    public static function addWorkDays(Employee $e, Carbon $from, int $n): Carbon
    {
        $d = $from->copy()->startOfDay();
        $guard = 0;
        while ($n > 0 && $guard++ < 400) {
            $d->addDay();
            if (AttendanceService::isWorkDay($e, $d)) {
                $n--;
            }
        }

        return $d->endOfDay();
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
            // §78 boundaries: <4h short; 4–10h inclusive course; >10–30h program; >30h intensive (inclusiveness is lookup meta)
            $a = $acts->first(function ($l) use ($hours) {
                $m = $l->meta ?? [];
                if (! array_key_exists('min_hours', $m) || $m['min_hours'] === null) {
                    return false;
                }
                $lo = ($m['min_inclusive'] ?? true) ? $hours >= $m['min_hours'] : $hours > $m['min_hours'];
                $max = $m['max_hours'] ?? null;
                $hi = $max === null || (($m['max_inclusive'] ?? false) ? $hours <= $max : $hours < $max);

                return $lo && $hi;
            });
        }

        return $a ? ['activity' => $a->code, 'points' => (int) ($a->meta['points'] ?? 0)] : ['activity' => $activity, 'points' => 0];
    }

    /** Whether weekly reports are expected from this employee (§34: the manager enables it per employee, else the company setting). */
    public static function weeklyRequired(Employee $e): bool
    {
        return $e->weekly_report_required ?? (Settings::get('weeklyReportEnabled', $e->company_id, true) !== false);
    }

    /**
     * Due date of a week's report (§34 + owner's note): the direct manager chooses, per employee, the last day of the
     * week or the first day of the following week (employees.weekly_due); otherwise the group / company rule.
     */
    public static function weekDue(WeeklyReport $w): Carbon
    {
        $e = $w->relationLoaded('employee') ? $w->employee : $w->employee()->first();
        $rule = $e?->weekly_due ?: (Settings::get('weeklyDueRule', $e?->company_id, 'next') ?: 'next');

        return $rule === 'end' ? Carbon::parse($w->week_end)->endOfDay() : $w->week_start->copy()->addDays(7)->endOfDay();
    }

    public static function weekDueLabel(?Employee $e): string
    {
        $rule = $e?->weekly_due ?: (Settings::get('weeklyDueRule', $e?->company_id, 'next') ?: 'next');

        return $rule === 'end' ? 'آخر يوم في الأسبوع' : 'أول يوم من الأسبوع التالي';
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
        // §35: an employee's own item the manager declined (mgr_approved === false) does not count
        $items = $rep ? array_values(array_filter($rep->items ?? [], fn ($i) => ! (($i['source'] ?? 'org') === 'emp' && ($i['mgr_approved'] ?? null) === false))) : [];
        if ($rep && count($items)) {
            // §71: manager's rating per item when present (else the employee's), each capped at 100%, weights brought back to 100
            $tw = array_sum(array_map(fn ($i) => (float) ($i['weight'] ?? 0), $items)) ?: 1;
            $pct = array_sum(array_map(fn ($i) => min(100, max(0, (float) ($i['mgr_score'] ?? $i['self_score'] ?? $i['pct'] ?? 0))) * (float) ($i['weight'] ?? 0), $items)) / $tw;
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
            $pendingNotes = $noteReq ? $e->attendance()->where('work_date', 'like', "$period-%")->where('note_pending', true)->count() : 0;
            $dn = $noteReq ? max(0, $sub['attend']['dailyNote'] - $pendingNotes) : $sub['attend']['dailyNote'];
            $att = $presence + $noLate + $dn;
            $parts['attend'] = ['presence' => $r1($presence), 'no_late' => $r1($noLate), 'daily_note' => $r1($dn), 'absent' => $a['absent'], 'late' => $a['late']];
        }

        // development (§78): (points ÷ 100) × 20, up to 30 at 150 points — the surplus compensates the other axes
        $dp = self::devPoints($e, $period);
        $dev = $dp['total'] / 100 * self::BASE['dev'];
        $parts['dev'] = ['points' => $dp['points'], 'carry' => $dp['carry'], 'total' => $dp['total'], 'level' => $dp['level_label'], 'records' => $dp['records'], 'score' => $r1($dev)];

        // discipline: weekly reports on time, cooperation (manager), conduct (no active non-financial violation)
        self::ensureWeeks($e, $period); // weeks nobody opened yet still count as missing
        $ws = self::weeklyStats($e->id, $period);
        $weeklyOn = self::weeklyRequired($e);
        $weekly = $weeklyOn && $ws['total'] ? max(0, $sub['disc']['weekly'] * ($ws['on_time'] + $ws['late'] * 0.5) / $ws['total']) : $sub['disc']['weekly'];
        // §71 cooperation (4): rated by the direct manager (mandatory at the manager's review). Not rated yet: a provisional
        // full value before the manager stage, 0 once the report has passed the manager — both marked pending.
        $mgrPassed = $rep && in_array($rep->status, ['mgr_reviewed', 'hr_reviewed', 'approved'], true);
        $coopPending = $rep?->coop === null;
        $coop = $coopPending ? ($mgrPassed ? 0 : $sub['disc']['coop']) : min($sub['disc']['coop'], max(0, (float) $rep->coop));
        // §71 conduct (2): withheld when an active behavioural violation (conduct / work-instructions category) took effect in the month
        $monthViol = self::monthViolations($e->id, $period);
        $behav = $monthViol->filter(fn ($v) => self::isBehavioural($v));
        $serious = $monthViol->filter(fn ($v) => self::isSerious($v));
        $conduct = $behav->isNotEmpty() ? 0 : $sub['disc']['conduct'];
        $disc = $weekly + $coop + $conduct;
        $parts['disc'] = ['weekly' => $r1($weekly), 'coop' => $r1($coop), 'conduct' => $r1($conduct), 'weeks' => $ws, 'coop_pending' => $coopPending,
            'conduct_violations' => $behav->pluck('id')->values()->all()];

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
        // §35: late submission −3; not submitted by the end of the month −10 (instead of −3) and a red flag. The FIRST
        // submission decides (a report returned and re-submitted keeps its original date).
        $monthEnd = Carbon::parse($period.'-01')->endOfMonth();
        $monthOver = now()->gt($monthEnd);
        $firstAt = $rep && in_array($rep->status, MonthlyReport::SUBMITTED, true) ? ($rep->first_submitted_at ?? $rep->submitted_at) : null;
        $submitted = $firstAt !== null;
        $inMonth = $submitted && $firstAt->lte($monthEnd);
        $missing = $monthOver && ! $inMonth; // nothing by the last day of the month (even if submitted afterwards)
        $penalty = $missing ? (float) Settings::get('reportMissingPenalty', $e->company_id, 10) : ($submitted && $rep->late ? (float) Settings::get('reportLatePenalty', $e->company_id, 3) : 0);
        $parts['report'] = ['submitted' => $submitted, 'first_submitted_at' => $firstAt?->toDateTimeString(), 'late' => (bool) ($rep?->late), 'after_month_end' => $submitted && ! $inMonth, 'penalty' => $penalty, 'red_flag' => $missing];
        $score = max(0, min(100, $total + $bonus - $penalty));
        // §74: monthly colour status with its reasons
        $reasons = [];
        $achPct = $W['achieve'] ? $a1 / $W['achieve'] : 1;
        $attPct = ($a2 === null || ! $W['attend']) ? 1 : $a2 / $W['attend'];
        if ($score < 70) $reasons['red'][] = 'الدرجة أقل من 70';
        if ($achPct < 0.6) $reasons['red'][] = 'أقل من 60% من محور الإنجاز';
        if ($attPct < 0.6) $reasons['red'][] = 'أقل من 60% من محور الالتزام';
        if ($missing) $reasons['red'][] = $submitted ? 'لم يُرفع التقرير الشهري حتى آخر يوم في الشهر' : 'لم يُرفع التقرير الشهري';
        if ($ws['missing'] >= 2) $reasons['red'][] = 'أسبوعان فأكثر دون تقرير';
        if ($serious->isNotEmpty()) $reasons['red'][] = 'مخالفة جسيمة ثابتة';
        if ($score >= 70 && $score < 80) $reasons['orange'][] = 'الدرجة بين 70 و80';
        if ($rep?->late && ! $missing) $reasons['orange'][] = 'تأخر رفع التقرير الشهري';
        if ($ws['missing'] === 1) $reasons['orange'][] = 'أسبوع واحد دون تقرير';
        if ($ws['late'] > 0) $reasons['orange'][] = 'تأخر تقرير أسبوعي';
        if ($dp['total'] < 50 && $monthOver) $reasons['orange'][] = 'نقاط التطوير أقل من 50';
        if (($parts['attend']['late'] ?? 0) >= 3) $reasons['orange'][] = 'تأخر متكرر في الحضور';
        $flag = ['color' => ! empty($reasons['red']) ? 'red' : (! empty($reasons['orange']) ? 'orange' : 'green'), 'reasons' => array_values(array_merge($reasons['red'] ?? [], $reasons['orange'] ?? []))];

        return ['axes' => ['achieve' => $r1($a1), 'attend' => $a2 === null ? null : $r1($a2), 'dev' => $r1($a3), 'disc' => $r1($a4)], 'weights' => $W,
            'applicable' => $applicable, 'earned' => $r1($earned), 'total' => $r1($total), 'bonus' => $bonus, 'penalty' => $penalty, 'score' => round($score, 2), 'flag' => $flag,
            'grade' => self::grade($score, $tpl, $flag['color']), 'exempt_attendance' => $att === null, 'parts' => $parts, 'template' => $tpl->only('id', 'name', 'pass_score', 'version')];
    }

    /** Persist the monthly evaluation (called when a monthly report is approved, or explicitly by HR). */
    public static function publish(Employee $e, string $period, ?int $by = null): Evaluation
    {
        $ev = self::compute($e, $period);
        $row = Evaluation::updateOrCreate(['employee_id' => $e->id, 'period' => $period, 'type' => 'monthly'], [
            'company_id' => $e->company_id, 'axes' => $ev['axes'], 'weights' => $ev['weights'], 'score' => $ev['score'], 'grade' => $ev['grade']['grade'],
            'grade_label' => $ev['grade']['label'], 'details' => $ev['parts'] + ['bonus' => $ev['bonus'], 'penalty' => $ev['penalty'], 'flag' => $ev['flag'], 'exempt_attendance' => $ev['exempt_attendance'], 'pass' => $ev['grade']['pass']],
            'template_id' => $ev['template']['id'], 'published_at' => now(), 'published_by' => $by ?? auth()->id(),
        ]);
        self::devGuidance($e, $period);

        return $row;
    }

    /**
     * §79: a month that ended below 50 development points → the direct manager holds a guidance session and sets the
     * next month's development plan. One notification per employee and month (idempotent).
     */
    public static function devGuidance(Employee $e, string $period): bool
    {
        if (! now()->gt(Carbon::parse($period.'-01')->endOfMonth()) || $e->status === 'terminated') {
            return false;
        }
        $dp = self::devPoints($e, $period);
        if ($dp['total'] >= 50) {
            return false;
        }
        $mgr = $e->manager_id ? \App\Models\User::where('employee_id', $e->manager_id)->where('active', true)->value('id') : null;
        $to = $mgr ? [$mgr] : Access::hrUsersFor($e->company_id, 'DEV');
        $title = 'جلسة توجيه تطويري مطلوبة';
        $body = "{$e->name} — نقاط التطوير في ".Carbon::parse($period.'-01')->format('m/Y')." {$dp['total']} (أقل من 50): اعقد جلسة توجيه وضع خطة تطوير للشهر التالي (§79)";
        if (! $to || \App\Models\AppNotification::whereIn('user_id', $to)->where('title', $title)->where('body', $body)->exists()) {
            return false;
        }
        Audit::notify($to, $title, $body, 'warn', ['screen' => 'development']);

        return true;
    }

    /** Raw verified development points of a whole year, and the gold months (§79 annual recognition). */
    public static function devYear(Employee $e, int $year): array
    {
        $points = 0;
        $gold = 0;
        $months = [];
        $last = $year === (int) now()->year ? (int) now()->month : 12;
        for ($m = 1; $m <= $last; $m++) {
            $p = sprintf('%d-%02d', $year, $m);
            $raw = self::rawDevPoints($e->id, $p);
            $points += $raw;
            if ($raw > 0) { // the carry-over alone (≤ 50) never reaches gold
                $dp = self::devPoints($e, $p);
                $gold += $dp['level'] === 'gold' ? 1 : 0;
            }
            $months[$p] = $raw;
        }

        return ['year' => $year, 'points' => $points, 'gold_months' => $gold, 'qualified' => $points >= 1500 || $gold >= 6, 'months' => $months,
            'rule' => '1,500 نقطة فأكثر في السنة أو المستوى الذهبي 6 أشهر فأكثر (§79)'];
    }

    /** §79 «نجم التطوير»: highest development points of the quarter in each company. */
    public static function devStars($employees, int $year, int $quarter): array
    {
        $periods = array_map(fn ($m) => sprintf('%d-%02d', $year, ($quarter - 1) * 3 + $m), [1, 2, 3]);
        $rows = collect($employees)->map(fn (Employee $e) => ['employee' => $e->only('id', 'name', 'emp_no'), 'company_id' => $e->company_id, 'company' => $e->company?->name,
            'points' => array_sum(array_map(fn ($p) => self::rawDevPoints($e->id, $p), $periods))])->filter(fn ($r) => $r['points'] > 0);
        $out = [];
        foreach ($rows->groupBy('company_id') as $list) {
            $max = $list->max('points');
            foreach ($list->where('points', $max) as $r) {
                $out[] = $r;
            }
        }

        return ['year' => $year, 'quarter' => $quarter, 'periods' => $periods, 'stars' => array_values($out)];
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
        $key = $year.'-'.($kind === 'contract' ? '99' : '00');
        $goalsRow = Evaluation::where('employee_id', $e->id)->where('type', 'goals')->where('period', $key)->first();
        // §73: the goals rating counts only once the company CEO approved it (a manager's proposal is shown, not used)
        $goals = $goalsRow && $goalsRow->grade === 'approved' ? $goalsRow : null;
        $goalsOut = $goalsRow ? $goalsRow->only('score', 'details', 'published_at') + ['status' => $goalsRow->grade, 'approved' => (bool) $goals] : null;
        $series = $rows->map(fn ($r) => ['period' => $r->period, 'score' => $r->score, 'grade' => $r->grade])->values();
        $published = Evaluation::where('employee_id', $e->id)->where('type', 'annual')->where('period', $key)->first();
        $pubOut = $published ? ['id' => $published->id, 'score' => $published->score, 'grade' => $published->grade, 'published_at' => $published->published_at, 'objection_status' => $published->objection_status] : null;
        // §73: fewer than the minimum months still yields a result when the employee meets the §86 bonus service condition (6 months)
        $partial = false;
        if ($rows->isEmpty() || $rows->count() < $min) {
            $svc = $kind === 'contract' && $e->hire_date
                ? BonusService::qualifyingDays($e, $e->hire_date->copy()->year($year), $e->hire_date->copy()->year($year)->addYear()->subDay())
                : BonusService::qualifyingDays($e, Carbon::create($year, 1, 1), Carbon::create($year, 12, 31));
            if ($rows->isEmpty() || $svc['days'] < 182) {
                return $rows->isEmpty() ? null : ['year' => $year, 'kind' => $kind, 'months' => $rows->count(), 'min_months' => $min, 'score' => null, 'grade' => null, 'axes' => [], 'series' => $series, 'excluded' => $excluded, 'insufficient' => true, 'goals' => $goalsOut, 'published' => $pubOut];
            }
            $partial = true;
        }
        $avg = round($rows->avg('score'), 2);
        $score = min(100, $goals ? round($avg * 0.7 + (float) $goals->score * 0.3, 2) : $avg);
        // §76: an accepted objection's corrected score is what counts (and flows into the bonus / raise)
        if ($published && $published->objection_status === 'accepted') {
            $score = (float) $published->score;
        }
        $tpl = self::templateFor($e);

        return ['year' => $year, 'kind' => $kind, 'months' => $rows->count(), 'min_months' => $min, 'partial' => $partial, 'score' => $score, 'monthly_avg' => $avg, 'goals' => $goalsOut, 'grade' => self::grade($score, $tpl),
            'formula' => ($goals ? '70% متوسط الأشهر + 30% الأهداف السنوية' : ($goalsRow ? 'متوسط الأشهر (تقييم الأهداف مقترح ولم يعتمده الرئيس التنفيذي بعد)' : 'متوسط الأشهر (لم تُقيَّم الأهداف السنوية بعد)')).($partial ? ' — من الأشهر المتاحة (أقل من '.$min.')' : ''),
            'excluded' => $excluded, 'goals_final' => (bool) $goals, 'published' => $pubOut,
            'axes' => collect(['achieve', 'attend', 'dev', 'disc'])->mapWithKeys(fn ($k) => [$k => round($rows->avg(fn ($r) => $r->axes[$k] ?? 0), 1)]),
            'series' => $series];
    }

    /** Persist the annual evaluation (type annual, period YYYY-00 fiscal / YYYY-99 contract) so it can be objected to (§76). */
    public static function publishAnnual(Employee $e, int $year, string $kind = 'fiscal', ?int $by = null): ?Evaluation
    {
        $an = self::annual($e, $year, $kind);
        if (! $an || $an['score'] === null) {
            return null;
        }
        $key = $year.'-'.($kind === 'contract' ? '99' : '00');
        $prev = Evaluation::where('employee_id', $e->id)->where('type', 'annual')->where('period', $key)->first();
        $data = ['company_id' => $e->company_id, 'axes' => $an['axes'], 'weights' => [], 'score' => $an['score'], 'grade' => $an['grade']['grade'], 'grade_label' => $an['grade']['label'],
            'details' => ['kind' => $kind, 'months' => $an['months'], 'monthly_avg' => $an['monthly_avg'], 'goals' => $an['goals']['score'] ?? null, 'goals_final' => $an['goals_final'], 'partial' => $an['partial'], 'formula' => $an['formula'], 'excluded' => $an['excluded']]];
        // an objection already decided keeps its corrected score; otherwise republish with a new publication date only if the score changed
        if ($prev && $prev->objection_status !== 'none') {
            return $prev;
        }
        if (! $prev || abs((float) $prev->score - (float) $an['score']) > 0.001) {
            $data += ['published_at' => now(), 'published_by' => $by ?? auth()->id()];
        }

        return Evaluation::updateOrCreate(['employee_id' => $e->id, 'period' => $key, 'type' => 'annual'], $data);
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
        $six = $e->sixDayWeek();
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
                // owner's note: a note in the personal file, the week turns red, and it weighs on the monthly evaluation
                $e->career()->create(['type' => 'تقرير أسبوعي غير مرفوع', 'detail' => "أسبوع {$wk->week_start->format('d/m/Y')} لم يُرفع تقريره في موعده (§34) — يُخصم من محور الانضباط في التقييم الشهري", 'occurred_on' => now()->toDateString()]);
                $wk->update(['noted_at' => now()]);
                $who = array_filter([\App\Models\User::where('employee_id', $e->id)->value('id'), $e->manager_id ? \App\Models\User::where('employee_id', $e->manager_id)->value('id') : null]);
                Audit::notify($who, 'تقرير أسبوعي لم يُرفع: '.$e->name, 'أسبوع '.$wk->week_start->format('d/m/Y').' — سُجّلت ملاحظة في الملف وأثّرت في التقييم الشهري', 'bad', ['screen' => 'reports']);
            }
        }
    }
}
