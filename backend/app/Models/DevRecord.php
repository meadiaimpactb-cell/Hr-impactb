<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevRecord extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['planned' => 'مخطط', 'pending' => 'بانتظار التحقق', 'verified' => 'موثَّق', 'rejected' => 'مرفوض'];

    protected function casts(): array
    {
        return ['from' => 'date:Y-m-d', 'to' => 'date:Y-m-d', 'hours' => 'float', 'cost' => 'float', 'verified_at' => 'datetime', 'summary_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }
}
