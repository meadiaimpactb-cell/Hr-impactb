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
        $factor = $e->contract_type === 'part' ? ($e->part_ratio ?: 0.5) : 1;

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

        return [
            'type' => $lt->code, 'name' => $lt->name, 'accrual' => $lt->accrual, 'quota' => $quota, 'part_factor' => $factor,
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
    public static function days(string $from, string $to, ?Employee $e = null): int
    {
        $a = Carbon::parse($from)->startOfDay();
        $b = Carbon::parse($to)->startOfDay();
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

        return (int) $a->diffInDays($b) + 1;
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
