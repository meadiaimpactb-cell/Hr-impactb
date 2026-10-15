<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobTitle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['responsibilities' => 'array', 'qualifications' => 'array', 'skills' => 'array', 'kpis' => 'array'];
    }

    public function employees() { return $this->hasMany(Employee::class); }
}
