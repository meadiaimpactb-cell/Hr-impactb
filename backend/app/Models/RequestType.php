<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestType extends Model
{
    protected $guarded = ['id'];

    /** Field types a custom request form may use. */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'bool', 'employee', 'lookup'];

    protected function casts(): array
    {
        return ['flow' => 'array', 'form' => 'array', 'roles' => 'array', 'self_service' => 'boolean', 'on_behalf' => 'boolean', 'active' => 'boolean', 'builtin' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
