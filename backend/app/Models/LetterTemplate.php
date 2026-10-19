<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LetterTemplate extends Model
{
    protected $guarded = ["id"];

    public function company() { return $this->belongsTo(Company::class); }

    protected function casts(): array
    {
        return ["includes_salary" => "boolean", "active" => "boolean"];
    }
}
