<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Employee extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const CONTRACTS = ['full' => 'دوام كامل', 'part' => 'دوام جزئي', 'collab' => 'متعاون بالمهام'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date:Y-m-d', 'birth_date' => 'date:Y-m-d', 'last_working_day' => 'date:Y-m-d', 'track_viewers' => 'array',
            'punch_required' => 'boolean', 'punch_anywhere' => 'boolean', 'geo_tolerance' => 'integer', 'email_enabled' => 'boolean', 'eosb_saudi' => 'boolean',
            'flex_enabled' => 'boolean', 'daily_note_required' => 'boolean', 'advances_enabled' => 'boolean',
            'custom_benefits' => 'array', 'insurance' => 'array', 'service_companies' => 'array', 'service_allocation' => 'array', 'part_ratio' => 'float', 'work_hours' => 'array',
        ];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function jobTitle() { return $this->belongsTo(JobTitle::class); }
    public function manager() { return $this->belongsTo(Employee::class, 'manager_id'); }
    public function reports() { return $this->hasMany(Employee::class, 'manager_id'); }
    public function schedule() { return $this->belongsTo(Schedule::class); }
    public function locations() { return $this->belongsToMany(Location::class); }
    public function components() { return $this->hasMany(SalaryComponent::class); }
    public function career() { return $this->hasMany(CareerEvent::class)->orderByDesc('occurred_on')->orderByDesc('id'); }
    public function attendance() { return $this->hasMany(AttendanceRecord::class); }
    public function user() { return $this->hasOne(User::class); }

    public function scopeActive($q) { return $q->where('status', '!=', 'terminated'); }

    // ---- working time: the employee's own hours (work_hours, keyed by day of week) win over the shared schedule
    public function hasCustomHours(): bool { return ! empty($this->work_hours); }

    /** @return array{0:string,1:string}|null */
    public function hoursFor(int $dow): ?array
    {
        if ($this->hasCustomHours()) {
            $h = $this->work_hours[(string) $dow] ?? null;

            return is_array($h) && count($h) === 2 ? array_values($h) : null;
        }

        return $this->schedule?->hoursFor($dow);
    }

    public function isWorkDay(int $dow): bool
    {
        if ($this->hasCustomHours()) {
            return $this->hoursFor($dow) !== null;
        }

        return $this->schedule ? $this->schedule->isWorkDay($dow) : $dow < 5;
    }

    /** A shift whose end time is earlier than its start crosses midnight (e.g. 22:00 → 06:00). */
    public function crossesMidnight(int $dow): bool
    {
        if ($this->hasCustomHours()) {
            $h = $this->hoursFor($dow);

            return $h !== null && $h[1] < $h[0];
        }

        return (bool) $this->schedule?->cross_midnight;
    }

    /** Benefit value: employee-specific exception first, then company tier. */
    /** §63: insurance class by job level (1 CEO · 2 leaders · 3–4 managers/specialists · 5 operations) and the company tier. */
    public function insuranceClass(): ?string
    {
        $key = match ((int) $this->job_level) { 1 => 'insuranceCeo', 2 => 'insuranceLeaders', 3, 4 => 'insuranceMgrs', default => 'insuranceClass' };

        return $this->benefit($key) ?? $this->benefit('insuranceClass');
    }

    public function benefit(string $key): mixed
    {
        if (is_array($this->custom_benefits) && array_key_exists($key, $this->custom_benefits)) {
            return $this->custom_benefits[$key];
        }

        return $this->company?->tier?->benefits[$key] ?? null;
    }

    /** Salary components active in a given period (Y-m). */
    public function activeComponents(?string $period = null)
    {
        $period ??= now()->format('Y-m');
        $start = Carbon::parse($period.'-01');
        $end = $start->copy()->endOfMonth();

        return $this->components->filter(fn ($c) => (! $c->valid_from || $c->valid_from->lte($end)) && (! $c->valid_to || $c->valid_to->gte($start)))->values();
    }

    public function monthlyGross(?string $period = null): float
    {
        return round($this->activeComponents($period)->sum('amount'), 2);
    }

    public function componentAmount(string $key, ?string $period = null): float
    {
        return (float) ($this->activeComponents($period)->where('key', $key)->last()?->amount ?? 0);
    }

    public function addCareer(string $type, string $detail, ?int $userId = null): void
    {
        $this->career()->create(['type' => $type, 'detail' => $detail, 'occurred_on' => now()->toDateString(), 'user_id' => $userId ?? auth()->id()]);
    }
}
