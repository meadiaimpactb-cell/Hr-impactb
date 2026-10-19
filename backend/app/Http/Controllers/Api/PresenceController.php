<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access;
use App\Services\AttendanceService;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * §28 — presence checks and location tracking.
 *  - The direct manager (or HR) sends a presence check at any time during the working day; the employee confirms from
 *    the app with their location within the deadline (30 min). No answer → the day is flagged "left without permission",
 *    the check-in is put on review and the employee is asked to check in again.
 *  - While checked in, the app reports its position every few minutes; only the HR manager and the group CEO see the
 *    movement, and the group CEO may grant (or withdraw) the view to the direct manager for a given employee.
 */
class PresenceController extends Controller
{
    // ---------- presence checks ----------
    public function request(Request $request, Employee $employee)
    {
        $u = $request->user();
        abort_unless(Settings::get('presenceCheckEnabled', $employee->company_id, true), 422, 'خاصية طلب التحضير غير مفعّلة');
        abort_unless(Access::for()->isManagerOf($employee) || ($u->isHr() && Access::for()->can('ATT', 'approve', ['employee' => $employee])) || $u->hasRole('super') || Access::for()->isCeoOf($employee->company_id), 403, 'طلب التحضير للمشرف المباشر أو الموارد البشرية');
        $open = DB::table('presence_checks')->where('employee_id', $employee->id)->where('status', 'pending')->where('due_at', '>', now())->exists();
        abort_if($open, 422, 'يوجد طلب تحضير قائم لهذا الموظف لم تنتهِ مهلته بعد');
        $minutes = (int) Settings::get('presenceCheckMinutes', $employee->company_id, 30);
        $id = DB::table('presence_checks')->insertGetId(['employee_id' => $employee->id, 'company_id' => $employee->company_id, 'requested_by' => $u->id, 'requested_at' => now(), 'due_at' => now()->addMinutes($minutes), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('presence_checks', $id, 'request', null, ['employee' => $employee->id]);
        Audit::notify(User::where('employee_id', $employee->id)->value('id'), 'طلب تحضير — أكّد تواجدك الآن', "طلب {$u->name} تأكيد تواجدك في موقع العمل خلال $minutes دقيقة، من التطبيق", 'warn', ['screen' => 'presence'], null);

        return ['id' => $id, 'due_at' => now()->addMinutes($minutes)->toDateTimeString(), 'minutes' => $minutes];
    }

    /** The employee's open check (the app shows the confirmation dialog). */
    public function pending(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        self::expire($e->id);
        $c = DB::table('presence_checks')->where('employee_id', $e->id)->where('status', 'pending')->orderByDesc('id')->first();

        return ['check' => $c ? ['id' => $c->id, 'requested_at' => $c->requested_at, 'due_at' => $c->due_at, 'by' => User::where('id', $c->requested_by)->value('name')] : null,
            'tracking' => (bool) Settings::get('locationTrackingEnabled', $e->company_id, false), 'track_interval' => (int) Settings::get('trackIntervalMinutes', $e->company_id, 10)];
    }

    public function confirm(Request $request, int $check)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $c = DB::table('presence_checks')->where('id', $check)->where('employee_id', $e->id)->first();
        abort_unless($c, 404);
        self::expire($e->id);
        $c = DB::table('presence_checks')->where('id', $check)->first();
        abort_unless($c->status === 'pending', 422, 'انتهت مهلة طلب التحضير — سجّل حضورك من جديد');
        $d = $request->validate(['lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'accuracy' => 'nullable|integer|min:0']);
        $geo = AttendanceService::geoCheck($e->load('locations'), isset($d['lat']) ? (float) $d['lat'] : null, isset($d['lng']) ? (float) $d['lng'] : null, isset($d['accuracy']) ? (int) $d['accuracy'] : null);
        DB::table('presence_checks')->where('id', $check)->update(['responded_at' => now(), 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null, 'accuracy' => $d['accuracy'] ?? null,
            'distance_m' => $geo['dist'], 'inside' => $geo['ok'], 'status' => 'confirmed', 'note' => $geo['msg'], 'updated_at' => now()]);
        $this->ping($e, $d, $geo);
        Audit::notify($c->requested_by, ($geo['ok'] ? 'أكّد تواجده داخل النطاق' : 'أكّد تواجده خارج النطاق').' — '.$e->name, $geo['msg'].' · '.now()->format('H:i'), $geo['ok'] ? 'ok' : 'warn', ['screen' => 'attendance']);

        return ['ok' => true, 'inside' => $geo['ok'], 'msg' => $geo['msg']];
    }

    /** Checks of the employees in scope (today by default). */
    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('ATT', 'view'), 403);
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $ids = Access::for()->employees()->pluck('id');
        foreach ($ids as $id) {
            self::expire($id);
        }
        $names = User::pluck('name', 'id');

        return DB::table('presence_checks')->join('employees', 'employees.id', '=', 'presence_checks.employee_id')->whereIn('presence_checks.employee_id', $ids)->whereDate('requested_at', $date)
            ->orderByDesc('presence_checks.id')->get(['presence_checks.*', 'employees.name as employee_name', 'employees.emp_no'])
            ->map(fn ($c) => (array) $c + ['requested_by_name' => $names[$c->requested_by] ?? '', 'status_label' => ['pending' => 'بانتظار التأكيد', 'confirmed' => 'أكّد', 'missed' => 'لم يستجب'][$c->status] ?? $c->status]);
    }

    /** Overdue checks → missed: the day goes to review, HR and the employee are told, and the employee must check in again (§28/§99). */
    public static function expire(?int $employeeId = null): int
    {
        $q = DB::table('presence_checks')->where('status', 'pending')->where('due_at', '<', now());
        if ($employeeId) {
            $q->where('employee_id', $employeeId);
        }
        $n = 0;
        foreach ($q->get() as $c) {
            DB::table('presence_checks')->where('id', $c->id)->update(['status' => 'missed', 'note' => 'لم يستجب خلال المهلة', 'updated_at' => now()]);
            $e = Employee::find($c->employee_id);
            if (! $e) {
                continue;
            }
            $rec = AttendanceRecord::where('employee_id', $e->id)->whereDate('work_date', $c->requested_at)->first();
            if ($rec && ! $rec->check_out) {
                $rec->update(['pending' => true, 'note' => trim(($rec->note ?? '')."\n[طلب تحضير بلا استجابة ".\Illuminate\Support\Carbon::parse($c->requested_at)->format('H:i').' — حضور اليوم للمراجعة، مطلوب إعادة التحضير]')]);
            }
            Audit::notify(User::where('employee_id', $e->id)->value('id'), 'لم تستجب لطلب التحضير — أعد تسجيل حضورك', 'عُدّ حضورك اليوم معلّقًا للمراجعة (مغادرة دون إذن §28). سجّل حضورك الآن من التطبيق', 'bad', ['screen' => 'attendance']);
            Audit::notify(array_values(array_unique([$c->requested_by, ...Access::hrUsersFor($e->company_id, 'ATT')])), 'لم يستجب لطلب التحضير', $e->name.' — حضور اليوم للمراجعة', 'warn', ['screen' => 'attendance']);
            Audit::log('presence_checks', $c->id, 'missed', null, ['employee' => $e->id]);
            $n++;
        }

        return $n;
    }

    // ---------- location tracking ----------
    /** The app reports its position while the employee is checked in and not yet checked out. */
    public function track(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        abort_unless(Settings::get('locationTrackingEnabled', $e->company_id, false), 422, 'تسجيل الموقع غير مفعّل');
        $d = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180', 'accuracy' => 'nullable|integer|min:0']);
        $rec = AttendanceRecord::where('employee_id', $e->id)->whereDate('work_date', now()->toDateString())->first();
        if (! $rec || ! $rec->check_in || $rec->check_out) {
            return ['ok' => false, 'reason' => 'outside_work']; // §28: nothing is recorded after check-out or outside working time
        }
        $geo = AttendanceService::geoCheck($e->load('locations'), (float) $d['lat'], (float) $d['lng'], isset($d['accuracy']) ? (int) $d['accuracy'] : null);
        $this->ping($e, $d, $geo);

        return ['ok' => true, 'inside' => $geo['ok']];
    }

    private function ping(Employee $e, array $d, array $geo): void
    {
        if (! isset($d['lat'], $d['lng'])) {
            return;
        }
        DB::table('location_pings')->insert(['employee_id' => $e->id, 'work_date' => now()->toDateString(), 'at' => now(), 'lat' => $d['lat'], 'lng' => $d['lng'], 'accuracy' => $d['accuracy'] ?? null,
            'distance_m' => $geo['dist'], 'inside' => $geo['ok'], 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Movement of a day — HR manager, group CEO, or a manager the group CEO allowed for this employee (§28, §116). */
    public function movement(Request $request, Employee $employee)
    {
        $u = $request->user();
        $allowed = $u->hasRole('super') || $u->hasRole('hr_mgr') || in_array($u->id, array_map('intval', (array) ($employee->track_viewers ?? [])), true);
        abort_unless($allowed, 403, 'بيانات الموقع سرية: للرئيس التنفيذي للمجموعة ومدير الموارد البشرية، أو لمن مُنح الاطلاع');
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        Audit::log('location_pings', $employee->id, 'view', null, ['date' => $date]);
        $pings = DB::table('location_pings')->where('employee_id', $employee->id)->where('work_date', $date)->orderBy('at')->get(['at', 'lat', 'lng', 'accuracy', 'distance_m', 'inside']);
        $fence = AttendanceService::fenceFor($employee)->filter(fn ($l) => $l->scope !== 'open')->map(fn ($l) => ['name' => $l->name, 'lat' => (float) $l->lat, 'lng' => (float) $l->lng, 'radius' => (int) $l->radius])->values();

        return ['date' => $date, 'pings' => $pings, 'fence' => $fence, 'outside' => $pings->where('inside', false)->count(), 'viewers' => $employee->track_viewers ?? [],
            'can_grant' => $u->hasRole('super'), 'manager_user_id' => $employee->manager_id ? User::where('employee_id', $employee->manager_id)->value('id') : null, 'manager_name' => $employee->manager?->name];
    }

    /** Group CEO grants or withdraws the movement view for the employee's direct manager (§28). */
    public function viewers(Request $request, Employee $employee)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'منح الاطلاع على الموقع صلاحية الرئيس التنفيذي للمجموعة وحده');
        $d = $request->validate(['user_id' => 'required|exists:users,id', 'grant' => 'required|boolean']);
        $cur = array_map('intval', (array) ($employee->track_viewers ?? []));
        $cur = $d['grant'] ? array_values(array_unique([...$cur, (int) $d['user_id']])) : array_values(array_diff($cur, [(int) $d['user_id']]));
        $employee->update(['track_viewers' => $cur ?: null]);
        Audit::log('employees', $employee->id, $d['grant'] ? 'track_grant' : 'track_revoke', null, ['user_id' => $d['user_id']]);

        return ['viewers' => $cur];
    }
}
