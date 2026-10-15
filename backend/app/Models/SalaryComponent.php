<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryComponent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'is_fixed' => 'boolean', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }
}
