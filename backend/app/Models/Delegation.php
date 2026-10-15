<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delegation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ops' => 'array', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }

    public function fromUser() { return $this->belongsTo(User::class, 'from_user_id'); }
    public function toUser() { return $this->belongsTo(User::class, 'to_user_id'); }

    public function scopeActive($q)
    {
        $today = now()->toDateString();

        return $q->where('status', 'active')->whereDate('valid_from', '<=', $today)->whereDate('valid_to', '>=', $today);
    }
}
