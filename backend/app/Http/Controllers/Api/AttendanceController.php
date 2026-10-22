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
            'week' => AttendanceService::weekly($e->load('schedule', 'company.tier')),
            'records' => $this->records($e, $period),
            'grace' => (int) Settings::get('graceMinutes', $e->company_id, 0),
            'note_required' => $e->daily_note_required || Settings::get('dailyNoteEnabled', $e->company_id, false),
            'note_min' => (int) Settings::get('dailyNoteMin', $e->company_id, 20),
            'note_pending' => $e->attendance()->where('note_pending', true)->where('work_date', '>=', now()->subDays((int) Settings::get('dailyNoteLateDays', $e->company_id, 7))->toDateString())->orderByDesc('work_date')->get(['id', 'work_date', 'note']),
        ];
    }

    /** Today board for managers/HR. */
    public function today(Request $request)
    {
        $today = $request->date('date')?->toDateString() ?? now()->toDateString(); // any day's log; today by default
        $emps = Access::for()->employees('ATT')->where('status', '!=', 'terminated')->where('punch_required', true)
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->with(['company:id,name', 'attendance' => fn ($q) => $q->whereDate('work_date', $today)->with('location:id,name')])
            ->with('schedule', 'company.tier')->orderBy('name')->get();
        $me = $request->user()->id;
        // owner's note (22/10): «طلب تحضير» is offered for whoever is late and has not checked in; its status is shown
        \App\Http\Controllers\Api\PresenceController::expire();
        $isToday = $today === now()->toDateString();
        $asks = \Illuminate\Support\Facades\DB::table('presence_checks')->whereIn('employee_id', $emps->pluck('id'))->where('kind', 'checkin')->whereDate('requested_at', $today)
            ->orderBy('id')->get()->keyBy('employee_id');
        // §28: the movement route is shown to the HR manager and the group CEO, and to a manager the group CEO granted it to
        $rows = $emps->map(fn ($e) => ['employee' => $e->only('id', 'name', 'emp_no') + ['track_viewer' => in_array($me, array_map('intval', (array) ($e->track_viewers ?? [])), true)], 'company' => $e->company?->name,
            'record' => $e->attendance->first(), 'on_leave' => AttendanceService::onLeave($e->id, $today),
            'late_now' => $isToday && ! $e->attendance->first()?->check_in && (bool) \App\Http\Controllers\Api\PresenceController::lateNow($e),
            'checkin_request' => isset($asks[$e->id]) ? ['status' => $asks[$e->id]->status, 'at' => $asks[$e->id]->requested_at, 'due_at' => $asks[$e->id]->due_at] : null]);
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
        $emps = Access::for()->employees('ATT')->where('status', '!=', 'terminated')->where('punch_required', true)
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
        $ids = Access::for()->employees('ATT')->pluck('id');

        return AttendanceRecord::with('employee:id,name,emp_no', 'location:id,name')->whereIn('employee_id', $ids)
            ->where('pending', true)->orderByDesc('work_date')->limit(200)->get();
    }

    /** Multi-select (owner's note): accept, or ask for an excuse on, several records at once with one reason. */
    public function reviewMany(Request $request)
    {
        $d = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer', 'accept' => 'required|boolean', 'reason' => 'required_if:accept,false|nullable|string|max:500']);
        $done = 0;
        $skipped = [];
        foreach (AttendanceRecord::with('employee')->whereIn('id', $d['ids'])->get() as $r) {
            try {
                $this->decideRecord($r, (bool) $d['accept'], $d['reason'] ?? null, $request->user());
                $done++;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $skipped[] = ($r->employee?->name ?? '#'.$r->id).': '.($e->getMessage() ?: 'غير مسموح');
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    public function review(Request $request, AttendanceRecord $record)
    {
        $data = $request->validate(['accept' => 'required|boolean', 'reason' => 'required_if:accept,false|nullable|string|max:500']);

        return $this->decideRecord($record, (bool) $data['accept'], $data['reason'] ?? null, $request->user());
    }

    private function decideRecord(AttendanceRecord $record, bool $accept, ?string $reason, \App\Models\User $u)
    {
        $record->loadMissing('employee');
        // attendance and absence are decided by HR only: the HR manager, or HR staff granted attendance approval
        abort_unless($u->hasRole('hr_mgr', 'super') || ($u->hasRole('hr_staff') && Access::for()->can('ATT', 'approve', ['employee' => $record->employee])), 403, 'قرارات الحضور والغياب صلاحية مدير الموارد البشرية');
        abort_if($record->employee->user?->id === $u->id, 403, 'لا يمكنك مراجعة سجل حضورك');
        abort_if(! $accept && blank($reason), 422, 'سبب الرفض إلزامي');
        $data = ['accept' => $accept, 'reason' => $reason];
        $date = $record->work_date->format('d/m/Y');
        if ($data['accept']) {
            $record->update(['pending' => false, 'status' => $record->status === 'excuse_requested' ? 'present' : $record->status, 'reviewed_by' => $u->id]);
            Audit::log('attendance', $record->id, 'accept', null, null, $data['reason'] ?? null);
            Audit::notify($record->employee->user?->id, 'قُبل سجل حضورك', $date.' '.($data['reason'] ?? ''), 'ok', ['screen' => 'attendance']);

            return $record;
        }
        if ($record->status !== 'excuse_requested') {
            // §27: an outside-range day is counted absent only after the employee has been able to give an excuse
            $days = (int) Settings::get('excuseDays', $record->employee->company_id, 3);
            $due = now();
            for ($i = 0; $i < $days; $i++) {
                $guard = 0;
                do {
                    $due->addDay();
                } while (! AttendanceService::isWorkDay($record->employee, $due) && ++$guard < 14);
            }
            $record->update(['status' => 'excuse_requested', 'pending' => true, 'excuse_due' => $due->endOfDay(), 'reviewed_by' => $u->id]);
            Audit::log('attendance', $record->id, 'excuse_requested', null, null, $data['reason']);
            Audit::notify($record->employee->user?->id, 'مطلوب إبداء عذرك عن حضور '.$date, 'سجلت حضورك من خارج النطاق ولم تقبله الموارد البشرية ('.$data['reason'].'). قدّم عذرك قبل '.$due->format('d/m/Y').' وإلا عُدّ اليوم غيابًا', 'warn', ['screen' => 'attendance']);

            return $record->fresh();
        }
        abort_if(! $record->excuse && $record->excuse_due && now()->lt($record->excuse_due), 422, 'لم تنتهِ مهلة إبداء العذر ولم يقدّم الموظف عذره بعد (حتى '.\Illuminate\Support\Carbon::parse($record->excuse_due)->format('d/m/Y').')');
        $record->update(['pending' => false, 'status' => 'absent', 'reviewed_by' => $u->id]);
        Audit::log('attendance', $record->id, 'reject', null, null, $data['reason']);
        Audit::notify($record->employee->user?->id, 'عُدّ يوم '.$date.' غيابًا', $data['reason'], 'bad', ['screen' => 'attendance']);

        return $record;
    }

    /** §27: the employee gives their excuse for an outside-range day HR did not accept. */
    public function excuse(Request $request, AttendanceRecord $record)
    {
        $e = $request->user()->employee;
        abort_unless($e && $record->employee_id === $e->id, 403);
        abort_unless($record->status === 'excuse_requested', 422, 'لا يوجد طلب عذر قائم على هذا اليوم');
        $d = $request->validate(['excuse' => 'required|string|min:5|max:2000']);
        $record->update(['excuse' => $d['excuse'], 'excuse_at' => now()]);
        Audit::log('attendance', $record->id, 'excuse', null, null, $d['excuse']);
        Audit::notify(Access::hrUsersFor($e->company_id, 'ATT'), 'عذر موظف عن حضور خارج النطاق', $e->name.' — '.$record->work_date->format('d/m/Y'), 'info', ['screen' => 'attendance']);

        return $record;
    }

    /** §27: HR enters days from the alternative (paper) register in bulk when the platform was unavailable. */
    public function paper(Request $request)
    {
        $u = $request->user();
        abort_unless($u->hasRole('super', 'hr_mgr') || ($u->hasRole('hr_staff') && Access::for()->canAny('ATT', 'approve')), 403, 'إدخال السجل البديل للموارد البشرية');
        $d = $request->validate(['rows' => 'required|array|min:1|max:500', 'rows.*.employee_id' => 'required|integer|exists:employees,id', 'rows.*.date' => 'required|date|before_or_equal:today',
            'rows.*.in' => 'nullable|date_format:H:i', 'rows.*.out' => 'nullable|date_format:H:i', 'rows.*.note' => 'nullable|string|max:300']);
        $ids = Access::for()->employees('ATT')->pluck('id')->all();
        $done = [];
        $errors = [];
        foreach ($d['rows'] as $i => $r) {
            $emp = Employee::with('schedule', 'company.tier')->find($r['employee_id']);
            if (! in_array($emp->id, $ids, true) || $emp->user?->id === $u->id) {
                $errors[] = 'السطر '.($i + 1).': الموظف خارج نطاق صلاحيتك';
                continue;
            }
            if (empty($r['in']) && empty($r['out'])) {
                $errors[] = 'السطر '.($i + 1).': أدخل وقت الحضور أو الانصراف';
                continue;
            }
            $done[] = AttendanceService::paperEntry($emp, Carbon::parse($r['date'])->toDateString(), $r['in'] ?? null, $r['out'] ?? null, $r['note'] ?? null)->id;
        }

        return ['saved' => count($done), 'ids' => $done, 'errors' => $errors];
    }

    /** §27: attendance-correction requests in scope, flagged when filed after the 3-working-day window (flag only). */
    public function corrections(Request $request)
    {
        abort_unless(Access::for()->canAny('ATT', 'view'), 403);
        $ids = Access::for()->employees('ATT')->pluck('id');
        $types = \App\Models\RequestType::where('handler', 'correction')->pluck('code')->all() ?: ['correction'];

        return \App\Models\ApprovalRequest::with('employee:id,name,emp_no,company_id')->whereIn('employee_id', $ids)->whereIn('type', $types)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->limit(300)->get()
            ->map(fn ($r) => ['id' => $r->id, 'employee' => $r->employee?->only('id', 'name', 'emp_no'), 'date' => $r->data['date'] ?? null, 'kind' => $r->data['kind'] ?? null,
                'reason' => $r->data['reason'] ?? null, 'status' => $r->status, 'filed_at' => $r->created_at?->toDateTimeString(),
                'late' => isset($r->data['date']) && AttendanceService::correctionLate($r->data['date'], $r->created_at, $r->employee),
                'late_label' => isset($r->data['date']) && AttendanceService::correctionLate($r->data['date'], $r->created_at, $r->employee) ? 'متأخر عن المهلة' : null]);
    }

    /** §29/30: weekly balance — required vs worked hours, flex minutes absorbed and still to be made up, remote day. */
    public function week(Request $request)
    {
        $e = $request->integer('employee_id') ? Employee::findOrFail($request->integer('employee_id')) : $request->user()->employee;
        abort_unless($e, 404);
        abort_unless($e->id === $request->user()->employee_id || Access::for()->can('ATT', 'view', ['employee' => $e]) || Access::for()->isManagerOf($e), 403);

        return AttendanceService::weekly($e->load('schedule', 'company.tier'), $request->date('date') ?? now());
    }

    private function records(Employee $e, string $period)
    {
        $start = Carbon::parse($period.'-01');

        return $e->attendance()->with('location:id,name')->whereBetween('work_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->orderByDesc('work_date')->get();
    }

    /**
     * §33: the employee can rewrite his daily achievement for today (after check-out too); earlier days stay as written.
     * A day left pending for a missing achievement can still be completed within dailyNoteLateDays (owner's note:
     * the day's attendance is approved once the achievement is written).
     */
    public function note(Request $request, \App\Models\AttendanceRecord $record)
    {
        abort_unless($record->employee_id === $request->user()->employee_id, 403);
        $cid = $record->employee?->company_id;
        $late = (int) \App\Services\Settings::get('dailyNoteLateDays', $cid, 7);
        $ok = $record->work_date->isToday() || ($record->note_pending && $record->work_date->gte(now()->subDays($late)->startOfDay()));
        abort_unless($ok, 422, $record->note_pending ? "انتهت مهلة كتابة المنجز ($late أيام)" : 'يُعدَّل المنجز اليومي في اليوم نفسه فقط');
        $min = (int) \App\Services\Settings::get('dailyNoteMin', $cid, 20);
        $d = $request->validate(['note' => "required|string|min:$min|max:2000"], ['note.min' => "اكتب المنجز في $min حرفًا على الأقل (§33)"]);
        $was = $record->note_pending;
        $edited = trim((string) $record->note) !== '';
        $record->update(['note' => $d['note'], 'note_pending' => false]);
        \App\Services\Audit::log('attendance', $record->id, $was ? 'note_completed' : 'note');
        AttendanceService::noteWritten($record->loadMissing('employee'), $edited);

        return $record->fresh();
    }

    /**
     * The daily achievements of the team (manager) or of the scope (HR, group CEO) for a range of days — the full
     * text, plus who still owes one (owner's note, 22/10).
     */
    public function achievements(Request $request)
    {
        $u = $request->user();
        $acc = Access::for();
        $team = $u->employee_id ? \App\Models\Employee::where('manager_id', $u->employee_id)->pluck('id') : collect();
        abort_unless($acc->canAny('ATT', 'view') || $team->isNotEmpty(), 403);
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $from = $request->date('from')?->toDateString() ?? now()->subDays(6)->toDateString();
        $ids = ($acc->canAny('ATT', 'view') ? $acc->employees('ATT')->pluck('id') : collect())->merge($team)->unique();
        if ($c = $request->integer('company_id')) {
            $ids = \App\Models\Employee::whereIn('id', $ids)->where('company_id', $c)->pluck('id');
        }

        return \App\Models\AttendanceRecord::with('employee:id,name,emp_no,company_id,manager_id', 'employee.company:id,name')->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$from, $to])->where(fn ($q) => $q->where(fn ($w) => $w->whereNotNull('note')->where('note', '!=', ''))->orWhere('note_pending', true))
            ->orderByDesc('work_date')->orderBy('employee_id')->limit(1000)->get()
            ->map(fn ($r) => ['id' => $r->id, 'work_date' => $r->work_date->toDateString(), 'check_in' => $r->check_in, 'check_out' => $r->check_out, 'note' => $r->note, 'note_pending' => (bool) $r->note_pending,
                'employee' => $r->employee?->only('id', 'name', 'emp_no'), 'company' => $r->employee?->company?->name])->values();
    }

    /** My days still pending for a missing daily achievement (web + mobile banner). */
    public function notePending(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $late = (int) Settings::get('dailyNoteLateDays', $e->company_id, 7);

        return $e->attendance()->where('note_pending', true)->where('work_date', '>=', now()->subDays($late)->toDateString())->orderByDesc('work_date')->get(['id', 'work_date', 'check_in', 'check_out', 'note'])
            ->map(fn ($r) => $r->toArray() + ['deadline' => $r->work_date->copy()->addDays($late)->toDateString()]);
    }
}
