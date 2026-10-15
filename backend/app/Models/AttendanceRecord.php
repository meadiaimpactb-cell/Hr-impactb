<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d', 'check_in' => 'datetime', 'check_out' => 'datetime', 'client_time' => 'datetime',
            'offline' => 'boolean', 'geo_fail' => 'boolean', 'low_accuracy' => 'boolean', 'pending' => 'boolean',
            'corrected' => 'boolean', 'note_required' => 'boolean', 'lat' => 'float', 'lng' => 'float',
        ];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function location() { return $this->belongsTo(Location::class); }
}
