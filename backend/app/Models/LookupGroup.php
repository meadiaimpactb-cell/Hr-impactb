<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LookupGroup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta_fields' => 'array', 'builtin' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function items() { return $this->hasMany(Lookup::class, 'group', 'code'); }
}
