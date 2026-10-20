<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Incentive;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\BonusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** §88: incentives & praise register (next to the violations register), excellence points and the honour board. */
class IncentiveController extends Controller
{
    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $period = $request->input('period', now()->format('Y-m'));

        return ['records' => Incentive::with('giver:id,name')->where('employee_id', $e->id)->orderByDesc('id')->get()->map(fn ($i) => $this->row($i)),
            'excellence' => BonusService::excellencePoints($e, $period), 'kinds' => Incentive::KINDS];
    }

    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('PRF', 'view'), 403);
        $ids = Access::for()->employees()->pluck('id');

        return Incentive::with('employee:id,name,emp_no,company_id', 'employee.company:id,name', 'giver:id,name')->whereIn('employee_id', $ids)
            ->when($request->input('period'), fn ($q, $p) => $q->where('period', $p))->orderByDesc('id')->limit(300)->get()->map(fn ($i) => $this->row($i));
    }

    public function forEmployee(Request $request, Employee $employee)
    {
        abort_unless($employee->id === $request->user()->employee_id || Access::for()->canSeeEmployee($employee), 403);

        return ['records' => Incentive::with('giver:id,name')->where('employee_id', $employee->id)->orderByDesc('id')->get()->map(fn ($i) => $this->row($i)),
            'excellence' => BonusService::excellencePoints($employee, $request->input('period', now()->format('Y-m')))];
    }

    /** Manager / HR / CEO record praise, thank-you letters, certificates and honours (bonuses come through the approved request). */
    public function store(Request $request)
    {
        $u = $request->user();
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'kind' => ['required', Rule::in(array_keys(Incentive::KINDS))], 'title' => 'required|string|max:200',
            'detail' => 'nullable|string|max:2000', 'points' => 'nullable|integer|min:0|max:100', 'period' => 'nullable|date_format:Y-m']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(Access::for()->isManagerOf($e) || Access::for()->can('PRF', 'approve', ['employee' => $e]) || $u->hasRole('super'), 403);
        abort_if($e->id === $u->employee_id, 422, 'لا يمكن تسجيل إشادة لنفسك');
        abort_if($d['kind'] === 'bonus', 422, 'المكافأة الفورية تُقدَّم طلبًا (مكافأة إنجاز فورية) وتُسجَّل هنا بعد اعتمادها');
        // §88: the points are fixed by the policy table, not by the giver — a documented praise (manager / management /
        // external body) = 20; other register entries (instant bonus, development star) carry no excellence points.
        unset($d['points']);
        $points = in_array($d['kind'], BonusService::PRAISE_KINDS, true) ? 20 : 0;
        $inc = Incentive::create($d + ['company_id' => $e->company_id, 'points' => $points, 'period' => $d['period'] ?? now()->format('Y-m'), 'given_by' => $u->id]);
        $e->addCareer('إشادة / تقدير', Incentive::KINDS[$inc->kind].' — '.$inc->title);
        Audit::log('incentives', $inc->id, 'create', null, $inc->only('kind', 'title', 'points'));
        Audit::notify(User::where('employee_id', $e->id)->value('id'), Incentive::KINDS[$inc->kind].' جديدة في سجلك', $inc->title, 'ok', ['screen' => 'incentives']);

        return response()->json($this->row($inc->load('employee:id,name,emp_no', 'giver:id,name')), 201);
    }

    public function destroy(Request $request, Incentive $incentive)
    {
        abort_unless($request->user()->isHr() || $request->user()->hasRole('super'), 403);
        abort_if($incentive->request_id, 422, 'سجل المكافأة المعتمدة لا يُحذف');
        $incentive->delete();
        Audit::log('incentives', $incentive->id, 'delete', $incentive->toArray());

        return ['ok' => true];
    }

    /** §88: honour board — excellence points of the period for everyone in scope. */
    public function board(Request $request)
    {
        $period = $request->input('period', now()->format('Y-m'));
        $emps = Access::for()->employees()->where('status', '!=', 'terminated')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->with('company:id,name')->get();
        $rows = $emps->map(fn ($e) => ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name, 'points' => BonusService::excellencePoints($e, $period)])
            ->sortByDesc(fn ($r) => $r['points']['total'])->values();

        return ['period' => $period, 'rows' => $rows->take(50)];
    }

    private function row(Incentive $i): array
    {
        return $i->only('id', 'kind', 'title', 'detail', 'amount', 'points', 'period', 'paid', 'request_id', 'created_at') + ['kind_label' => Incentive::KINDS[$i->kind] ?? $i->kind,
            'by' => $i->relationLoaded('giver') ? $i->giver?->name : null, 'employee' => $i->relationLoaded('employee') ? $i->employee?->only('id', 'name', 'emp_no') : null,
            'company' => $i->relationLoaded('employee') && $i->employee?->relationLoaded('company') ? $i->employee->company?->name : null];
    }
}
