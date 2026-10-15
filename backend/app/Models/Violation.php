<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Violation extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['awaiting_statement' => 'بانتظار إفادة الموظف', 'awaiting_approval' => 'بانتظار التوقيع والاعتماد', 'active' => 'نافذة', 'cancelled' => 'ملغاة'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d', 'effective_from' => 'date:Y-m-d', 'amount' => 'float', 'statement_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}
