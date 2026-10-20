<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["history" => "array", "interviewers" => "array", "offer" => "array"];
    }

    public function opening() { return $this->belongsTo(JobOpening::class, "opening_id"); }
    public function company() { return $this->belongsTo(Company::class); }
    public function jobTitle() { return $this->belongsTo(JobTitle::class); }
}
