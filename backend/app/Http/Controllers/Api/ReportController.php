<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\Access;
use App\Services\Audit;
use App\Services\EvaluationService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Monthly reports (employee → manager → HR → CEO) and weekly reports. Templates are data. */
class ReportController extends Controller
{
    // ---------- templates ----------
    public function templates()
    {
        return ReportTemplate::orderBy('id')->get();
    }

    public function saveTemplate(Request $request, ?ReportTemplate $template = null)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);
        $d = $request->validate(['name' => 'required|string|max:120', 'scope' => 'required|in:group,company,job,employee', 'scope_id' => 'nullable|integer|required_if:scope,employee',
            'items' => 'required|array|min:1|max:80', 'items.*.axis' => 'nullable|string|max:160', 'items.*.title' => 'required|string|max:300', 'items.*.target' => 'nullable|string|max:300',
            'items.*.indicator' => 'nullable|string|max:300', 'items.*.weight' => 'required|numeric|min:0|max:100',
            'allow_employee_items' => 'boolean', 'max_employee_items' => 'integer|min:0|max:10', 'employee_item_weight_max' => 'integer|min:0|max:50', 'due_day' => 'integer|min:1|max:28', 'active' => 'boolean']);
        if (abs(array_sum(array_column($d['items'], 'weight')) - 100) > 0.01) {
            throw ValidationException::withMessages(['items' => 'مجموع أوزان البنود يجب أن يساوي 100']);
        }
        $d['items'] = array_map(fn ($i) => $i + ['source' => 'org'], $d['items']);
        $before = $template?->toArray();
        $template ? $template->update($d + ['version' => $template->version + 1]) : $template = ReportTemplate::create($d);
        Audit::log('report_templates', $template->id, $before ? 'update' : 'create', $before, $template->toArray());

        return $template;
    }

    // ---------- monthly: mine ----------
    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $period = $request->input('period', now()->format('Y-m'));
        $rep = MonthlyReport::firstOrCreate(['employee_id' => $e->id, 'period' => $period], $this->blank($e, $period));
        // a draft nobody has started follows the employee's current template (e.g. after HR assigns a personal one)
        $tpl = EvaluationService::reportTemplateFor($e);
        if ($tpl && $rep->status === 'draft' && $rep->template_id !== $tpl->id && ! collect($rep->items)->contains(fn ($i) => trim((string) ($i['achieved'] ?? '')) !== '')) {
            $rep->update(['template_id' => $tpl->id, 'items' => self::itemsFrom($tpl)]);
        }

        return $this->detail($rep->load('employee:id,name,emp_no', 'template'), $request->user());
    }

    public function myHistory(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return MonthlyReport::where('employee_id', $e->id)->orderByDesc('period')->limit(24)->get()->map(fn ($r) => $this->row($r));
    }

    /** Employee edits own draft / returned report (items, dev). */
    public function save(Request $request, MonthlyReport $report)
    {
        $u = $request->user();
        abort_unless($report->employee_id === $u->employee_id && in_array($report->status, ['draft', 'returned'], true), 403);
        abort_if(self::locked($report), 422, 'الشهر مقفل — اطلب فتحه عبر «فتح شهر مقفل»');
        $tpl = $report->template ?? EvaluationService::reportTemplateFor($report->employee);
        $d = $request->validate(['items' => 'required|array|max:90', 'items.*.id' => 'nullable|string|max:20', 'items.*.title' => 'required|string|max:300', 'items.*.kpi_result' => 'nullable|string|max:6000', 'items.*.weight' => 'nullable|numeric|min:0|max:100',
            'items.*.source' => 'in:org,emp', 'items.*.achieved' => 'nullable|string|max:20000', 'items.*.pct' => 'nullable|numeric|min:0|max:100', 'items.*.evidence' => 'nullable|string|max:500',
            'items.*.obstacles' => 'nullable|string|max:5000', 'items.*.solutions' => 'nullable|string|max:5000', 'items.*.next_target' => 'nullable|string|max:5000', 'items.*.notes' => 'nullable|string|max:5000',
            'dev' => 'nullable|array', 'dev.program' => 'nullable|string|max:200', 'dev.provider' => 'nullable|string|max:120', 'dev.completed' => 'boolean', 'dev.applied' => 'boolean', 'dev.notes' => 'nullable|string|max:1000']);
        $empItems = array_filter($d['items'], fn ($i) => ($i['source'] ?? 'org') === 'emp');
        if ($tpl && count($empItems) > ($tpl->allow_employee_items ? $tpl->max_employee_items : 0)) {
            throw ValidationException::withMessages(['items' => 'تجاوزت الحد المسموح من البنود الشخصية']);
        }
        foreach ($empItems as $i) {
            if (($i['weight'] ?? 0) > ($tpl->employee_item_weight_max ?? 15)) {
                throw ValidationException::withMessages(['items' => 'وزن البند الشخصي يتجاوز الحد']);
            }
        }
        // keep manager fields from previous version; employee sets self_score = pct.
        // Organisation items are fixed: their title/weight come from the stored report and none may be removed.
        $prev = collect($report->items)->keyBy('id');
        $orgIds = $prev->filter(fn ($x) => ($x['source'] ?? 'org') !== 'emp')->keys()->all();
        $sentIds = array_filter(array_column($d['items'], 'id'));
        if (array_diff($orgIds, $sentIds)) {
            throw ValidationException::withMessages(['items' => 'لا يمكن حذف بنود القالب']);
        }
        $items = array_map(function ($i) use ($prev) {
            $id = $i['id'] ?? ('it'.substr(md5($i['title'].microtime()), 0, 6));
            $old = $prev[$id] ?? [];
            $isOrg = ($old['source'] ?? $i['source'] ?? 'org') !== 'emp';
            $merged = array_merge($old, $i, ['id' => $id, 'self_score' => $i['pct'] ?? null, 'mgr_note' => $old['mgr_note'] ?? null, 'mgr_score' => $old['mgr_score'] ?? null, 'source' => $isOrg ? 'org' : 'emp', 'weight' => $isOrg ? ($old['weight'] ?? 0) : ($i['weight'] ?? ($old['weight'] ?? 0))]);
            if ($isOrg && $old) {
                $merged['title'] = $old['title'];
            }

            return $merged;
        }, $d['items']);
        $report->update(['items' => array_values($items), 'dev' => array_merge($report->dev ?? [], $d['dev'] ?? [])]);

        return $this->detail($report->fresh()->load('employee:id,name,emp_no', 'template'), $u);
    }

    /** A month is locked once closeDays+1 of the following month has passed, unless a reopen request lifted it. */
    public static function locked(MonthlyReport $r): bool
    {
        $close = Carbon::parse($r->period.'-01')->addMonth()->day((int) Settings::get('closeDays', $r->company_id, 5))->addDay()->endOfDay();

        return now()->gt($close) && ! ($r->reopen_until && $r->reopen_until->gte(now()->startOfDay()));
    }

    public function submit(Request $request, MonthlyReport $report)
    {
        $u = $request->user();
        abort_unless($report->employee_id === $u->employee_id && in_array($report->status, ['draft', 'returned'], true), 403);
        abort_if(self::locked($report), 422, 'الشهر مقفل — اطلب فتحه عبر «فتح شهر مقفل»');
        $empty = collect($report->items)->filter(fn ($i) => trim((string) ($i['achieved'] ?? '')) === '')->count();
        if ($empty) {
            throw ValidationException::withMessages(['items' => "أكمل وصف الإنجاز لكل بند ($empty بند ناقص)"]);
        }
        // §35: due on day 26 of the report month itself (HR may move the day); never past the month's last day
        $start = Carbon::parse($report->period.'-01');
        $due = $start->copy()->day(min((int) Settings::get('monthlyReportDueDay', $report->company_id, 26), $start->daysInMonth));
        $report->update(['status' => 'submitted', 'submitted_at' => now(), 'late' => now()->gt($due->endOfDay()), 'version' => $report->version + 1, 'return_note' => null]);
        $mgr = $report->employee->manager_id ? User::where('employee_id', $report->employee->manager_id)->value('id') : null;
        Audit::notify($mgr ?: Access::usersWithRole('hr_mgr'), 'تقرير شهري بانتظار مراجعتك', $report->employee->name.' — '.$report->period, 'approval', ['screen' => 'reports']);
        Audit::log('monthly_reports', $report->id, 'submit');

        return $this->detail($report->fresh()->load('employee:id,name,emp_no', 'template'), $u);
    }

    // ---------- monthly: review chain ----------
    public function index(Request $request)
    {
        $acc = Access::for();
        $period = $request->input('period', now()->format('Y-m'));
        $ids = $acc->employees()->where('status', '!=', 'terminated')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->pluck('id');
        $reps = MonthlyReport::with('employee:id,name,emp_no,manager_id', 'company:id,name')->whereIn('employee_id', $ids)->where('period', $period)->get()->keyBy('employee_id');
        $emps = Employee::whereIn('id', $ids)->with('company:id,name')->orderBy('name')->get(['id', 'name', 'emp_no', 'company_id', 'manager_id']);

        return $emps->map(function ($e) use ($reps, $period) {
            $r = $reps[$e->id] ?? null;

            return ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name, 'period' => $period, 'report' => $r ? $this->row($r) : null];
        })->values();
    }

    /**
     * Reports of several employees for one month, ready to export as one workbook (a sheet per employee):
     * the submitted / draft report when there is one, otherwise the employee's blank form.
     */
    public function exportData(Request $request)
    {
        $d = $request->validate(['period' => 'required|date_format:Y-m', 'employee_ids' => 'required|array|max:300', 'employee_ids.*' => 'integer']);
        $emps = Access::for()->employees()->whereIn('employees.id', $d['employee_ids'])->with('company:id,name', 'jobTitle:id,name', 'manager:id,name')->orderBy('name')->get();
        $reps = MonthlyReport::whereIn('employee_id', $emps->pluck('id'))->where('period', $d['period'])->get()->keyBy('employee_id');

        return $emps->map(function (Employee $e) use ($reps, $d) {
            $r = $reps[$e->id] ?? null;

            return ['info' => ['name' => $e->name, 'company' => $e->company?->name, 'job' => $e->jobTitle?->name, 'manager' => $e->manager?->name, 'period' => $d['period'],
                'status' => $r ? (MonthlyReport::STATUSES[$r->status] ?? $r->status) : 'لم يبدأ'],
                'items' => $r && count($r->items) ? $r->items : self::itemsFrom(EvaluationService::reportTemplateFor($e))];
        })->values();
    }

    public function show(Request $request, MonthlyReport $report)
    {
        $u = $request->user();
        abort_unless($report->employee_id === $u->employee_id || Access::for()->canSeeEmployee($report->employee), 403);

        return $this->detail($report->load('employee:id,name,emp_no,manager_id', 'template'), $u);
    }

    /** Manager: score each item + cooperation, then pass on. HR: dev hr_score + bonus. CEO: quality + approve. Anyone in chain may return. */
    public function review(Request $request, MonthlyReport $report)
    {
        $u = $request->user();
        $acc = Access::for();
        $e = $report->employee;
        $d = $request->validate(['action' => 'required|in:approve,return', 'note' => 'nullable|string|max:500', 'items' => 'nullable|array', 'items.*.id' => 'required|string',
            'items.*.mgr_score' => 'nullable|numeric|min:0|max:100', 'items.*.mgr_note' => 'nullable|string|max:500', 'coop' => 'nullable|numeric|min:0|max:10',
            'quality' => 'nullable|numeric|min:0|max:20', 'hr_score' => 'nullable|numeric|min:0|max:10', 'hr_bonus' => 'nullable|numeric|min:0|max:10', 'interaction' => 'nullable|numeric|min:0|max:10']);
        $stage = $report->status;
        $isMgr = $acc->isManagerOf($e) || ($stage === 'submitted' && ! $e->manager_id && $u->isHr());
        $isHr = $u->isHr() && $acc->can('PRF', 'approve', ['employee' => $e]);
        $isCeo = $u->hasRole('super') || $acc->isCeoOf($e->company_id);
        $ok = match ($stage) { 'submitted' => $isMgr, 'mgr_reviewed' => $isHr, 'hr_reviewed' => $isCeo, default => false };
        abort_unless($ok, 403, 'ليست مرحلتك في مراجعة هذا التقرير');

        if ($d['action'] === 'return') {
            abort_unless(! empty($d['note']), 422, 'سبب الإرجاع إلزامي');
            $report->update(['status' => 'returned', 'return_note' => $d['note']]);
            Audit::notify(User::where('employee_id', $e->id)->value('id'), 'أُرجع تقريرك الشهري', $d['note'], 'bad', ['screen' => 'reports']);
        } elseif ($stage === 'submitted') {
            $scores = collect($d['items'] ?? [])->keyBy('id');
            $items = array_map(fn ($i) => array_merge($i, ['mgr_score' => $scores[$i['id']]['mgr_score'] ?? $i['mgr_score'] ?? $i['self_score'] ?? $i['pct'] ?? 0, 'mgr_note' => $scores[$i['id']]['mgr_note'] ?? $i['mgr_note'] ?? null]), $report->items);
            $report->update(['items' => $items, 'coop' => $d['coop'] ?? $report->coop, 'status' => 'mgr_reviewed', 'mgr_at' => now(), 'mgr_by' => $u->id]);
            Audit::notify(Access::hrUsersFor($e->company_id, 'PRF'), 'تقرير شهري بانتظار مراجعة الموارد البشرية', $e->name.' — '.$report->period, 'approval', ['screen' => 'reports']);
        } elseif ($stage === 'mgr_reviewed') {
            $dev = array_merge($report->dev ?? [], array_filter(['hr_score' => $d['hr_score'] ?? null, 'interaction' => $d['interaction'] ?? null], fn ($v) => $v !== null));
            $report->update(['dev' => $dev, 'hr_bonus' => $d['hr_bonus'] ?? $report->hr_bonus, 'status' => 'hr_reviewed', 'hr_at' => now(), 'hr_by' => $u->id]);
            $ceo = $e->company?->ceo_employee_id ? User::where('employee_id', $e->company->ceo_employee_id)->value('id') : null;
            Audit::notify($ceo ?: Access::usersWithRole('super'), 'تقرير شهري بانتظار اعتماد الرئيس', $e->name.' — '.$report->period, 'approval', ['screen' => 'reports']);
        } else {
            $report->update(['quality' => $d['quality'] ?? $report->quality, 'status' => 'approved', 'ceo_at' => now(), 'ceo_by' => $u->id]);
            $ev = EvaluationService::publish($e, $report->period, $u->id);
            Audit::notify(User::where('employee_id', $e->id)->value('id'), 'اعتُمد تقريرك الشهري ونُشر تقييمك', "{$report->period} — الدرجة {$ev->score} ({$ev->grade})", 'ok', ['screen' => 'evaluation']);
        }
        Audit::log('monthly_reports', $report->id, $d['action'].'_'.$stage, null, ['status' => $report->status], $d['note'] ?? null);

        return $this->detail($report->fresh()->load('employee:id,name,emp_no,manager_id', 'template'), $u);
    }

    /** HR: remind everyone whose report for the period is missing/draft/returned (and their managers). */
    public function remind(Request $request)
    {
        abort_unless($request->user()->isHr(), 403);
        $period = $request->input('period', now()->subMonthNoOverflow()->format('Y-m'));
        $ids = Access::for()->employees()->where('status', '!=', 'terminated')->pluck('id');
        $done = MonthlyReport::whereIn('employee_id', $ids)->where('period', $period)->whereNotIn('status', ['draft', 'returned'])->pluck('employee_id');
        $late = Employee::whereIn('id', $ids)->whereNotIn('id', $done)->get(['id', 'name', 'manager_id']);
        foreach ($late as $e) {
            Audit::notify(User::where('employee_id', $e->id)->value('id'), "تذكير: تقريرك الشهري عن $period لم يُرفع", 'يرجى الرفع قبل إقفال الشهر', 'warn', ['screen' => 'reports']);
            if ($e->manager_id) {
                Audit::notify(User::where('employee_id', $e->manager_id)->value('id'), 'موظف لم يرفع تقريره الشهري', $e->name." — $period", 'info', ['screen' => 'reports']);
            }
        }

        return ['reminded' => $late->count()];
    }

    /** HR adjustment after approval: bonus points with a mandatory reason → version bump and republished evaluation. */
    public function adjust(Request $request, MonthlyReport $report)
    {
        $u = $request->user();
        abort_unless($u->isHr() && Access::for()->can('PRF', 'approve', ['employee' => $report->employee]) && $report->status === 'approved', 403);
        $d = $request->validate(['hr_bonus' => 'required|numeric|min:0|max:10', 'reason' => 'required|string|max:300']);
        $report->update(['hr_bonus' => $d['hr_bonus'], 'adjust_reason' => $d['reason'], 'version' => $report->version + 1]);
        $ev = EvaluationService::publish($report->employee, $report->period, $u->id);
        Audit::log('monthly_reports', $report->id, 'adjust', null, ['hr_bonus' => $d['hr_bonus']], $d['reason']);
        Audit::notify(User::where('employee_id', $report->employee_id)->value('id'), 'عُدّل تقييمك بعد الاعتماد', "الدرجة الآن {$ev->score} ({$ev->grade}) — {$d['reason']}", 'info', ['screen' => 'evaluation']);

        return $this->detail($report->fresh()->load('employee:id,name,emp_no,manager_id', 'template'), $u);
    }

    // ---------- weekly ----------
    public function weeklyMine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);
        $period = $request->input('period', now()->format('Y-m'));
        EvaluationService::ensureWeeks($e, $period);

        return WeeklyReport::where('employee_id', $e->id)->where('week_start', 'like', "$period-%")->orderByDesc('week_start')->get();
    }

    public function weeklySubmit(Request $request, WeeklyReport $week)
    {
        abort_unless($week->employee_id === $request->user()->employee_id, 403);
        $d = $request->validate(['done' => 'required|string|max:3000', 'next' => 'nullable|string|max:3000', 'blockers' => 'nullable|string|max:2000']);
        $dueDay = (int) Settings::get('weeklyReportDueDay', $request->user()->employee->company_id, 4); // 0=Sun … 4=Thu
        $due = $week->week_start->copy()->addDays($dueDay)->endOfDay();
        $week->update($d + ['status' => $week->status === 'submitted' ? 'submitted' : (now()->gt($due) ? 'late' : 'submitted'), 'submitted_at' => $week->submitted_at ?? now()]);

        return $week;
    }

    public function weeklyTeam(Request $request)
    {
        $period = $request->input('period', now()->format('Y-m'));
        $ids = Access::for()->employees()->where('status', '!=', 'terminated')->pluck('id');
        foreach (Employee::whereIn('id', $ids)->get() as $e) {
            EvaluationService::ensureWeeks($e, $period);
        }

        return WeeklyReport::with('employee:id,name,emp_no')->whereIn('employee_id', $ids)->where('week_start', 'like', "$period-%")->orderBy('employee_id')->orderBy('week_start')->get();
    }

    // ---------- helpers ----------
    /** Empty report items from a template: the employee's obligations with nothing filled in. */
    public static function itemsFrom(?ReportTemplate $tpl): array
    {
        return array_map(fn ($it, $i) => $it + ['id' => 'it'.($i + 1), 'achieved' => '', 'kpi_result' => '', 'pct' => 0, 'evidence' => '', 'obstacles' => '', 'solutions' => '', 'next_target' => '', 'notes' => '', 'self_score' => null, 'mgr_note' => null, 'mgr_score' => null], $tpl?->items ?? [], array_keys($tpl?->items ?? []));
    }

    /** Blank monthly report form of an employee (HR downloads it as Excel to hand out). */
    public function templateFor(Request $request, Employee $employee)
    {
        abort_unless(Access::for()->canSeeEmployee($employee), 403);
        $tpl = EvaluationService::reportTemplateFor($employee);

        return ['employee' => $employee->only('id', 'name', 'emp_no'), 'job' => $employee->jobTitle?->name, 'company' => $employee->company?->name, 'manager' => $employee->manager?->name, 'template' => $tpl?->only('id', 'name', 'scope'), 'items' => self::itemsFrom($tpl)];
    }

    private function blank(Employee $e, string $period): array
    {
        $tpl = EvaluationService::reportTemplateFor($e);
        $items = self::itemsFrom($tpl);

        return ['company_id' => $e->company_id, 'template_id' => $tpl?->id, 'items' => $items, 'dev' => ['program' => '', 'provider' => '', 'completed' => false, 'interaction' => 0, 'applied' => false, 'hr_score' => null, 'notes' => ''], 'status' => 'draft'];
    }

    private function row(MonthlyReport $r): array
    {
        $done = collect($r->items)->filter(fn ($i) => ! empty($i['achieved']) && ($i['pct'] ?? 0) > 0)->count();

        return ['id' => $r->id, 'period' => $r->period, 'status' => $r->status, 'status_label' => MonthlyReport::STATUSES[$r->status] ?? $r->status, 'late' => $r->late,
            'items_total' => count($r->items), 'items_done' => $done, 'submitted_at' => $r->submitted_at, 'employee' => $r->relationLoaded('employee') ? $r->employee?->only('id', 'name', 'emp_no') : null,
            'company' => $r->relationLoaded('company') ? $r->company?->name : null, 'return_note' => $r->return_note];
    }

    private function detail(MonthlyReport $r, User $u): array
    {
        $acc = Access::for($u);
        $e = $r->employee;
        $tpl = $r->template ?? EvaluationService::reportTemplateFor($e);
        $stage = $r->status;
        $isMgr = $acc->isManagerOf($e) || ($stage === 'submitted' && ! $e->manager_id && $u->isHr());
        $canReview = match ($stage) { 'submitted' => $isMgr, 'mgr_reviewed' => $u->isHr() && $acc->can('PRF', 'approve', ['employee' => $e]), 'hr_reviewed' => $u->hasRole('super') || $acc->isCeoOf($e->company_id), default => false };
        $evalTpl = EvaluationService::templateFor($e);

        return $this->row($r) + [
            'items' => $r->items, 'dev' => $r->dev, 'quality' => $r->quality, 'coop' => $r->coop, 'hr_bonus' => $r->hr_bonus,
            'template' => $tpl?->only('id', 'name', 'allow_employee_items', 'max_employee_items', 'employee_item_weight_max', 'due_day'),
            'limits' => ['quality' => $evalTpl->sub['achieve']['quality'], 'coop' => $evalTpl->sub['disc']['coop'], 'hr_score' => $evalTpl->sub['dev']['hrScore'], 'interaction' => $evalTpl->sub['dev']['interact']],
            'locked' => self::locked($r), 'reopen_until' => $r->reopen_until?->toDateString(), 'adjust_reason' => $r->adjust_reason,
            'can_adjust' => $stage === 'approved' && $u->isHr() && $acc->can('PRF', 'approve', ['employee' => $e]),
            'can_edit' => $r->employee_id === $u->employee_id && in_array($stage, ['draft', 'returned'], true) && ! self::locked($r), 'can_review' => $canReview, 'review_role' => $canReview ? ['submitted' => 'mgr', 'mgr_reviewed' => 'hr', 'hr_reviewed' => 'ceo'][$stage] : null,
            'timeline' => array_values(array_filter([$r->submitted_at ? ['at' => $r->submitted_at, 'label' => 'رُفع'] : null, $r->mgr_at ? ['at' => $r->mgr_at, 'label' => 'راجعه المدير'] : null, $r->hr_at ? ['at' => $r->hr_at, 'label' => 'راجعته الموارد البشرية'] : null, $r->ceo_at ? ['at' => $r->ceo_at, 'label' => 'اعتمده الرئيس'] : null])),
            'preview' => $acc->can('PRF', 'view', ['employee' => $e]) || $r->employee_id === $u->employee_id ? EvaluationService::compute($e, $r->period) : null,
        ];
    }
}
