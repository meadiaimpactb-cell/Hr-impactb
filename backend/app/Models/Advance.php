<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Advance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'monthly_amount' => 'float', 'remaining' => 'float', 'schedule' => 'array'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
}
