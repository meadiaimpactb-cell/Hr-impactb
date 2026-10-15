<?php

namespace App\Services;

use App\Models\DevRecord;
use App\Models\Employee;
use App\Models\EvalTemplate;
use App\Models\Evaluation;
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

        // development: completion (report flag or verified records in the month), interaction, application, HR score
        $d = $rep?->dev ?? [];
        $devRecs = DevRecord::where('employee_id', $e->id)->where('status', 'verified')->where(fn ($q) => $q->where('to', 'like', "$period-%")->orWhere('from', 'like', "$period-%"))->count();
        $complete = (! empty($d['completed']) || $devRecs) ? $sub['dev']['complete'] : 0;
        $interact = min($sub['dev']['interact'], (float) ($d['interaction'] ?? 0));
        $apply = ! empty($d['applied']) ? $sub['dev']['apply'] : 0;
        $hrS = min($sub['dev']['hrScore'], (float) ($d['hr_score'] ?? 0));
        $dev = $complete + $interact + $apply + $hrS;
        $parts['dev'] = ['complete' => $r1($complete), 'interact' => $r1($interact), 'apply' => $r1($apply), 'hr_score' => $r1($hrS), 'records' => $devRecs];

        // discipline: weekly reports on time, cooperation (manager), conduct (no active non-financial violation)
        $ws = self::weeklyStats($e->id, $period);
        $weeklyOn = Settings::get('weeklyReportEnabled', $e->company_id, true) !== false;
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
        $score = min(100, $total + $bonus);

        return ['axes' => ['achieve' => $r1($a1), 'attend' => $a2 === null ? null : $r1($a2), 'dev' => $r1($a3), 'disc' => $r1($a4)], 'weights' => $W,
            'applicable' => $applicable, 'earned' => $r1($earned), 'total' => $r1($total), 'bonus' => $bonus, 'score' => round($score, 2),
            'grade' => self::grade($score, $tpl), 'exempt_attendance' => $att === null, 'parts' => $parts, 'template' => $tpl->only('id', 'name', 'pass_score', 'version')];
    }

    /** Persist the monthly evaluation (called when a monthly report is approved, or explicitly by HR). */
    public static function publish(Employee $e, string $period, ?int $by = null): Evaluation
    {
        $ev = self::compute($e, $period);

        return Evaluation::updateOrCreate(['employee_id' => $e->id, 'period' => $period, 'type' => 'monthly'], [
            'company_id' => $e->company_id, 'axes' => $ev['axes'], 'weights' => $ev['weights'], 'score' => $ev['score'], 'grade' => $ev['grade']['grade'],
            'grade_label' => $ev['grade']['label'], 'details' => $ev['parts'] + ['bonus' => $ev['bonus'], 'exempt_attendance' => $ev['exempt_attendance']],
            'template_id' => $ev['template']['id'], 'published_at' => now(), 'published_by' => $by ?? auth()->id(),
        ]);
    }

    /** Annual = mean of published monthly evaluations of the year. */
    public static function annual(Employee $e, int $year): ?array
    {
        $rows = Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->where('period', 'like', "$year-%")->orderBy('period')->get();
        $min = (int) Settings::get('annualMinMonths', $e->company_id, 6);
        if ($rows->isEmpty() || $rows->count() < $min) {
            return $rows->isEmpty() ? null : ['year' => $year, 'months' => $rows->count(), 'min_months' => $min, 'score' => null, 'grade' => null, 'axes' => [], 'series' => $rows->map(fn ($r) => ['period' => $r->period, 'score' => $r->score, 'grade' => $r->grade])->values(), 'insufficient' => true];
        }
        $score = round($rows->avg('score'), 2);
        $tpl = self::templateFor($e);

        return ['year' => $year, 'months' => $rows->count(), 'score' => $score, 'grade' => self::grade($score, $tpl),
            'axes' => collect(['achieve', 'attend', 'dev', 'disc'])->mapWithKeys(fn ($k) => [$k => round($rows->avg(fn ($r) => $r->axes[$k] ?? 0), 1)]),
            'series' => $rows->map(fn ($r) => ['period' => $r->period, 'score' => $r->score, 'grade' => $r->grade])->values()];
    }

    /** Ensure weekly report rows exist for a month (missing until submitted), for employees in scope. */
    public static function ensureWeeks(Employee $e, string $period): void
    {
        $start = Carbon::parse($period.'-01');
        $end = $start->copy()->endOfMonth();
        $hire = Carbon::parse($e->hire_date);
        for ($w = $start->copy()->startOfWeek(Carbon::SUNDAY); $w->lte($end); $w->addWeek()) {
            if ($w->lt($start) || $w->lt($hire) || $w->gt(now())) {
                continue;
            }
            WeeklyReport::firstOrCreate(['employee_id' => $e->id, 'week_start' => $w->toDateString()], ['week_end' => $w->copy()->addDays(4)->toDateString(), 'status' => 'missing']);
        }
    }
}
