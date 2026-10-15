<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveLedger extends Model
{
    protected $table = 'leave_ledger';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['days' => 'float', 'occurred_on' => 'date:Y-m-d'];
    }
}
