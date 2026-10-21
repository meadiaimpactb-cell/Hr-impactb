<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Services\Access;
use App\Services\Audit;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeaveController extends Controller
{
    public function types()
    {
        return LeaveType::where('active', true)->orderBy('sort')->get();
    }

    public function myBalances(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return $this->balancesFor($e->load('company.tier'), $request->integer('year') ?: null);
    }

    public function balances(Request $request, Employee $employee)
    {
        abort_unless(Access::for()->can('LVE', 'view', ['employee' => $employee]), 403);

        return $this->balancesFor($employee->load('company.tier'), $request->integer('year') ?: null);
    }

    /** Consolidated balances for all visible employees. */
    public function report(Request $request)
    {
        $types = LeaveType::where('active', true)->whereIn('accrual', ['monthly', 'yearly'])->whereNotNull('quota_key')->orderBy('sort')->get();
        $emps = Access::for()->employees('LVE')->where('status', '!=', 'terminated')->with('company.tier')
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->orderBy('name')->get();

        return [
            'types' => $types->map->only('code', 'name', 'name_en'),
            'rows' => $emps->map(fn ($e) => ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name,
                'balances' => ($b = $types->mapWithKeys(fn ($t) => [$t->code => LeaveService::balance($e, $t)]))->map(fn ($x) => $x['available']),
                'details' => $b->map(fn ($x) => ['entitled' => round((float) ($x['accrued'] ?? 0) + (float) ($x['opening'] ?? 0) + (float) ($x['adjusted'] ?? 0), 2), 'used' => $x['used'], 'reserved' => $x['reserved'] ?? 0, 'available' => $x['available']])]),
        ];
    }

    /** Team calendar of leaves in a month. */
    public function calendar(Request $request)
    {
        $m = Carbon::parse($request->input('month', now()->format('Y-m')).'-01');
        $ids = Access::for()->employees('LVE')->where('status', '!=', 'terminated')->pluck('id');

        return ApprovalRequest::with('employee:id,name')->whereIn('employee_id', $ids)->where('type', 'leave')->whereIn('status', ['pending', 'approved'])
            ->where('data->from', '<=', $m->copy()->endOfMonth()->toDateString())->where('data->to', '>=', $m->toDateString())
            ->get(['id', 'employee_id', 'status', 'data']);
    }

    /**
     * HR uploads actual balances from a sheet (e.g. 5 days left, 10 left, all taken). Each row is matched by employee
     * number, else by exact name, and sets the remaining balance ("available") or the days used. Returns a per-row result.
     */
    public function importBalances(Request $request)
    {
        abort_unless($request->user()->isHr(), 403, 'رفع الأرصدة صلاحية الموارد البشرية');
        $d = $request->validate(['type' => 'required|exists:leave_types,code', 'year' => 'nullable|integer|min:2000|max:2100', 'reason' => 'required|string|min:3|max:300',
            'rows' => 'required|array|max:2000', 'rows.*.emp_no' => 'nullable|string|max:30', 'rows.*.name' => 'nullable|string|max:150',
            'rows.*.available' => 'nullable|numeric|min:-365|max:3650', 'rows.*.used' => 'nullable|numeric|min:0|max:3650']);
        $lt = LeaveType::where('code', $d['type'])->firstOrFail();
        $year = $d['year'] ?? (int) now()->year;
        $scope = Access::for()->employees('LVE')->where('status', '!=', 'terminated')->with('company.tier', 'schedule')->get();
        $out = [];
        foreach ($d['rows'] as $i => $row) {
            $e = ! empty($row['emp_no']) ? $scope->first(fn ($x) => (string) $x->emp_no === trim((string) $row['emp_no'])) : null;
            $e ??= ! empty($row['name']) ? $scope->first(fn ($x) => trim($x->name) === trim((string) $row['name'])) : null;
            $label = $row['name'] ?? $row['emp_no'] ?? ('صف '.($i + 1));
            if (! $e) {
                $out[] = ['row' => $label, 'ok' => false, 'msg' => 'لم يُعثر على الموظف'];

                continue;
            }
            if (($row['available'] ?? null) === null && ($row['used'] ?? null) === null) {
                $out[] = ['row' => $e->name, 'ok' => false, 'msg' => 'لا رصيد ولا مستهلك في الصف'];

                continue;
            }
            $before = LeaveService::balance($e, $lt, $year)['available'];
            $after = LeaveService::setBalance($e, $lt, $year, $row['available'] ?? null, isset($row['available']) ? null : ($row['used'] ?? null), $d['reason'], $request->user()->id);
            $out[] = ['row' => $e->name, 'ok' => true, 'msg' => "{$before} ← {$after['available']}"];
        }

        return ['done' => collect($out)->where('ok', true)->count(), 'failed' => collect($out)->where('ok', false)->count(), 'results' => $out];
    }

    /** One balance edited from the balances table. */
    public function setBalance(Request $request, Employee $employee)
    {
        abort_unless($request->user()->isHr() && Access::for()->can('LVE', 'edit', ['employee' => $employee]), 403, 'تعديل الأرصدة صلاحية الموارد البشرية');
        $d = $request->validate(['type' => 'required|exists:leave_types,code', 'available' => 'nullable|numeric|min:-365|max:3650', 'used' => 'nullable|numeric|min:0|max:3650', 'reason' => 'nullable|string|max:300']);
        $lt = LeaveType::where('code', $d['type'])->firstOrFail();

        return LeaveService::setBalance($employee->load('company.tier', 'schedule'), $lt, (int) now()->year, $d['available'] ?? null, $d['used'] ?? null, $d['reason'] ?: 'تعديل مباشر من جدول الأرصدة', $request->user()->id);
    }

    // ---------- leaves taken before the system went live (opening balances) ----------
    private const PAST = 'إجازة سابقة قبل تشغيل النظام';

    public function pastIndex(Request $request)
    {
        abort_unless($request->user()->isHr(), 403);
        $ids = Access::for()->employees('LVE')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->pluck('id');

        return LeaveLedger::with('employee:id,name,emp_no')->whereIn('employee_id', $ids)->where('entry', 'used')->whereNull('request_id')
            ->where('note', 'like', self::PAST.'%')->orderByDesc('occurred_on')->limit(500)->get()
            ->map(fn ($l) => $l->toArray() + ['type_name' => LeaveType::where('code', $l->leave_type)->value('name')]);
    }

    /** HR records a leave taken before go-live: deducted from the balance of the year it was taken in. */
    public function pastStore(Request $request)
    {
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'type' => 'required|exists:leave_types,code',
            'from' => 'required|date', 'to' => 'required|date|after_or_equal:from', 'days' => 'nullable|numeric|min:0.5|max:365', 'note' => 'nullable|string|max:300']);
        $e = Employee::with('schedule')->findOrFail($d['employee_id']);
        abort_unless($request->user()->isHr() && Access::for()->can('LVE', 'edit', ['employee' => $e]), 403, 'إدخال الإجازات السابقة صلاحية الموارد البشرية');
        $days = (float) ($d['days'] ?? LeaveService::days($d['from'], $d['to'], $e));
        abort_if($days <= 0, 422, 'عدد الأيام صفر: الفترة كلها أيام عطلة، اكتب العدد يدويًا');
        $overlap = LeaveLedger::where('employee_id', $e->id)->where('entry', 'used')->where('note', 'like', '%'.$d['from'].' → '.$d['to'].'%')->exists();
        abort_if($overlap, 422, 'هذه الإجازة مسجلة من قبل');
        $l = LeaveLedger::create(['employee_id' => $e->id, 'year' => (int) substr($d['from'], 0, 4), 'leave_type' => $d['type'], 'entry' => 'used', 'days' => -$days,
            'note' => self::PAST.': '.$d['from'].' → '.$d['to'].(! empty($d['note']) ? ' — '.$d['note'] : ''), 'occurred_on' => $d['from']]);
        $lt = LeaveType::where('code', $d['type'])->first();
        $e->addCareer('إجازة سابقة', "{$lt->name} {$days} يوم ({$d['from']} → {$d['to']})");
        Audit::log('leave_ledger', $l->id, 'past_leave', null, $l->toArray());

        return ['entry' => $l->load('employee:id,name,emp_no'), 'balance' => LeaveService::balance($e, $lt, $l->year)];
    }

    public function pastDestroy(Request $request, LeaveLedger $entry)
    {
        abort_unless($request->user()->isHr() && $entry->entry === 'used' && ! $entry->request_id && str_starts_with((string) $entry->note, self::PAST), 403);
        Audit::log('leave_ledger', $entry->id, 'past_leave_delete', $entry->toArray());
        $entry->delete();

        return ['ok' => true];
    }

    private function balancesFor(Employee $e, ?int $year = null): array
    {
        $year ??= (int) now()->year;

        return [
            'year' => $year,
            // next year's balance too, so leave already booked for it is visible (it is deducted from that year's pool)
            'next_year' => \App\Models\ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->whereIn('status', ['approved', 'pending'])->where('data->from', 'like', ($year + 1).'%')->exists()
                ? ['year' => $year + 1, 'balances' => LeaveService::balances($e, $year + 1)] : null,
            'balances' => LeaveService::balances($e, $year),
            'ledger' => LeaveLedger::where('employee_id', $e->id)->where('year', $year)->orderByDesc('occurred_on')->get(),
            'work_days' => array_values(array_filter(range(0, 6), fn ($d) => $e->isWorkDay($d))), // for counting days in the request form
        ];
    }
}
