<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Access;
use App\Services\Audit;
use App\Services\AttendanceService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    /** Employee check-in / check-out from web or mobile. */
    public function punch(Request $request)
    {
        $data = $request->validate([
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'accuracy' => 'nullable|integer|min:0',
            'note' => 'nullable|string|max:2000', 'offline' => 'boolean', 'client_time' => 'nullable|date', 'device' => 'nullable|string|max:190',
        ]);
        $e = $request->user()->employee;
        abort_unless($e, 422, 'الحساب غير مرتبط بموظف');
        $rec = AttendanceService::punch($e->load('schedule', 'locations'), $data);
        if ($request->hasFile('selfie') && Settings::get('selfieEnabled', $e->company_id, false)) {
            $rec->update(['selfie_path' => $request->file('selfie')->store('selfies/'.now()->format('Y/m'), 'local')]);
        }

        return ['record' => $rec, 'geo' => $rec->geo];
    }

    /** My today record + month summary (home screen of the app). */
    public function me(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $period = $request->input('period', now()->format('Y-m'));

        return [
            'today' => $e->attendance()->whereDate('work_date', now()->toDateString())->first(),
            'summary' => AttendanceService::summary($e, $period),
            'records' => $this->records($e, $period),
            'grace' => (int) Settings::get('graceMinutes', $e->company_id, 0),
            'note_required' => $e->daily_note_required || Settings::get('dailyNoteEnabled', $e->company_id, false),
        ];
    }

    /** Today board for managers/HR. */
    public function today(Request $request)
    {
        $today = $request->date('date')?->toDateString() ?? now()->toDateString(); // any day's log; today by default
        $emps = Access::for()->employees()->where('status', '!=', 'terminated')->where('punch_required', true)
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->with(['company:id,name', 'attendance' => fn ($q) => $q->whereDate('work_date', $today)->with('location:id,name')])
            ->orderBy('name')->get(['id', 'name', 'emp_no', 'company_id']);
        $rows = $emps->map(fn ($e) => ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name,
            'record' => $e->attendance->first(), 'on_leave' => AttendanceService::onLeave($e->id, $today)]);
        $grace = (int) Settings::get('graceMinutes', null, 0);

        return [
            'rows' => $rows,
            'stats' => [
                'required' => $rows->count(),
                'present' => $rows->filter(fn ($r) => $r['record']?->check_in)->count(),
                'late' => $rows->filter(fn ($r) => $r['record'] && $r['record']->late_minutes > $grace)->count(),
                'missing' => $rows->filter(fn ($r) => ! $r['record'] && ! $r['on_leave'])->count(),
                'on_leave' => $rows->where('on_leave', true)->count(),
            ],
        ];
    }

    /** Monthly summary for all visible employees. */
    public function month(Request $request)
    {
        $period = $request->input('period', now()->format('Y-m'));
        $emps = Access::for()->employees()->where('status', '!=', 'terminated')->where('punch_required', true)
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->with('company:id,name', 'schedule')->orderBy('name')->get();

        return $emps->map(fn ($e) => ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name, 'summary' => AttendanceService::summary($e, $period)]);
    }

    public function employee(Request $request, Employee $employee)
    {
        abort_unless(Access::for()->can('ATT', 'view', ['employee' => $employee]), 403);
        $period = $request->input('period', now()->format('Y-m'));

        return ['summary' => AttendanceService::summary($employee, $period), 'records' => $this->records($employee, $period)];
    }

    public function pending()
    {
        $ids = Access::for()->employees()->pluck('id');

        return AttendanceRecord::with('employee:id,name,emp_no', 'location:id,name')->whereIn('employee_id', $ids)
            ->where('pending', true)->orderByDesc('work_date')->limit(200)->get();
    }

    public function review(Request $request, AttendanceRecord $record)
    {
        $record->load('employee');
        // attendance and absence are decided by HR only: the HR manager, or HR staff granted attendance approval
        $u = $request->user();
        abort_unless($u->hasRole('hr_mgr') || ($u->hasRole('hr_staff') && Access::for()->can('ATT', 'approve', ['employee' => $record->employee])), 403, 'قرارات الحضور والغياب صلاحية مدير الموارد البشرية');
        abort_if($record->employee->user?->id === $u->id, 403, 'لا يمكنك مراجعة سجل حضورك');
        $data = $request->validate(['accept' => 'required|boolean', 'reason' => 'required_if:accept,false|nullable|string|max:500']);
        $record->update($data['accept'] ? ['pending' => false, 'reviewed_by' => auth()->id()] : ['pending' => false, 'status' => 'absent', 'reviewed_by' => auth()->id()]);
        Audit::log('attendance', $record->id, $data['accept'] ? 'accept' : 'reject', null, null, $data['reason'] ?? null);
        Audit::notify($record->employee->user?->id, $data['accept'] ? 'قُبل سجل حضورك' : 'رُفض سجل حضورك', $record->work_date->toDateString().' '.($data['reason'] ?? ''), $data['accept'] ? 'ok' : 'bad', ['screen' => 'attendance']);

        return $record;
    }

    private function records(Employee $e, string $period)
    {
        $start = Carbon::parse($period.'-01');

        return $e->attendance()->with('location:id,name')->whereBetween('work_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->orderByDesc('work_date')->get();
    }
}
