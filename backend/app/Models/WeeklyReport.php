<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['week_start' => 'date:Y-m-d', 'week_end' => 'date:Y-m-d', 'submitted_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
}
