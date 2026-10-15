<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Obstacle extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["due_date" => "date:Y-m-d"];
    }

    public function project() { return $this->belongsTo(Project::class); }
    public function owner() { return $this->belongsTo(Employee::class, "owner_employee_id"); }
}
