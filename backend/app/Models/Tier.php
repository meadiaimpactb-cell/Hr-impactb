<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['benefits' => 'array', 'is_default' => 'boolean', 'effective_from' => 'date:Y-m-d'];
    }

    public function companies() { return $this->hasMany(Company::class); }
}
