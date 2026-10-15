<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deducts' => 'boolean', 'attachment_required' => 'boolean', 'carry_over' => 'boolean', 'extra_approval' => 'array', 'active' => 'boolean'];
    }
}
