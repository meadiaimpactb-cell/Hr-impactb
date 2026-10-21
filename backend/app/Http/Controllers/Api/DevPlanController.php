<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * §77: an individual development plan per employee per year, agreed with the direct manager at the start of the year —
 * the gaps to close, the target tracks, and at least one development activity a month with how it will be applied.
 */
class DevPlanController extends Controller
{
    private function row($p, ?Employee $e = null): array
    {
        return ['id' => $p->id, 'employee_id' => $p->employee_id, 'employee' => $e?->only('id', 'name', 'emp_no'), 'year' => $p->year, 'gaps' => $p->gaps,
            'tracks' => json_decode($p->tracks ?? '[]', true) ?: [], 'status' => $p->status, 'manager_note' => $p->manager_note, 'approved_at' => $p->approved_at,
            'status_label' => ['draft' => 'مسودة', 'submitted' => 'بانتظار اعتماد المشرف', 'approved' => 'معتمدة', 'returned' => 'معادة للتعديل'][$p->status] ?? $p->status];
    }

    /** The employee's plan of a year (empty draft when none yet). */
    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $year = $request->integer('year') ?: (int) now()->year;
        $p = DB::table('dev_plans')->where('employee_id', $e->id)->where('year', $year)->first();

        return $p ? $this->row($p, $e) : ['id' => null, 'employee_id' => $e->id, 'year' => $year, 'gaps' => null, 'tracks' => [], 'status' => 'none', 'status_label' => 'لم تُعد بعد'];
    }

    /** Plans of my team (direct reports) or, for HR, of my scope — with who has none yet. */
    public function team(Request $request)
    {
        $u = $request->user();
        $year = $request->integer('year') ?: (int) now()->year;
        $emps = $u->isHr() ? Access::for()->employees('DEV')->where('status', '!=', 'terminated')->get(['id', 'name', 'emp_no'])
            : Employee::where('manager_id', $u->employee_id)->where('status', '!=', 'terminated')->get(['id', 'name', 'emp_no']);
        $plans = DB::table('dev_plans')->where('year', $year)->whereIn('employee_id', $emps->pluck('id'))->get()->keyBy('employee_id');

        return $emps->map(fn ($e) => isset($plans[$e->id]) ? $this->row($plans[$e->id], $e) : ['id' => null, 'employee' => $e->only('id', 'name', 'emp_no'), 'year' => $year, 'status' => 'none', 'status_label' => 'لم تُعد بعد', 'tracks' => []])->values();
    }

    public function save(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $d = $request->validate(['year' => 'required|integer|min:2024|max:2100', 'gaps' => 'required|string|max:3000', 'submit' => 'boolean',
            'tracks' => 'required|array|min:1|max:24', 'tracks.*.title' => 'required|string|max:200', 'tracks.*.month' => 'nullable|string|max:20', 'tracks.*.apply' => 'nullable|string|max:500'],
            ['tracks.required' => 'أضف نشاطًا تطويريًا واحدًا على الأقل', 'gaps.required' => 'اكتب الفجوات أو المهارات المستهدفة']);
        $cur = DB::table('dev_plans')->where('employee_id', $e->id)->where('year', $d['year'])->first();
        abort_if($cur && $cur->status === 'approved', 422, 'الخطة معتمدة — اطلب من مشرفك إعادتها للتعديل');
        $status = ! empty($d['submit']) ? 'submitted' : 'draft';
        DB::table('dev_plans')->updateOrInsert(['employee_id' => $e->id, 'year' => $d['year']],
            ['gaps' => $d['gaps'], 'tracks' => json_encode(array_values($d['tracks']), JSON_UNESCAPED_UNICODE), 'status' => $status, 'updated_at' => now(), 'created_at' => $cur->created_at ?? now()]);
        if ($status === 'submitted') {
            $mgr = $e->manager_id ? User::where('employee_id', $e->manager_id)->where('active', true)->value('id') : null;
            Audit::notify($mgr ? [$mgr] : Access::hrUsersFor($e->company_id, 'DEV'), 'خطة تطوير سنوية بانتظار اعتمادك', $e->name.' — '.$d['year'], 'approval', ['screen' => 'development']);
        }

        return $this->mine($request->merge(['year' => $d['year']]));
    }

    /** The direct manager (or HR) approves the plan or returns it with a note. */
    public function decide(Request $request, int $plan)
    {
        $p = DB::table('dev_plans')->find($plan);
        abort_unless($p, 404);
        $e = Employee::findOrFail($p->employee_id);
        $u = $request->user();
        abort_unless($e->manager_id === $u->employee_id || ($u->isHr() && Access::for()->canSeeEmployee($e)), 403);
        abort_if($u->employee_id === $e->id, 403, 'لا يعتمد أحد خطته بنفسه');
        $d = $request->validate(['action' => 'required|in:approve,return', 'note' => 'nullable|string|max:1000']);
        abort_if($d['action'] === 'return' && empty($d['note']), 422, 'سبب الإعادة إلزامي');
        DB::table('dev_plans')->where('id', $plan)->update(['status' => $d['action'] === 'approve' ? 'approved' : 'returned', 'manager_note' => $d['note'] ?? null,
            'approved_by' => $d['action'] === 'approve' ? $u->id : null, 'approved_at' => $d['action'] === 'approve' ? now() : null, 'updated_at' => now()]);
        Audit::log('dev_plans', $plan, $d['action'], null, null, $d['note'] ?? null);
        Audit::notify(User::where('employee_id', $e->id)->value('id'), $d['action'] === 'approve' ? 'اعتُمدت خطة تطويرك السنوية' : 'أُعيدت خطة تطويرك للتعديل', $d['note'] ?? '', $d['action'] === 'approve' ? 'ok' : 'warn', ['screen' => 'development']);

        return $this->row(DB::table('dev_plans')->find($plan), $e);
    }
}
