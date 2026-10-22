<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvalTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weights' => 'array', 'sub' => 'array', 'grades' => 'array', 'active' => 'boolean', 'effective_from' => 'date:Y-m-d', 'previous' => 'array'];
    }
}
