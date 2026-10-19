<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Violation extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['awaiting_statement' => 'بانتظار إفادة الموظف', 'awaiting_recommendation' => 'بانتظار توصية الموارد البشرية', 'awaiting_approval' => 'بانتظار اعتماد الرئيس التنفيذي', 'active' => 'نافذة', 'cancelled' => 'ملغاة'];

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
        return ['occurred_on' => 'date:Y-m-d', 'effective_from' => 'date:Y-m-d', 'amount' => 'float', 'basis' => 'float', 'operational_impact' => 'boolean', 'statement_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}
