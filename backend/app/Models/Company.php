<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_holding' => 'boolean'];
    }

    /** Public URL of the company logo (served by the /brand route), versioned so a new upload replaces the cached one. */
    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return url('/brand/'.basename($this->logo_path)).'?v='.($this->updated_at?->timestamp ?? 0);
    }

    public function tier() { return $this->belongsTo(Tier::class); }
    public function ceo() { return $this->belongsTo(Employee::class, 'ceo_employee_id'); }
    public function employees() { return $this->hasMany(Employee::class); }
}
