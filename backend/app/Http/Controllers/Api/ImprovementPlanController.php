<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\ImprovementPlan;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\EvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * §75: correction session after one red month (held within 5 working days of publishing, 30-day support plan),
 * improvement plan after two consecutive red months / three in six / annual < 70 (60–90 days, biweekly reviews;
 * prepared by the manager, reviewed by HR, approved by the company CEO). The plan succeeds with ≥ 70 in its last two
 * months; otherwise one extension of up to 30 days, a suitable role, or a lawful contractual step.
 */
class ImprovementPlanController extends Controller
{
    private const OPEN = ['draft', 'hr_reviewed', 'active', 'extended'];

    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('PRF', 'view'), 403);
        $ids = Access::for()->employees('PRF')->pluck('id');

        return ['plans' => ImprovementPlan::with('employee:id,name,emp_no,company_id,manager_id', 'employee.company:id,name')->whereIn('employee_id', $ids)->orderByDesc('id')->limit(200)->get()->map(fn ($p) => $this->row($p, $request->user())),
            'suggestions' => $this->suggestions($ids)];
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return ImprovementPlan::where('employee_id', $e->id)->whereIn('status', ['active', 'extended', 'succeeded', 'failed', 'closed'])->orderByDesc('id')->get()->map(fn ($p) => $this->row($p));
    }

    /** Employees whose published results trigger §75 and who have no open plan. */
    private function suggestions($ids): array
    {
        $out = [];
        $open = ImprovementPlan::whereIn('status', self::OPEN)->pluck('employee_id')->all();
        $lastYear = (int) now()->year - 1;
        foreach (Employee::whereIn('id', $ids)->whereNotIn('id', $open)->where('status', '!=', 'terminated')->get() as $e) {
            $rows = Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->orderByDesc('period')->limit(6)->get(['period', 'score', 'details', 'published_at']);
            $red = $rows->map(fn ($r) => (($r->details['flag']['color'] ?? null) === 'red') || $r->score < 70);
            $reason = null;
            $stage = 'plan';
            if ($rows->count() >= 2 && $red[0] && $red[1]) {
                $reason = 'شهران متتاليان بحالة حمراء';
            } elseif ($red->filter()->count() >= 3) {
                $reason = 'ثلاثة أشهر حمراء خلال ستة أشهر';
            } else {
                // §75: annual result below 70 (the published annual evaluation, else the computed one of last year)
                $annual = Evaluation::where('employee_id', $e->id)->where('type', 'annual')->where('period', $lastYear.'-00')->value('score');
                if ($annual === null && Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->where('period', 'like', "$lastYear-%")->exists()) {
                    $annual = EvaluationService::annual($e, $lastYear)['score'] ?? null;
                }
                if ($annual !== null && (float) $annual < 70) {
                    $reason = "النتيجة السنوية $lastYear أقل من 70 ($annual)";
                } elseif ($rows->isNotEmpty() && $red[0]) {
                    $reason = 'شهر واحد بحالة حمراء — جلسة تصحيح';
                    $stage = 'session';
                }
            }
            if (! $reason) {
                continue;
            }
            $sug = ['employee' => $e->only('id', 'name', 'emp_no'), 'reason' => $reason, 'stage' => $stage, 'last' => $rows[0]->period ?? null, 'score' => $rows[0]->score ?? null];
            if ($stage === 'session' && $rows[0]->published_at) {
                // the correction session is held within 5 working days of publishing the red month
                $due = EvaluationService::addWorkDays($e, $rows[0]->published_at, 5);
                $sug += ['session_due' => $due->toDateString(), 'session_overdue' => $due->isPast()];
            }
            $out[] = $sug;
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
        abort_if(ImprovementPlan::where('employee_id', $e->id)->whereIn('status', self::OPEN)->exists(), 422, 'للموظف خطة مفتوحة بالفعل');
        $days = Carbon::parse($d['start'])->diffInDays(Carbon::parse($d['end']));
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
            // §75 / appendix 5: prepared by the manager → reviewed by HR → approved by the company CEO
            Audit::notify(Access::hrUsersFor($e->company_id, 'PRF') ?: Access::usersWithRole('hr_mgr'), 'خطة تحسين أداء بانتظار مراجعة الموارد البشرية', $e->name, 'approval', ['screen' => 'improvement']);
        }

        return response()->json($this->row($plan->load('employee:id,name,emp_no,manager_id'), $u), 201);
    }

    /**
     * Two stages on the same endpoint: HR reviews a draft (→ hr_reviewed, the CEO is notified); the company CEO (or the
     * group CEO) approves a reviewed plan (→ active, the employee is notified in writing).
     */
    public function approve(Request $request, ImprovementPlan $plan)
    {
        $u = $request->user();
        $acc = Access::for();
        if ($plan->status === 'draft') {
            abort_unless(($u->isHr() && $acc->can('PRF', 'approve', ['employee' => $plan->employee])) || $u->hasRole('super'), 403, 'تراجع الخطة الموارد البشرية قبل اعتماد الرئيس التنفيذي (§75)');
            $plan->update(['status' => 'hr_reviewed', 'hr_reviewed_by' => $u->id, 'hr_reviewed_at' => now()]);
            Audit::log('improvement_plans', $plan->id, 'hr_review');
            $ceo = $plan->employee->company?->ceo_employee_id ? User::where('employee_id', $plan->employee->company->ceo_employee_id)->value('id') : null;
            Audit::notify($ceo ?: Access::usersWithRole('super'), 'خطة تحسين أداء بانتظار اعتمادك', $plan->employee->name, 'approval', ['screen' => 'improvement']);

            return $this->row($plan->fresh()->load('employee:id,name,emp_no,manager_id'), $u);
        }
        abort_unless(($u->hasRole('super') || $acc->isCeoOf($plan->company_id)) && $plan->status === 'hr_reviewed', 403, 'يعتمد الخطة الرئيس التنفيذي للشركة بعد مراجعة الموارد البشرية');
        $plan->update(['status' => 'active', 'approved_by' => $u->id, 'approved_at' => now()]);
        Audit::log('improvement_plans', $plan->id, 'approve');
        $this->notifyEmployee($plan, 'خطة تحسين أداء معتمدة', "من {$plan->start->format('d/m/Y')} إلى {$plan->end->format('d/m/Y')} — مراجعة كل أسبوعين");
        $plan->employee->addCareer('خطة تحسين أداء', "من {$plan->start->format('d/m/Y')} إلى {$plan->end->format('d/m/Y')}");

        return $this->row($plan->fresh()->load('employee:id,name,emp_no,manager_id'), $u);
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

        return $this->row($plan->fresh()->load('employee:id,name,emp_no,manager_id'), $u);
    }

    /** Close: succeeded (≥ 70 in the last two months — checked) / extend once (≤ 30 days) / failed / closed. */
    public function close(Request $request, ImprovementPlan $plan)
    {
        $u = $request->user();
        abort_unless(($u->isHr() || $u->hasRole('super') || Access::for()->isCeoOf($plan->company_id)) && in_array($plan->status, ['active', 'extended'], true), 403);
        $d = $request->validate(['decision' => 'required|in:succeeded,extend,failed,closed', 'outcome' => 'required|string|max:2000', 'new_end' => 'nullable|date|after:start']);
        $check = self::successCheck($plan);
        if ($d['decision'] === 'succeeded' && ! $check['met']) {
            abort(422, 'لا تتحقق قاعدة النجاح: 70 فأكثر في كل من آخر شهرين من الخطة (§75) — '.$check['text']);
        }
        if ($d['decision'] === 'extend') {
            abort_if($plan->status === 'extended', 422, 'تُمدَّد الخطة مرة واحدة فقط (§75)');
            $newEnd = Carbon::parse($d['new_end'] ?? $plan->end->copy()->addDays(30));
            abort_if($newEnd->gt($plan->end->copy()->addDays(30)), 422, 'التمديد لا يتجاوز 30 يومًا');
            $plan->update(['status' => 'extended', 'end' => $newEnd->toDateString(), 'outcome' => $d['outcome']]);
        } else {
            $plan->update(['status' => $d['decision'], 'outcome' => $d['outcome']]);
        }
        Audit::log('improvement_plans', $plan->id, $d['decision'], null, ['success_check' => $check], $d['outcome']);
        $this->notifyEmployee($plan, 'قرار خطة التحسين: '.ImprovementPlan::STATUSES[$plan->status], $d['outcome']);

        return $this->row($plan->fresh()->load('employee:id,name,emp_no,manager_id'), $u);
    }

    /** §75 success rule: published monthly score ≥ 70 (and not red) in EACH of the plan's last two months. */
    public static function successCheck(ImprovementPlan $p): array
    {
        $first = $p->start->format('Y-m');
        $last = $p->end->format('Y-m');
        $rows = Evaluation::where('employee_id', $p->employee_id)->where('type', 'monthly')->where('period', '>=', $first)->where('period', '<=', $last)
            ->orderByDesc('period')->limit(2)->get(['period', 'score', 'details']);
        $months = $rows->map(fn ($r) => ['period' => $r->period, 'score' => $r->score, 'red' => ($r->details['flag']['color'] ?? null) === 'red'])->values()->all();
        $met = count($months) === 2 && collect($months)->every(fn ($m) => $m['score'] >= 70 && ! $m['red']);
        $text = count($months) < 2 ? 'لم يُنشر تقييم شهرين من مدة الخطة بعد' : implode(' · ', array_map(fn ($m) => Carbon::parse($m['period'].'-01')->format('m/Y').': '.$m['score'].($m['red'] ? ' (حمراء)' : ''), $months));

        return ['met' => $met, 'months' => $months, 'text' => $text, 'suggested' => $met ? 'succeeded' : ($p->status === 'extended' ? 'failed' : 'extend')];
    }

    private function notifyEmployee(ImprovementPlan $plan, string $title, string $body): void
    {
        Audit::notify(User::where('employee_id', $plan->employee_id)->value('id'), $title, $body, 'warn', ['screen' => 'improvement']);
    }

    private function row(ImprovementPlan $p, ?User $u = null): array
    {
        $open = in_array($p->status, ['active', 'extended'], true);
        // biweekly reviews: due 14 days after the last review (or after approval / start)
        $lastReview = collect($p->reviews ?? [])->pluck('at')->filter()->max();
        $base = $lastReview ? Carbon::parse($lastReview) : ($p->approved_at ? $p->approved_at->copy() : $p->start?->copy());
        $nextReview = $open && $base ? $base->copy()->addDays(14)->startOfDay() : null;
        $acc = $u ? Access::for($u) : null;

        return $p->only('id', 'stage', 'trigger', 'start', 'end', 'goals', 'support', 'reviews', 'status', 'outcome', 'approved_at', 'hr_reviewed_at', 'created_at') + [
            'stage_label' => ImprovementPlan::STAGES[$p->stage] ?? $p->stage, 'status_label' => ImprovementPlan::STATUSES[$p->status] ?? $p->status,
            'employee' => $p->relationLoaded('employee') ? $p->employee?->only('id', 'name', 'emp_no') : null,
            'company' => $p->relationLoaded('employee') && $p->employee?->relationLoaded('company') ? $p->employee->company?->name : null,
            'days_left' => $p->end ? max(0, now()->startOfDay()->diffInDays($p->end, false)) : null,
            'next_review' => $nextReview?->toDateString(), 'review_overdue' => $nextReview && $nextReview->lt(now()->startOfDay()),
            'success' => $open ? self::successCheck($p) : null,
            'can_hr_review' => $u && $p->status === 'draft' && (($u->isHr() && $acc->can('PRF', 'approve', ['employee' => $p->employee])) || $u->hasRole('super')),
            'can_approve' => $u && $p->status === 'hr_reviewed' && ($u->hasRole('super') || $acc->isCeoOf($p->company_id)),
        ];
    }
}
