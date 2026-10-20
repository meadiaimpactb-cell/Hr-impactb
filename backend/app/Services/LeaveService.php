<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;

class LeaveService
{
    /** Balance of a leave type for a year, with a full explanation of how it was computed. */
    public static function balance(Employee $e, LeaveType|string $type, ?int $year = null): array
    {
        $lt = $type instanceof LeaveType ? $type : LeaveType::where('code', $type)->firstOrFail();
        $year ??= (int) now()->year;
        $quota = $lt->quota_key ? (float) ($e->benefit($lt->quota_key) ?? ($lt->code === 'annual' ? 21 : 0)) : 0; // 21 days: labour-law default when the package sets none
        if ($lt->code === 'annual' && $e->hire_date && $e->hire_date->diffInYears(Carbon::create($year, 12, 31)) >= 5) {
            // §56: at least 30 calendar days after five continuous years — compared in calendar-day equivalents, so a
            // work-day package (tiers 2–3) is raised only when it is worth less than 30 calendar days
            $quota = self::unit($e) === 'work' ? max($quota, (float) ceil(30 * 5 / 7)) : max($quota, 30);
        }
        if ($lt->code === 'sick') {
            $quota = 120; // §59: 30 days full pay + 60 days at three quarters + 30 days unpaid, per year from the first sick leave
        }
        $factor = $e->contract_type === 'part' ? ($e->part_ratio ?: 0.5) : 1;
        if ($lt->accrual === 'yearly' && $e->hire_date && $e->hire_date->year === $year) {
            $factor *= max(0, 12 - $e->hire_date->month + 1) / 12; // §56: the first year accrues from the hire date
        }

        $accrued = 0.0;
        if ($lt->accrual === 'monthly') {
            $start = $e->hire_date->year < $year ? Carbon::create($year, 1, 1) : $e->hire_date->copy();
            $end = now()->year > $year ? Carbon::create($year, 12, 31) : now();
            $months = max(0, ($end->year - $start->year) * 12 + $end->month - $start->month + ($end->day >= $start->day ? 1 : 0));
            $accrued = round(min(12, $months) * $quota / 12 * $factor, 2);
        } elseif ($lt->accrual === 'yearly') {
            $accrued = round($quota * $factor, 2);
        } elseif ($lt->accrual === 'case') {
            $accrued = $quota;
        }

        $ledger = LeaveLedger::where('employee_id', $e->id)->where('year', $year)->where('leave_type', $lt->code)->get();
        $opening = (float) $ledger->where('entry', 'opening')->sum('days');
        $adjusted = (float) $ledger->where('entry', 'adjust')->sum('days');
        $usedLedger = (float) -$ledger->where('entry', 'used')->sum('days');
        $ledgerRefs = $ledger->pluck('request_id')->filter()->all();

        $reqs = ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->whereIn('status', ['approved', 'pending'])
            ->where('data->type', $lt->code)->where('data->from', 'like', $year.'%')->get(['id', 'status', 'data']);
        $usedLive = $reqs->where('status', 'approved')->whereNotIn('id', $ledgerRefs)->sum(fn ($r) => $r->data['days'] ?? 0);
        $reserved = $reqs->where('status', 'pending')->sum(fn ($r) => $r->data['days'] ?? 0);
        $used = $usedLedger + $usedLive;
        $available = $lt->accrual === 'none' ? 0 : round($opening + $accrued + $adjusted - $used - $reserved, 2);

        $carried = (float) $ledger->where('entry', 'carry')->sum('days');
        $available += $lt->accrual === 'none' ? 0 : $carried;

        return [
            'type' => $lt->code, 'name' => $lt->name, 'accrual' => $lt->accrual, 'quota' => $quota, 'part_factor' => $factor, 'unit' => self::unit($e), 'carried' => $carried,
            'unit_label' => self::unit($e) === 'work' ? 'أيام عمل' : 'أيام تقويمية',
            'opening' => $opening, 'accrued' => $accrued, 'adjusted' => $adjusted, 'used' => $used, 'reserved' => $reserved,
            'available' => $available,
        ];
    }

    public static function balances(Employee $e, ?int $year = null): array
    {
        return LeaveType::where('active', true)->whereNotNull('quota_key')->where('accrual', '!=', 'none')->orderBy('sort')->get()
            ->map(fn ($lt) => self::balance($e, $lt, $year))->all();
    }

    /** Calendar days between two dates (inclusive). */
    /**
     * Leave days as calendar days (labour-office rule): a weekend that falls between two leave days counts
     * (Thursday + Sunday = 4 days), while weekend days at the start or end of the request do not
     * (they are outside the leave). Without an employee the plain calendar span is used.
     */
    /** Unit of the annual balance (§52/§56): calendar days for tier 1, work days for tiers 2 and 3. */
    public static function unit(?Employee $e): string
    {
        return $e && ($e->benefit('leaveUnit') ?? 'calendar') === 'work' ? 'work' : 'calendar';
    }

    public static function days(string $from, string $to, ?Employee $e = null): int
    {
        $a = Carbon::parse($from)->startOfDay();
        $b = Carbon::parse($to)->startOfDay();
        // §58: official holidays (and the company's extra Eid days) inside the leave are not counted — the leave is extended by them
        $hol = $e ? \App\Models\Holiday::datesFor($e, $a->toDateString(), $b->toDateString()) : [];
        if ($e && self::unit($e) === 'work') { // tiers 2–3: only work days are deducted
            $n = 0;
            for ($d = $a->copy(); $d->lte($b); $d->addDay()) {
                $n += $e->isWorkDay($d->dayOfWeek) && ! isset($hol[$d->toDateString()]) ? 1 : 0;
            }

            return $n;
        }
        if ($e) {
            while ($a->lte($b) && ! $e->isWorkDay($a->dayOfWeek)) {
                $a->addDay();
            }
            while ($b->gte($a) && ! $e->isWorkDay($b->dayOfWeek)) {
                $b->subDay();
            }
            if ($a->gt($b)) {
                return 0;
            }
        }
        $inside = count(array_filter(array_keys($hol), fn ($k) => $k >= $a->toDateString() && $k <= $b->toDateString()));

        return max(0, (int) $a->diffInDays($b) + 1 - $inside);
    }

    /**
     * Sets a leave type's available balance and/or used days to the given values for the year. The difference is
     * booked in the leave ledger (adjust / used) with the reason, logged and noted in the career timeline.
     */
    public static function setBalance(Employee $e, LeaveType $lt, int $year, $available, $used, string $reason, ?int $userId = null): array
    {
        $before = self::balance($e, $lt, $year);
        $date = $year === (int) now()->year ? now()->toDateString() : "$year-12-31";
        if ($used !== null && $used !== '' && (float) $used !== (float) $before['used']) {
            $diff = round((float) $used - (float) $before['used'], 2);
            LeaveLedger::create(['employee_id' => $e->id, 'year' => $year, 'leave_type' => $lt->code, 'entry' => 'used', 'days' => -$diff, 'note' => 'تعديل المستهلك يدويًا: '.$reason, 'occurred_on' => $date]);
        }
        $mid = self::balance($e, $lt, $year);
        if ($available !== null && $available !== '' && (float) $available !== (float) $mid['available']) {
            $diff = round((float) $available - (float) $mid['available'], 2);
            LeaveLedger::create(['employee_id' => $e->id, 'year' => $year, 'leave_type' => $lt->code, 'entry' => 'adjust', 'days' => $diff, 'note' => 'تعديل الرصيد يدويًا: '.$reason, 'occurred_on' => $date]);
        }
        $after = self::balance($e, $lt, $year);
        if ($after['available'] != $before['available'] || $after['used'] != $before['used']) {
            Audit::log('employees', $e->id, 'leave_adjust', ['type' => $lt->code, 'available' => $before['available'], 'used' => $before['used']], ['available' => $after['available'], 'used' => $after['used']], $reason);
            $e->career()->create(['type' => 'تعديل رصيد إجازة', 'detail' => "{$lt->name}: المتاح {$before['available']} ← {$after['available']} · المستهلك {$before['used']} ← {$after['used']} — {$reason}", 'occurred_on' => now()->toDateString(), 'user_id' => $userId]);
        }

        return $after;
    }
}
