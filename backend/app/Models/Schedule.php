<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['week_days' => 'array', 'hours' => 'array', 'flex' => 'boolean', 'cross_midnight' => 'boolean', 'break_minutes' => 'integer'];
    }

    public function employees() { return $this->hasMany(Employee::class); }

    public function isWorkDay(int $dow): bool { return in_array($dow, $this->week_days ?? [], true); }

    /** @return array{0:string,1:string}|null */
    public function hoursFor(int $dow): ?array { return $this->hours[(string) $dow] ?? null; }
}
