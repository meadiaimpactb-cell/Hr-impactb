<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $guarded = ['id'];

    /** Human stages of the run (§40); the automatic generation is not a human stage. */
    public const HUMAN_STAGES = ['hr_review', 'hr_approved', 'fin_approved', 'approved'];

    protected function casts(): array
    {
        return ['gross' => 'float', 'net' => 'float', 'lines' => 'array', 'locked_at' => 'datetime', 'stage_log' => 'array'];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function payslips() { return $this->hasMany(Payslip::class); }

    /** User ids who already acted on a human stage of this run (§40/§46 separation of duties). */
    public function stageActors(): array
    {
        $ids = [];
        foreach (self::HUMAN_STAGES as $s) {
            if ($this->{$s.'_by'}) {
                $ids[$s] = (int) $this->{$s.'_by'};
            }
        }

        return $ids;
    }

    public function logStage(string $stage, int $userId, ?string $note = null): void
    {
        $log = $this->stage_log ?? [];
        $log[] = array_filter(['stage' => $stage, 'by' => $userId, 'at' => now()->toDateTimeString(), 'note' => $note], fn ($v) => $v !== null);
        $this->stage_log = $log;
    }
}
