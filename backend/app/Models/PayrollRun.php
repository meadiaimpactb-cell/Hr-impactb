<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gross' => 'float', 'net' => 'float', 'lines' => 'array', 'locked_at' => 'datetime'];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function payslips() { return $this->hasMany(Payslip::class); }
}
