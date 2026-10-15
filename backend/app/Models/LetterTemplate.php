<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LetterTemplate extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["includes_salary" => "boolean", "active" => "boolean"];
    }
}
