<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettingDefinition extends Model
{
    protected $guarded = ['id'];

    public const TYPES = ['number', 'bool', 'list', 'json', 'text', 'secret'];

    protected function casts(): array
    {
        return ['builtin' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
