<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["members" => "array", "weeks" => "array"];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function owner() { return $this->belongsTo(Employee::class, "owner_employee_id"); }
    public function obstacles() { return $this->hasMany(Obstacle::class); }
}
