<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyReport extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['draft' => 'مسودة', 'submitted' => 'مرفوع — بانتظار المدير', 'mgr_reviewed' => 'راجعه المدير — بانتظار الموارد البشرية',
        'hr_reviewed' => 'راجعته الموارد البشرية — بانتظار الرئيس', 'approved' => 'معتمد', 'returned' => 'مُرجَع للتعديل'];

    protected function casts(): array
    {
        return ['items' => 'array', 'dev' => 'array', 'quality' => 'float', 'coop' => 'float', 'hr_bonus' => 'float', 'late' => 'boolean',
            'submitted_at' => 'datetime', 'reopen_until' => 'date:Y-m-d', 'mgr_at' => 'datetime', 'hr_at' => 'datetime', 'ceo_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function template() { return $this->belongsTo(ReportTemplate::class, 'template_id'); }
}
