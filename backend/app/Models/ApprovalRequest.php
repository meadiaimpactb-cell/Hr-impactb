<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'flow' => 'array', 'decided_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function actions() { return $this->hasMany(ApprovalAction::class)->orderBy('id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
