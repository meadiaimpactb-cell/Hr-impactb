<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalAction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function actingFor() { return $this->belongsTo(User::class, 'acting_for_user_id'); }
}
