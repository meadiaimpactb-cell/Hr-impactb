<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['axes' => 'array', 'weights' => 'array', 'details' => 'array', 'score' => 'float', 'published_at' => 'datetime', 'objection_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
}
