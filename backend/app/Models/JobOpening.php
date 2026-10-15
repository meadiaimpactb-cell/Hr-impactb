<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOpening extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["public" => "boolean"];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function jobTitle() { return $this->belongsTo(JobTitle::class); }
    public function applications() { return $this->hasMany(Application::class, "opening_id"); }
}
