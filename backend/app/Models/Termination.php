<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Termination extends Model
{
    protected $guarded = ['id'];

    public const STAGES = ['mgr' => 'المدير المباشر', 'hr' => 'الموارد البشرية', 'ceo' => 'رئيس الشركة', 'fin' => 'المدير المالي'];

    public const STATUSES = ['open' => 'قائمة', 'settled' => 'مسوّاة — بانتظار الصرف', 'paid' => 'مصروفة', 'cancelled' => 'ملغاة'];

    protected function casts(): array
    {
        return ['requested_at' => 'date:Y-m-d', 'last_working_day' => 'date:Y-m-d', 'clearance' => 'array', 'eosb' => 'array', 'settlement' => 'array', 'approvals' => 'array',
            'eosb_adjusted' => 'float', 'paid_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
}
