<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsurancePolicy extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["classes" => "array", "from" => "date:Y-m-d", "to" => "date:Y-m-d", "cost" => "float", "log" => "array", "active" => "boolean"];
    }

    public function company() { return $this->belongsTo(Company::class); }
}
