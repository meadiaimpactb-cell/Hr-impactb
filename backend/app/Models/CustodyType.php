<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustodyType extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'requires_ack' => 'boolean', 'has_secret' => 'boolean', 'is_cash' => 'boolean', 'active' => 'boolean'];
    }

    public function items() { return $this->hasMany(CustodyItem::class); }
}
