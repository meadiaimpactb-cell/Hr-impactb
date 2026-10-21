<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\InsurancePolicy;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Medical insurance policies per company; members are employees (class + dependents from tier, overridable). */
class InsuranceController extends Controller
{
    public function index(Request $request)
    {
        $acc = Access::for();
        abort_unless($acc->canAny('EMP', 'view'), 403);
        $cids = $acc->companyIds('EMP');
        $policies = InsurancePolicy::with('company:id,name')->whereIn('company_id', $cids)->orderByDesc('to')->get();
        $emps = $acc->employees('EMP')->where('status', '!=', 'terminated')->with('company.tier')->get();

        return [
            'policies' => $policies->map(fn ($p) => $this->row($p)),
            'members' => $emps->map(fn ($e) => ['employee' => $e->only('id', 'name', 'emp_no'), 'company_id' => $e->company_id, 'company' => $e->company?->name,
                'tier_class' => $e->insuranceClass(), 'tier_dependents' => $e->benefit('dependents'), 'insurance' => $e->insurance ?? null])->values(),
            'classes' => Lookup::grouped()['insurance_classes'] ?? [],
        ];
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $e->load('company.tier');
        $p = ! empty($e->insurance['policy_id']) ? InsurancePolicy::find($e->insurance['policy_id']) : null;

        return ['insurance' => $e->insurance, 'tier_class' => $e->insuranceClass(), 'tier_dependents' => $e->benefit('dependents'), 'policy' => $p ? $this->row($p) : null];
    }

    public function save(Request $request, ?InsurancePolicy $policy = null)
    {
        abort_unless($request->user()->isHr(), 403);
        $d = $request->validate(['company_id' => 'required|exists:companies,id', 'provider' => 'required|string|max:120', 'policy_no' => 'required|string|max:80', 'classes' => 'required|array|min:1',
            'classes.*' => Rule::in(array_column(Lookup::grouped()['insurance_classes'] ?? [], 'code')), 'from' => 'required|date', 'to' => 'required|date|after:from', 'cost' => 'nullable|numeric|min:0', 'owner_user_id' => 'nullable|exists:users,id', 'active' => 'boolean']);
        abort_unless(Access::for()->can('EMP', 'edit', ['company_id' => $d['company_id']]), 403);
        $before = $policy?->toArray();
        $policy ? $policy->update($d) : $policy = InsurancePolicy::create($d + ['log' => []]);
        Audit::log('insurance_policies', $policy->id, $before ? 'update' : 'create', $before, $policy->toArray());

        return $this->row($policy->load('company:id,name'));
    }

    /** Add / update / remove an employee's membership. */
    public function member(Request $request, Employee $employee)
    {
        abort_unless($request->user()->isHr() && Access::for()->can('EMP', 'edit', ['employee' => $employee]), 403);
        $d = $request->validate(['action' => 'required|in:set,remove', 'policy_id' => 'nullable|exists:insurance_policies,id', 'class' => 'nullable|string|max:5', 'dependents' => 'nullable|integer|min:0|max:20', 'card' => 'nullable|string|max:60']);
        $old = $employee->insurance;
        if ($d['action'] === 'remove') {
            $employee->update(['insurance' => null]);
            $ev = 'إلغاء';
        } else {
            $employee->update(['insurance' => ['policy_id' => $d['policy_id'] ?? null, 'class' => $d['class'] ?? $employee->insuranceClass(), 'dependents' => $d['dependents'] ?? $employee->benefit('dependents'), 'card' => $d['card'] ?? null, 'since' => now()->toDateString()]]);
            $ev = $old ? 'تعديل' : 'إضافة';
        }
        foreach (array_unique(array_filter([$old['policy_id'] ?? null, $d['policy_id'] ?? null])) as $pid) {
            $p = InsurancePolicy::find($pid);
            $p?->update(['log' => [...($p->log ?? []), ['ev' => $ev, 'employee' => $employee->name, 'at' => now()->toDateTimeString(), 'by' => $request->user()->name]]]);
        }
        Audit::log('employees', $employee->id, 'insurance_'.$d['action'], $old, $employee->insurance);
        Audit::notify(User::where('employee_id', $employee->id)->value('id'), 'تحديث تأمينك الطبي', $ev.($d['class'] ?? '' ? ' — الفئة '.$d['class'] : ''), 'info', ['screen' => 'insurance']);

        return ['insurance' => $employee->insurance];
    }

    private function row(InsurancePolicy $p): array
    {
        $days = (int) now()->startOfDay()->diffInDays($p->to, false);
        $members = Employee::where('insurance->policy_id', $p->id)->where('status', '!=', 'terminated')->get(['id', 'name', 'emp_no', 'insurance']);

        return $p->only('id', 'company_id', 'provider', 'policy_no', 'classes', 'from', 'to', 'cost', 'owner_user_id', 'log', 'active') + [
            'company' => $p->relationLoaded('company') ? $p->company?->name : null, 'days_left' => $days,
            'state' => $days < 0 ? ['label' => 'منتهية', 'color' => 'bad'] : ($days <= 30 ? ['label' => 'قريبة الانتهاء', 'color' => 'warn'] : ($days <= 90 ? ['label' => 'تحتاج متابعة', 'color' => 'info'] : ['label' => 'سارية', 'color' => 'ok'])),
            'members_count' => $members->count(), 'dependents_count' => $members->sum(fn ($m) => (int) ($m->insurance['dependents'] ?? 0)),
            'members' => $members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'emp_no' => $m->emp_no, 'class' => $m->insurance['class'] ?? null, 'dependents' => $m->insurance['dependents'] ?? 0, 'card' => $m->insurance['card'] ?? null]),
        ];
    }
}
