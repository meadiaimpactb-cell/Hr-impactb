<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EvalTemplate;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\EvaluationService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EvaluationController extends Controller
{
    public function templates()
    {
        return EvalTemplate::orderBy('id')->get();
    }

    public function saveTemplate(Request $request, ?EvalTemplate $template = null)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'الأوزان صلاحية الرئيس التنفيذي للمجموعة');
        $d = $request->validate(['name' => 'required|string|max:120', 'scope' => 'required|in:group,company,job', 'scope_id' => 'nullable|integer', 'pass_score' => 'required|integer|min:0|max:100',
            'weights' => 'required|array', 'weights.achieve' => 'required|numeric|min:0', 'weights.attend' => 'required|numeric|min:0', 'weights.dev' => 'required|numeric|min:0', 'weights.disc' => 'required|numeric|min:0',
            'sub' => 'required|array', 'grades' => 'required|array|min:1', 'grades.*' => 'array|size:3', 'active' => 'boolean', 'reason' => 'required|string|max:300']);
        if (abs(array_sum($d['weights']) - 100) > 0.01) {
            throw ValidationException::withMessages(['weights' => 'مجموع الأوزان يجب أن يساوي 100']);
        }
        usort($d['grades'], fn ($a, $b) => $b[0] <=> $a[0]);
        $reason = $d['reason'];
        unset($d['reason']);
        $before = $template?->toArray();
        $template ? $template->update($d + ['version' => $template->version + 1, 'effective_from' => now()->toDateString()]) : $template = EvalTemplate::create($d + ['effective_from' => now()->toDateString()]);
        Audit::log('eval_templates', $template->id, $before ? 'update' : 'create', $before, $template->toArray(), $reason);

        return $template;
    }

    /** Live computed monthly evaluations for employees in scope (published or not). */
    public function monthly(Request $request)
    {
        $acc = Access::for();
        abort_unless($acc->canAny('PRF', 'view'), 403);
        $period = $request->input('period', now()->subMonthNoOverflow()->format('Y-m'));
        $emps = $acc->employees()->where('status', '!=', 'terminated')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->with('company:id,name')->orderBy('name')->get();
        $published = Evaluation::whereIn('employee_id', $emps->pluck('id'))->where('period', $period)->where('type', 'monthly')->get()->keyBy('employee_id');
        $weights = $request->input('weights'); // simulation

        return $emps->map(function ($e) use ($period, $published, $weights) {
            $ev = EvaluationService::compute($e, $period, $weights ? ['weights' => $weights] : []);
            $p = $published[$e->id] ?? null;

            return ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name, 'period' => $period, 'axes' => $ev['axes'], 'score' => $ev['score'], 'grade' => $ev['grade'],
                'exempt_attendance' => $ev['exempt_attendance'], 'report_status' => $ev['parts']['achieve']['report_status'], 'published' => $p ? ['score' => $p->score, 'grade' => $p->grade, 'at' => $p->published_at, 'objection' => $p->objection_status, 'id' => $p->id] : null];
        })->values();
    }

    public function show(Request $request, Employee $employee, string $period)
    {
        $u = $request->user();
        abort_unless($employee->id === $u->employee_id || Access::for()->canSeeEmployee($employee), 403);
        $p = Evaluation::where('employee_id', $employee->id)->where('period', $period)->where('type', 'monthly')->first();
        abort_unless($p || Access::for()->can('PRF', 'view', ['employee' => $employee]), 404);

        return ['employee' => $employee->only('id', 'name', 'emp_no'), 'period' => $period, 'computed' => EvaluationService::compute($employee, $period), 'published' => $p,
            'can_object' => $p && $employee->id === $u->employee_id && $p->objection_status === 'none' && $p->published_at->addDays((int) Settings::get('objectionDays', $employee->company_id, 7))->isFuture(),
            'can_decide_objection' => $p && $p->objection_status === 'open' && $u->isHr() && Access::for()->can('PRF', 'approve', ['employee' => $employee]),
            'can_rate' => ! $p && $employee->id !== $u->employee_id && (Access::for()->isManagerOf($employee) || Access::for()->can('PRF', 'edit', ['employee' => $employee]) || $u->hasRole('super', 'hr_mgr')),
            'rating' => self::rating($employee, $period)];
    }

    /** Manager's current rating for the month, with the template's maximum for each part. */
    private static function rating(Employee $employee, string $period): array
    {
        $sub = EvaluationService::templateFor($employee)->sub;
        $rep = \App\Models\MonthlyReport::where('employee_id', $employee->id)->where('period', $period)->first();

        return ['quality' => $rep?->quality, 'coop' => $rep?->coop, 'note' => $rep?->mgr_note, 'max_quality' => $sub['achieve']['quality'] ?? 0, 'max_coop' => $sub['disc']['coop'] ?? 0,
            'by' => $rep?->mgr_by ? User::whereKey($rep->mgr_by)->value('name') : null, 'at' => $rep?->mgr_at];
    }

    /** Direct manager's own rating for the month: work quality (achievement axis) and cooperation (discipline axis), with a note. */
    public function managerRating(Request $request, Employee $employee, string $period)
    {
        $acc = Access::for();
        abort_unless($acc->isManagerOf($employee) || $acc->can('PRF', 'edit', ['employee' => $employee]) || $request->user()->hasRole('super', 'hr_mgr'), 403, 'تقييم الموظف صلاحية مديره المباشر أو الموارد البشرية');
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $period), 422);
        $sub = EvaluationService::templateFor($employee)->sub;
        $d = $request->validate(['quality' => 'required|numeric|min:0|max:'.$sub['achieve']['quality'], 'coop' => 'required|numeric|min:0|max:'.$sub['disc']['coop'], 'note' => 'nullable|string|max:1000']);
        $rep = \App\Models\MonthlyReport::firstOrCreate(['employee_id' => $employee->id, 'period' => $period], ['company_id' => $employee->company_id, 'items' => [], 'dev' => [], 'status' => 'draft']);
        $before = $rep->only('quality', 'coop', 'mgr_note');
        $rep->update(['quality' => $d['quality'], 'coop' => $d['coop'], 'mgr_note' => $d['note'] ?? $rep->mgr_note, 'mgr_by' => $request->user()->id, 'mgr_at' => now()]);
        Audit::log('monthly_reports', $rep->id, 'manager_rating', $before, $rep->only('quality', 'coop', 'mgr_note'));

        return ['computed' => EvaluationService::compute($employee, $period)];
    }

    public function publish(Request $request, Employee $employee, string $period)
    {
        abort_unless(Access::for()->can('PRF', 'publish', ['employee' => $employee]), 403);
        $ev = EvaluationService::publish($employee, $period, $request->user()->id);
        Audit::notify(User::where('employee_id', $employee->id)->value('id'), 'نُشر تقييمك الشهري', "$period — الدرجة {$ev->score} ({$ev->grade})", 'ok', ['screen' => 'evaluation']);
        Audit::log('evaluations', $ev->id, 'publish', null, ['score' => $ev->score]);

        return $ev;
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $rows = Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->orderByDesc('period')->limit(24)->get();

        return ['monthly' => $rows, 'annual' => EvaluationService::annual($e, (int) now()->year), 'template' => EvaluationService::templateFor($e)->only('name', 'weights', 'pass_score', 'grades'),
            'badges' => self::badges($e), 'kudos' => \Illuminate\Support\Facades\DB::table('kudos')->join('users', 'users.id', '=', 'kudos.from_user_id')->where('kudos.employee_id', $e->id)->orderByDesc('kudos.id')->limit(10)->get(['kudos.text', 'kudos.period', 'users.name as from'])];
    }

    /** Motivation badges computed from facts (not stored): early reports, discipline, development streak, kudos. */
    public static function badges(Employee $e): array
    {
        $out = [];
        $early = \App\Models\MonthlyReport::where('employee_id', $e->id)->whereNotNull('submitted_at')->where('late', false)->whereNotIn('status', ['draft', 'returned'])->count();
        if ($early) { $out[] = ['code' => 'early_report', 'label' => 'تقارير في موعدها', 'count' => $early, 'points' => $early * 10]; }
        $months = collect(range(0, 2))->map(fn ($i) => now()->subMonthsNoOverflow($i)->format('Y-m'));
        $clean = $months->filter(fn ($m) => \App\Models\Evaluation::where('employee_id', $e->id)->where('period', $m)->whereJsonContains('details->attend->absent', 0)->whereJsonContains('details->attend->late', 0)->exists())->count();
        if ($clean) { $out[] = ['code' => 'full_discipline', 'label' => 'انضباط كامل', 'count' => $clean, 'points' => $clean * 15]; }
        $streak = $months->every(fn ($m) => \App\Models\DevRecord::where('employee_id', $e->id)->where('status', 'verified')->where(fn ($q) => $q->where('to', 'like', "$m-%")->orWhere('from', 'like', "$m-%"))->exists());
        if ($streak) { $out[] = ['code' => 'dev_streak', 'label' => 'تطوير 3 أشهر متتالية', 'count' => 1, 'points' => 30]; }
        $k = \Illuminate\Support\Facades\DB::table('kudos')->where('employee_id', $e->id)->count();
        if ($k) { $out[] = ['code' => 'kudos', 'label' => 'إشادات الزملاء', 'count' => $k, 'points' => $k * 5]; }

        return $out;
    }

    public function annual(Request $request)
    {
        $acc = Access::for();
        abort_unless($acc->canAny('PRF', 'view'), 403);
        $year = (int) $request->input('year', now()->year);
        $emps = $acc->employees()->where('status', '!=', 'terminated')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->with('company:id,name')->orderBy('name')->get();

        return $emps->map(fn ($e) => ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name, 'annual' => EvaluationService::annual($e, $year)])->values();
    }

    public function object(Request $request, Evaluation $evaluation)
    {
        $u = $request->user();
        abort_unless($evaluation->employee_id === $u->employee_id && $evaluation->objection_status === 'none', 403);
        $days = (int) Settings::get('objectionDays', $evaluation->company_id, 7);
        abort_unless($evaluation->published_at->addDays($days)->isFuture(), 422, "انتهت مهلة الاعتراض ($days أيام)");
        $d = $request->validate(['text' => 'required|string|max:2000']);
        $evaluation->update(['objection_status' => 'open', 'objection_text' => $d['text'], 'objection_at' => now()]);
        Audit::notify(Access::hrUsersFor($evaluation->company_id, 'PRF'), 'اعتراض على تقييم', $evaluation->employee->name.' — '.$evaluation->period, 'approval', ['screen' => 'evaluation']);

        return $evaluation;
    }

    public function decideObjection(Request $request, Evaluation $evaluation)
    {
        $u = $request->user();
        abort_unless($u->isHr() && Access::for()->can('PRF', 'approve', ['employee' => $evaluation->employee]) && $evaluation->objection_status === 'open', 403);
        $d = $request->validate(['decision' => 'required|in:accepted,rejected', 'note' => 'required|string|max:1000', 'score' => 'nullable|numeric|min:0|max:100']);
        $upd = ['objection_status' => $d['decision'], 'objection_decision' => $d['note'], 'objection_by' => $u->id];
        if ($d['decision'] === 'accepted' && isset($d['score'])) {
            $g = EvaluationService::grade((float) $d['score'], EvaluationService::templateFor($evaluation->employee));
            $upd += ['score' => $d['score'], 'grade' => $g['grade'], 'grade_label' => $g['label']];
        }
        $evaluation->update($upd);
        Audit::log('evaluations', $evaluation->id, 'objection_'.$d['decision'], null, $upd, $d['note']);
        Audit::notify(User::where('employee_id', $evaluation->employee_id)->value('id'), $d['decision'] === 'accepted' ? 'قُبل اعتراضك على التقييم' : 'رُفض اعتراضك على التقييم', $d['note'], $d['decision'] === 'accepted' ? 'ok' : 'bad', ['screen' => 'evaluation']);

        return $evaluation;
    }
}
