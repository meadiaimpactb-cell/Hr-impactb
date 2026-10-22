<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Advance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'monthly_amount' => 'float', 'remaining' => 'float', 'schedule' => 'array', 'transferred_on' => 'date:Y-m-d'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }

    /** Amount actually recovered so far: a reduced instalment (§42 half-wage rule) counts only what was deducted. */
    public function recovered(?array $schedule = null): float
    {
        return round(collect($schedule ?? $this->schedule ?? [])->where('status', 'deducted')->sum(fn ($s) => (float) ($s['paid_amount'] ?? $s['amount'])), 2);
    }

    /** Instalments due in a period already past and not yet deducted (§46 monthly reconciliation). */
    public function overdueInstalments(?string $period = null): array
    {
        $period ??= now()->format('Y-m');

        return collect($this->schedule ?? [])->filter(fn ($s) => ($s['status'] ?? '') !== 'deducted' && ($s['period'] ?? '9999') < $period)->values()->all();
    }
}
