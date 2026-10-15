<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['link' => 'array', 'read_at' => 'datetime'];
    }
}
