<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['line' => 'array', 'adj_late' => 'float', 'mgr_approved' => 'boolean', 'hr_approved' => 'boolean',
            'published' => 'boolean', 'published_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function payrollRun() { return $this->belongsTo(PayrollRun::class); }

    /** Deductions total, honouring the manual late-deduction override if set. */
    public function net(): float
    {
        $ded = 0.0;
        foreach ($this->line['deductions'] ?? [] as $d) {
            $ded += ($d['key'] === 'late' && $this->adj_late !== null) ? $this->adj_late : $d['amount'];
        }

        return round(($this->line['gross'] ?? 0) - $ded, 2);
    }

    public function deductionsTotal(): float
    {
        return round(($this->line['gross'] ?? 0) - $this->net(), 2);
    }
}
