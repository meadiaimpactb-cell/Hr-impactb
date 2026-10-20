<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const ROLES = [
        'super' => 'الرئيس التنفيذي للمجموعة', 'hr_mgr' => 'مدير الموارد البشرية', 'hr_staff' => 'موظف موارد بشرية',
        'ceo' => 'رئيس شركة', 'mgr' => 'مدير مباشر', 'fin' => 'المدير المالي', 'custodian' => 'مسؤول عهد/تقنية', 'board' => 'رئيس مجلس الإدارة', 'emp' => 'موظف',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'invite_token', 'invite_secret', 'photo_path'];

    protected $appends = ['photo_url'];

    /** Public URL of the profile photo (served by the /photo route under an unguessable name). */
    public function getPhotoUrlAttribute(): ?string
    {
        $p = $this->attributes['photo_path'] ?? null;

        return $p ? url('/photo/'.basename($p)) : null;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'password' => 'hashed',
            'invite_expires_at' => 'datetime', 'invited_at' => 'datetime', 'invite_accepted_at' => 'datetime',
            'roles' => 'array', 'perms' => 'array', 'hr_scope' => 'array', 'active' => 'boolean', 'must_change_password' => 'boolean',
        ];
    }

    public function employee() { return $this->belongsTo(Employee::class); }

    public function hasRole(string ...$roles): bool
    {
        return (bool) array_intersect($roles, $this->roles ?? []);
    }

    public function isHr(): bool { return $this->hasRole('super', 'hr_mgr', 'hr_staff'); }
}
