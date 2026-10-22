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
        // §34: new weights apply from the next evaluation cycle (the 1st of next month); the version in force until then is kept
        $next = now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        if ($template) {
            $prev = $template->effective_from && $template->effective_from->toDateString() > now()->toDateString() ? $template->previous // still not in force: keep what is in force
                : $template->only('weights', 'sub', 'grades', 'pass_score', 'version');
            $template->update($d + ['version' => $template->version + 1, 'effective_from' => $next, 'previous' => $prev]);
            // §118: a change of the evaluation weights is announced to the employees it applies to before it takes effect
            if (($prev['weights'] ?? null) != $template->weights) {
                $emps = \App\Models\Employee::where('status', 'active')->when($template->scope === 'company', fn ($q) => $q->where('company_id', $template->scope_id))
                    ->when($template->scope === 'job', fn ($q) => $q->where('job_title_id', $template->scope_id))->pluck('id');
                $w = $template->weights;
                \App\Services\Audit::notify(\App\Models\User::whereIn('employee_id', $emps)->where('active', true)->pluck('id')->all(), 'تحديث أوزان التقييم الشهري',
                    'من '.\Illuminate\Support\Carbon::parse($next)->format('d/m/Y').': الإنجاز '.($w['achieve'] ?? 0).'% · الحضور '.($w['attend'] ?? 0).'% · التطوير '.($w['dev'] ?? 0).'% · الانضباط '.($w['disc'] ?? 0).'% — '.$reason, 'info', ['screen' => 'evaluation']);
            }
        } else {
            $template = EvalTemplate::create($d + ['effective_from' => now()->toDateString()]);
        }
        Audit::log('eval_templates', $template->id, $before ? 'update' : 'create', $before, $template->toArray(), $reason);

        return $template;
    }

    /** Live computed monthly evaluations for employees in scope (published or not). */
    public function monthly(Request $request)
    {
        $acc = Access::for();
        abort_unless($acc->canAny('PRF', 'view'), 403);
        $period = $request->input('period', now()->subMonthNoOverflow()->format('Y-m'));
        $emps = $acc->employees('PRF')->where('status', '!=', 'terminated')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->with('company:id,name')->orderBy('name')->get();
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
            'can_object' => $p && $employee->id === $u->employee_id && $p->objection_status === 'none' && self::objectionDeadline($p)->isFuture(),
            'can_decide_objection' => $p && $p->objection_status === 'open' && self::canDecide($u, $p),
            'objection' => $p ? self::objectionInfo($p) : null,
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

        // §76: published annual evaluations (fiscal / contract) can be objected to as well
        $annualRows = Evaluation::where('employee_id', $e->id)->where('type', 'annual')->orderByDesc('period')->limit(6)->get()
            ->map(fn ($a) => $a->toArray() + ['kind' => str_ends_with($a->period, '99') ? 'contract' : 'fiscal', 'year' => (int) substr($a->period, 0, 4), 'objection' => self::objectionInfo($a),
                'can_object' => $a->objection_status === 'none' && self::objectionDeadline($a)->isFuture()]);
        $rows = $rows->map(fn ($r) => $r->toArray() + ['objection' => self::objectionInfo($r), 'can_object' => $r->objection_status === 'none' && self::objectionDeadline($r)->isFuture()]);

        return ['monthly' => $rows, 'annual_published' => $annualRows, 'annual' => EvaluationService::annual($e, (int) now()->year), 'template' => EvaluationService::templateFor($e)->only('name', 'weights', 'pass_score', 'grades'),
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
        // §79: the development target (100 points) reached in EACH of the last three months
        $streak = $months->every(fn ($m) => EvaluationService::devPoints($e, $m)['total'] >= 100);
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
        $emps = $acc->employees('PRF')->where('status', '!=', 'terminated')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->with('company:id,name')->orderBy('name')->get();

        $kind = $request->input('kind', 'fiscal') === 'contract' ? 'contract' : 'fiscal';
        $withBonus = $request->user()->hasRole('super', 'hr_mgr', 'fin') || $acc->canAny('PAY', 'view');
        // ?publish=1: HR publishes the annual results of the list (each employee notified; opens the §76 objection window)
        $publish = $request->boolean('publish');
        abort_if($publish && ! $acc->canAny('PRF', 'publish'), 403);

        return $emps->map(function ($e) use ($year, $kind, $withBonus, $publish, $acc, $request) {
            if ($publish && $acc->can('PRF', 'publish', ['employee' => $e])) {
                $before = Evaluation::where('employee_id', $e->id)->where('type', 'annual')->where('period', $year.'-'.($kind === 'contract' ? '99' : '00'))->value('published_at');
                $ev = EvaluationService::publishAnnual($e, $year, $kind, $request->user()->id);
                if ($ev && (string) $ev->published_at !== (string) $before) {
                    Audit::notify(User::where('employee_id', $e->id)->value('id'), 'نُشر تقييمك السنوي', "$year — الدرجة {$ev->score} ({$ev->grade})", 'ok', ['screen' => 'evaluation']);
                    Audit::log('evaluations', $ev->id, 'publish_annual', null, ['score' => $ev->score]);
                }
            }
            $an = EvaluationService::annual($e, $year, $kind);

            $pub = ($an['published'] ?? null) ? Evaluation::find($an['published']['id']) : null;

            return ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name, 'annual' => $an,
                'objection' => $pub && $pub->objection_status !== 'none' ? ['id' => $pub->id, 'status' => $pub->objection_status, 'text' => $pub->objection_text, 'decision' => $pub->objection_decision,
                    'can_decide' => $pub->objection_status === 'open' && self::canDecide($request->user(), $pub)] + self::objectionInfo($pub) : null,
                'bonus' => $withBonus && $kind === 'fiscal' ? \App\Services\BonusService::annualBonus($e->load('company.tier'), $year, $an) : null];
        })->values();
    }

    /** §73: the annual-goals rating (30% of the annual score) — the manager proposes, the company CEO approves. */
    public function goals(Request $request, Employee $employee)
    {
        $u = $request->user();
        $acc = Access::for();
        abort_unless($acc->isManagerOf($employee) || $u->hasRole('super') || $acc->isCeoOf($employee->company_id) || ($u->isHr() && $acc->can('PRF', 'approve', ['employee' => $employee])), 403);
        $d = $request->validate(['year' => 'required|integer|min:2024|max:2100', 'kind' => 'in:fiscal,contract', 'score' => 'required|numeric|min:0|max:100', 'note' => 'nullable|string|max:2000', 'approve' => 'boolean']);
        $key = $d['year'].'-'.(($d['kind'] ?? 'fiscal') === 'contract' ? '99' : '00'); // evaluations.period is 7 chars: YYYY-00 fiscal goals, YYYY-99 contract-year goals
        $isCeo = $u->hasRole('super') || $acc->isCeoOf($employee->company_id);
        $ev = Evaluation::updateOrCreate(['employee_id' => $employee->id, 'period' => $key, 'type' => 'goals'], ['company_id' => $employee->company_id, 'score' => $d['score'], 'grade' => $isCeo ? 'approved' : 'proposed',
            'grade_label' => $isCeo ? 'معتمد من الرئيس التنفيذي' : 'مقترح المشرف', 'axes' => [], 'weights' => [], 'details' => ['note' => $d['note'] ?? null, 'by' => $u->name], 'published_at' => $isCeo ? now() : null, 'published_by' => $u->id]);
        Audit::log('evaluations', $ev->id, 'goals', null, ['score' => $d['score'], 'status' => $ev->grade], $d['note'] ?? null);
        if (! $isCeo) {
            $ceo = $employee->company?->ceo_employee_id ? User::where('employee_id', $employee->company->ceo_employee_id)->value('id') : null;
            Audit::notify($ceo ?: Access::usersWithRole('super'), 'تقييم أهداف سنوية بانتظار اعتمادك', $employee->name.' — '.$d['year'], 'approval', ['screen' => 'evaluation']);
        }

        return ['score' => $ev->score, 'status' => $ev->grade, 'status_label' => $ev->grade_label, 'annual' => EvaluationService::annual($employee, (int) $d['year'], $d['kind'] ?? 'fiscal')];
    }

    public function object(Request $request, Evaluation $evaluation)
    {
        $u = $request->user();
        abort_unless($evaluation->employee_id === $u->employee_id && $evaluation->objection_status === 'none' && in_array($evaluation->type, ['monthly', 'annual'], true) && $evaluation->published_at, 403);
        $days = (int) Settings::get('objectionDays', $evaluation->company_id, 7);
        abort_unless(self::objectionDeadline($evaluation)->isFuture(), 422, "انتهت مهلة الاعتراض ($days أيام عمل من النشر — §76)");
        $d = $request->validate(['text' => 'required|string|max:2000']);
        $evaluation->update(['objection_status' => 'open', 'objection_text' => $d['text'], 'objection_at' => now()]);
        $actors = self::actors($evaluation);
        $deciders = array_values(array_diff(Access::hrUsersFor($evaluation->company_id, 'PRF'), $actors));
        $label = $evaluation->type === 'annual' ? 'التقييم السنوي '.substr($evaluation->period, 0, 4) : $evaluation->period;
        Audit::notify($deciders ?: Access::usersWithRole('super'), 'اعتراض على تقييم — يُبت فيه خلال 10 أيام عمل', $evaluation->employee->name.' — '.$label, 'approval', ['screen' => 'evaluation']);
        Audit::notify(Access::usersWithRole('super'), 'اعتراض على تقييم (للعلم)', $evaluation->employee->name.' — '.$label, 'info', ['screen' => 'evaluation']);

        return $evaluation;
    }

    public function decideObjection(Request $request, Evaluation $evaluation)
    {
        $u = $request->user();
        abort_unless($evaluation->objection_status === 'open', 403);
        abort_unless(self::canDecide($u, $evaluation), 403, 'يبت في الاعتراض جهة لم تعتمد التقييم محل الاعتراض ولم تراجعه (§76)');
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
    /** §76: the objection window — 7 WORKING days (objectionDays) from publication, by the employee's week pattern. */
    public static function objectionDeadline(Evaluation $ev): \Illuminate\Support\Carbon
    {
        $days = (int) Settings::get('objectionDays', $ev->company_id, 7);

        return EvaluationService::addWorkDays($ev->employee, $ev->published_at ?? now(), $days);
    }

    /** Deadline + decision due date (10 working days from filing — objectionDecisionDays). */
    public static function objectionInfo(Evaluation $ev): array
    {
        $decide = (int) Settings::get('objectionDecisionDays', $ev->company_id, 10);
        $due = $ev->objection_at ? EvaluationService::addWorkDays($ev->employee, $ev->objection_at, $decide) : null;

        return ['deadline' => $ev->published_at ? self::objectionDeadline($ev)->toDateString() : null, 'decision_due' => $due?->toDateString(),
            'decision_overdue' => $due && $ev->objection_status === 'open' && $due->isPast(), 'decision_days' => $decide];
    }

    /** Users who reviewed / approved / published the evaluation under objection (they may not decide it). */
    public static function actors(Evaluation $ev): array
    {
        $ids = [$ev->published_by];
        if ($ev->type === 'monthly') {
            $rep = \App\Models\MonthlyReport::where('employee_id', $ev->employee_id)->where('period', $ev->period)->first();
            if ($rep) {
                $ids = array_merge($ids, [$rep->mgr_by, $rep->hr_by, $rep->ceo_by]);
            }
        } elseif ($ev->type === 'annual') {
            $ids[] = Evaluation::where('employee_id', $ev->employee_id)->where('type', 'goals')->where('period', $ev->period)->value('published_by');
        }
        // the direct manager rated the employee, the company CEO approved the month: neither decides the objection
        $e = $ev->employee;
        if ($e?->manager_id) {
            $ids[] = User::where('employee_id', $e->manager_id)->value('id');
        }

        return array_values(array_unique(array_filter(array_map('intval', array_filter($ids)))));
    }

    /**
     * §76 / appendix 5: decided by a higher body or committee that did NOT approve the evaluation — HR (manager or staff,
     * within scope) who did not act on it; the group CEO (super) always may.
     */
    public static function canDecide(User $u, Evaluation $ev): bool
    {
        if ($u->hasRole('super')) {
            return true;
        }
        if ($u->employee_id && $u->employee_id === $ev->employee_id) {
            return false;
        }

        return $u->hasRole('hr_mgr', 'hr_staff') && Access::for($u)->canSeeEmployee($ev->employee) && ! in_array($u->id, self::actors($ev), true);
    }
}
