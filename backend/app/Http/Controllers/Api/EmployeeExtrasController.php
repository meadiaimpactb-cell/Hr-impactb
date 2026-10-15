<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Services\Access;
use App\Services\Audit;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Direct edits from the employee page: leave balances, career events, next employee number. */
class EmployeeExtrasController extends Controller
{
    private function canEdit(Employee $e): void
    {
        abort_unless(request()->user()->isHr() && Access::for()->can('EMP', 'edit', ['employee' => $e]), 403, 'تعديل ملف الموظف صلاحية الموارد البشرية');
    }

    /**
     * Sets a leave type's balance and/or used days to the given values for the year.
     * The difference is booked in the leave ledger (adjust / used) with the reason, so history stays traceable.
     */
    public function adjustLeave(Request $request, Employee $employee)
    {
        $this->canEdit($employee);
        $d = $request->validate([
            'type' => ['required', Rule::exists('leave_types', 'code')], 'year' => 'nullable|integer|min:2000|max:2100',
            'available' => 'nullable|numeric|min:-365|max:3650', 'used' => 'nullable|numeric|min:0|max:3650', 'reason' => 'required|string|min:3|max:300',
        ]);
        $year = $d['year'] ?? (int) now()->year;
        $lt = LeaveType::where('code', $d['type'])->firstOrFail();
        $after = LeaveService::setBalance($employee, $lt, $year, $d['available'] ?? null, $d['used'] ?? null, $d['reason'], $request->user()->id);

        return ['balance' => $after, 'balances' => LeaveService::balances($employee, $year)];
    }

    /** Leave ledger lines of a type for the year (history behind the numbers). */
    public function leaveLedger(Request $request, Employee $employee)
    {
        abort_unless(Access::for()->canSeeEmployee($employee), 403);

        return LeaveLedger::where('employee_id', $employee->id)->where('year', $request->integer('year') ?: now()->year)
            ->when($request->input('type'), fn ($q, $t) => $q->where('leave_type', $t))->orderByDesc('occurred_on')->orderByDesc('id')->get();
    }

    /** Records a promotion / transfer / title change in the career timeline. */
    public function addCareer(Request $request, Employee $employee)
    {
        $this->canEdit($employee);
        $d = $request->validate([
            'type' => 'required|string|max:60', 'from' => 'nullable|string|max:120', 'to' => 'nullable|string|max:120',
            'occurred_on' => 'required|date', 'note' => 'nullable|string|max:500', 'job_title_id' => 'nullable|exists:job_titles,id',
        ]);
        $move = ($d['from'] ?? null) && ($d['to'] ?? null) ? $d['from'].' ← '.$d['to'] : ($d['to'] ?? $d['from'] ?? null);
        $detail = implode(' — ', array_filter([$move, $d['note'] ?? null]));
        $ev = $employee->career()->create(['type' => $d['type'], 'detail' => $detail ?: null, 'occurred_on' => $d['occurred_on'], 'user_id' => $request->user()->id]);
        if (! empty($d['job_title_id'])) { // a promotion can also move the employee to the new title
            $employee->update(['job_title_id' => $d['job_title_id']]);
        }
        Audit::log('employees', $employee->id, 'career_add', null, $ev->toArray());

        return $ev->load('user:id,name');
    }

    public function deleteCareer(Request $request, Employee $employee, int $event)
    {
        $this->canEdit($employee);
        $ev = $employee->career()->whereKey($event)->firstOrFail();
        Audit::log('employees', $employee->id, 'career_delete', $ev->toArray());
        $ev->delete();

        return ['ok' => true];
    }

    /**
     * Next free employee number for a company: the company's highest numeric number + 1
     * (each company keeps its own range, e.g. 3xxx for Irth Craft), else the group's highest + 1.
     */
    public function nextNumber(Request $request)
    {
        abort_unless($request->user()->isHr(), 403);
        $cid = $request->integer('company_id') ?: null;

        return ['emp_no' => self::next($cid)];
    }

    public static function next(?int $companyId): string
    {
        $nums = fn ($q) => $q->withTrashed()->pluck('emp_no')->filter(fn ($n) => ctype_digit((string) $n))->map(fn ($n) => (int) $n);
        $own = $companyId ? $nums(Employee::where('company_id', $companyId)) : collect();
        $all = $nums(Employee::query());
        $n = ($own->max() ?? $all->max() ?? 999) + 1;
        while (Employee::withTrashed()->where('emp_no', (string) $n)->exists()) {
            $n++;
        }

        return (string) $n;
    }
}
