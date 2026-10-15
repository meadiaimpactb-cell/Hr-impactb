<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareerEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d'];
    }

    public function user() { return $this->belongsTo(User::class); }
}
