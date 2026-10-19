<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\ImprovementPlan;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §75: correction session after one red month (30-day support plan), improvement plan after two consecutive red
 * months / three in six / annual < 70 (60–90 days, biweekly reviews, approved by the company CEO). The plan succeeds
 * with ≥ 70 in its last two months; otherwise one extension of up to 30 days, a suitable role, or a lawful contractual step.
 */
class ImprovementPlanController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('PRF', 'view'), 403);
        $ids = Access::for()->employees()->pluck('id');

        return ['plans' => ImprovementPlan::with('employee:id,name,emp_no,company_id', 'employee.company:id,name')->whereIn('employee_id', $ids)->orderByDesc('id')->limit(200)->get()->map(fn ($p) => $this->row($p)),
            'suggestions' => $this->suggestions($ids)];
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return ImprovementPlan::where('employee_id', $e->id)->where('status', '!=', 'draft')->orderByDesc('id')->get()->map(fn ($p) => $this->row($p));
    }

    /** Employees whose published months trigger §75 and who have no open plan. */
    private function suggestions($ids): array
    {
        $out = [];
        $open = ImprovementPlan::whereIn('status', ['draft', 'active', 'extended'])->pluck('employee_id')->all();
        foreach (Employee::whereIn('id', $ids)->whereNotIn('id', $open)->where('status', '!=', 'terminated')->get(['id', 'name', 'emp_no']) as $e) {
            $rows = Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->orderByDesc('period')->limit(6)->get(['period', 'score', 'details']);
            $red = $rows->map(fn ($r) => (($r->details['flag']['color'] ?? null) === 'red') || $r->score < 70);
            if ($rows->isEmpty()) {
                continue;
            }
            $reason = null;
            if ($red->count() >= 2 && $red[0] && $red[1]) {
                $reason = 'شهران متتاليان بحالة حمراء';
            } elseif ($red->filter()->count() >= 3) {
                $reason = 'ثلاثة أشهر حمراء خلال ستة أشهر';
            } elseif ($red[0]) {
                $reason = 'شهر واحد بحالة حمراء — جلسة تصحيح';
            }
            if ($reason) {
                $out[] = ['employee' => $e->only('id', 'name', 'emp_no'), 'reason' => $reason, 'stage' => str_contains($reason, 'جلسة') ? 'session' : 'plan', 'last' => $rows[0]->period, 'score' => $rows[0]->score];
            }
        }

        return $out;
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'stage' => ['required', Rule::in(array_keys(ImprovementPlan::STAGES))], 'trigger' => 'nullable|string|max:300',
            'start' => 'required|date', 'end' => 'required|date|after:start', 'goals' => 'required|array|min:1', 'goals.*.goal' => 'required|string|max:300', 'goals.*.measure' => 'nullable|string|max:300', 'goals.*.due' => 'nullable|date',
            'support' => 'nullable|string|max:2000']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(Access::for()->isManagerOf($e) || ($u->isHr() && Access::for()->can('PRF', 'approve', ['employee' => $e])) || $u->hasRole('super'), 403);
        $days = \Illuminate\Support\Carbon::parse($d['start'])->diffInDays(\Illuminate\Support\Carbon::parse($d['end']));
        if ($d['stage'] === 'plan' && ($days < 60 || $days > 90)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['end' => 'مدة خطة التحسين من 60 إلى 90 يومًا (§75)']);
        }
        if ($d['stage'] === 'session' && $days > 30) {
            throw \Illuminate\Validation\ValidationException::withMessages(['end' => 'خطة الدعم بعد جلسة التصحيح 30 يومًا كحد أقصى (§75)']);
        }
        $goals = array_map(fn ($g) => $g + ['status' => 'open'], $d['goals']);
        $plan = ImprovementPlan::create($d + ['goals' => $goals, 'company_id' => $e->company_id, 'status' => $d['stage'] === 'session' ? 'active' : 'draft', 'created_by' => $u->id, 'reviews' => []]);
        Audit::log('improvement_plans', $plan->id, 'create', null, $plan->only('stage', 'start', 'end'));
        if ($plan->status === 'active') {
            $this->notifyEmployee($plan, 'خطة دعم بعد جلسة التصحيح', 'اطّلع على الأهداف والدعم المقدَّم في ملفك');
        } else {
            $ceo = $e->company?->ceo_employee_id ? User::where('employee_id', $e->company->ceo_employee_id)->value('id') : null;
            Audit::notify($ceo ?: Access::usersWithRole('super'), 'خطة تحسين أداء بانتظار اعتمادك', $e->name, 'approval', ['screen' => 'improvement']);
        }

        return response()->json($this->row($plan->load('employee:id,name,emp_no')), 201);
    }

    /** The company CEO (or the group CEO) approves the plan → active and the employee is notified in writing. */
    public function approve(Request $request, ImprovementPlan $plan)
    {
        $u = $request->user();
        abort_unless(($u->hasRole('super') || Access::for()->isCeoOf($plan->company_id)) && $plan->status === 'draft', 403, 'يعتمد الخطة الرئيس التنفيذي للشركة');
        $plan->update(['status' => 'active', 'approved_by' => $u->id, 'approved_at' => now()]);
        $this->notifyEmployee($plan, 'خطة تحسين أداء معتمدة', "من {$plan->start->toDateString()} إلى {$plan->end->toDateString()} — مراجعة كل أسبوعين");
        $plan->employee->addCareer('خطة تحسين أداء', "من {$plan->start->toDateString()} إلى {$plan->end->toDateString()}");

        return $this->row($plan->fresh()->load('employee:id,name,emp_no'));
    }

    /** Biweekly review by the manager / HR (note + progress per goal). */
    public function review(Request $request, ImprovementPlan $plan)
    {
        $u = $request->user();
        abort_unless((Access::for()->isManagerOf($plan->employee) || $u->isHr() || $u->hasRole('super')) && in_array($plan->status, ['active', 'extended'], true), 403);
        $d = $request->validate(['note' => 'required|string|max:2000', 'goals' => 'nullable|array', 'goals.*.status' => 'in:open,progress,done,missed']);
        $goals = $plan->goals;
        foreach ($d['goals'] ?? [] as $i => $g) {
            if (isset($goals[$i])) { $goals[$i]['status'] = $g['status']; }
        }
        $plan->update(['goals' => $goals, 'reviews' => [...($plan->reviews ?? []), ['at' => now()->toDateString(), 'by' => $u->name, 'note' => $d['note']]]]);
        $this->notifyEmployee($plan, 'مراجعة خطة التحسين', $d['note']);

        return $this->row($plan->fresh()->load('employee:id,name,emp_no'));
    }

    /** Close: succeeded (≥ 70 in the last two months) / failed → extend once (≤ 30 days) / closed with the decision taken. */
    public function close(Request $request, ImprovementPlan $plan)
    {
        $u = $request->user();
        abort_unless(($u->isHr() || $u->hasRole('super') || Access::for()->isCeoOf($plan->company_id)) && in_array($plan->status, ['active', 'extended'], true), 403);
        $d = $request->validate(['decision' => 'required|in:succeeded,extend,failed,closed', 'outcome' => 'required|string|max:2000', 'new_end' => 'nullable|date|after:start']);
        if ($d['decision'] === 'extend') {
            abort_if($plan->status === 'extended', 422, 'تُمدَّد الخطة مرة واحدة فقط (§75)');
            $newEnd = \Illuminate\Support\Carbon::parse($d['new_end'] ?? $plan->end->copy()->addDays(30));
            abort_if($newEnd->gt($plan->end->copy()->addDays(30)), 422, 'التمديد لا يتجاوز 30 يومًا');
            $plan->update(['status' => 'extended', 'end' => $newEnd->toDateString(), 'outcome' => $d['outcome']]);
        } else {
            $plan->update(['status' => $d['decision'], 'outcome' => $d['outcome']]);
        }
        Audit::log('improvement_plans', $plan->id, $d['decision'], null, null, $d['outcome']);
        $this->notifyEmployee($plan, 'قرار خطة التحسين: '.ImprovementPlan::STATUSES[$plan->status], $d['outcome']);

        return $this->row($plan->fresh()->load('employee:id,name,emp_no'));
    }

    private function notifyEmployee(ImprovementPlan $plan, string $title, string $body): void
    {
        Audit::notify(User::where('employee_id', $plan->employee_id)->value('id'), $title, $body, 'warn', ['screen' => 'improvement']);
    }

    private function row(ImprovementPlan $p): array
    {
        return $p->only('id', 'stage', 'trigger', 'start', 'end', 'goals', 'support', 'reviews', 'status', 'outcome', 'approved_at', 'created_at') + [
            'stage_label' => ImprovementPlan::STAGES[$p->stage] ?? $p->stage, 'status_label' => ImprovementPlan::STATUSES[$p->status] ?? $p->status,
            'employee' => $p->relationLoaded('employee') ? $p->employee?->only('id', 'name', 'emp_no') : null,
            'company' => $p->relationLoaded('employee') && $p->employee?->relationLoaded('company') ? $p->employee->company?->name : null,
            'days_left' => $p->end ? max(0, now()->startOfDay()->diffInDays($p->end, false)) : null];
    }
}
