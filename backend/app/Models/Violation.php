<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Violation extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['awaiting_statement' => 'بانتظار إفادة الموظف', 'awaiting_recommendation' => 'بانتظار توصية الموارد البشرية', 'awaiting_approval' => 'بانتظار اعتماد الرئيس التنفيذي', 'active' => 'نافذة', 'cancelled' => 'ملغاة'];

    public const OBJECTIONS = ['none' => '—', 'open' => 'قيد نظر اللجنة', 'proposed' => 'قرار اللجنة بانتظار اعتماد الرئيس التنفيذي للمجموعة', 'accepted' => 'قُبل', 'rejected' => 'رُفض'];

    /** Fine step → amount on the daily basic wage (§98): "25%" of a day, "2d" days; warn/dismiss/investigate/- carry no fine. */
    public static function stepAmount(?string $step, float $dailyBasic): float
    {
        $step = trim((string) $step);
        if (preg_match('/^(\d+(?:\.\d+)?)%$/', $step, $m)) {
            return round($dailyBasic * (float) $m[1] / 100, 2);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)d$/', $step, $m)) {
            return round($dailyBasic * (float) $m[1], 2);
        }

        return 0.0;
    }

    public static function stepLabel(?string $step): string
    {
        $step = trim((string) $step);

        return match (true) {
            $step === 'warn' => 'إنذار', $step === 'dismiss' => 'فصل مع المكافأة وفق الضوابط', $step === 'investigate' => 'إحالة للتحقيق والإجراء النظامي', $step === '-' || $step === '' => '—',
            (bool) preg_match('/^(\d+(?:\.\d+)?)%$/', $step, $m) => "غرامة {$m[1]}% من الأجر الأساسي اليومي",
            (bool) preg_match('/^(\d+(?:\.\d+)?)d$/', $step, $m) => 'غرامة أجر '.($m[1] == 1 ? 'يوم' : ($m[1] == 2 ? 'يومين' : $m[1].' أيام')),
            default => $step,
        };
    }

    /** Degree implied by a step (§96): warn → 2 (إنذار كتابي), fine → 4, dismiss → 7, investigate → 3 (pending the inquiry). */
    public static function stepDegree(?string $step): int
    {
        $step = trim((string) $step);

        return match (true) { $step === 'warn' => 2, $step === 'dismiss' => 7, $step === 'investigate' => 3, $step === '-' || $step === '' => 3, default => 4 };
    }

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d', 'discovered_on' => 'date:Y-m-d', 'investigation_done_on' => 'date:Y-m-d', 'effective_from' => 'date:Y-m-d', 'deprivation_until' => 'date:Y-m-d',
            'notified_at' => 'datetime', 'removal_at' => 'datetime', 'objection_at' => 'datetime', 'committee' => 'array', 'suspension_days' => 'integer', 'deprivation_months' => 'integer',
            'amount' => 'float', 'basis' => 'float', 'operational_impact' => 'boolean', 'statement_at' => 'datetime'];
    }

    /** Degree codes with their own data (§96): 5 = deprivation of raise / promotion (≤ 12 months), 6 = suspension without pay (≤ 5 days a month). */
    public const DEG_DEPRIVATION = 5;

    public const DEG_SUSPENSION = 6;

    /**
     * §96 degree 5: is a deprivation of raise / promotion currently in force for this employee? Promotion and raise logic
     * should call this before granting either (EvaluationService / raise tables).
     */
    public static function activeDeprivation(int $employeeId, ?string $on = null): bool
    {
        return self::deprivationFor($employeeId, $on) !== null;
    }

    /** The deprivation in force on a date (or null). */
    public static function deprivationFor(int $employeeId, ?string $on = null): ?self
    {
        $on ??= now()->toDateString();

        return self::where('employee_id', $employeeId)->where('status', 'active')->where('signed_degree', self::DEG_DEPRIVATION)
            ->where('effective_from', '<=', $on)->where('deprivation_until', '>=', $on)->orderByDesc('deprivation_until')->first();
    }

    /** §97: suspension days already signed for an employee in a month (period YYYY-MM), excluding one violation. */
    public static function suspensionDaysIn(int $employeeId, string $period, ?int $exceptId = null): int
    {
        return (int) self::where('employee_id', $employeeId)->whereIn('status', ['active', 'awaiting_approval'])->where('signed_degree', self::DEG_SUSPENSION)
            ->where('period', $period)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->sum('suspension_days');
    }

    /** Adds N working days of the employee's schedule to a date (§95: the defence period counts working days). */
    public static function addWorkDays(Employee $e, \Carbon\CarbonInterface $from, int $days): \Illuminate\Support\Carbon
    {
        $d = \Illuminate\Support\Carbon::parse($from)->startOfDay();
        $guard = 0;
        while ($days > 0 && $guard++ < 60) {
            $d->addDay();
            if (\App\Services\AttendanceService::isWorkDay($e, $d)) {
                $days--;
            }
        }

        return $d->endOfDay();
    }

    /** §95: the employee's defence deadline — at least 2 working days (setting violationStatementDays, counted in working days). */
    public function statementDeadline(): \Illuminate\Support\Carbon
    {
        $days = max(2, (int) \App\Services\Settings::get('violationStatementDays', $this->company_id, 3));

        return self::addWorkDays($this->employee, $this->created_at, $days);
    }

    /** §105: the date the decision was notified (falls back to approval time for rows before the column existed). */
    public function notifiedDate(): ?\Illuminate\Support\Carbon
    {
        return $this->notified_at ?? ($this->status === 'active' ? $this->updated_at : null);
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
    public function investigator() { return $this->belongsTo(User::class, 'investigator_id'); }
}
