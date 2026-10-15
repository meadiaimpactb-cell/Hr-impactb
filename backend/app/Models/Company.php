<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_holding' => 'boolean'];
    }

    public function tier() { return $this->belongsTo(Tier::class); }
    public function ceo() { return $this->belongsTo(Employee::class, 'ceo_employee_id'); }
    public function employees() { return $this->hasMany(Employee::class); }
}
