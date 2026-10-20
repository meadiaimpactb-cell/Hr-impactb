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
        // §35: up to 3 own items, each weighing at most 15 (the template may be stricter, never looser)
        $empItems = array_filter($d['items'], fn ($i) => ($i['source'] ?? 'org') === 'emp');
        $maxItems = $tpl && $tpl->allow_employee_items ? min(3, (int) $tpl->max_employee_items) : 0;
        if (count($empItems) > $maxItems) {
            throw ValidationException::withMessages(['items' => "تجاوزت الحد المسموح من البنود الشخصية ($maxItems)"]);
        }
        $maxW = min(15, (float) ($tpl->employee_item_weight_max ?? 15));
        foreach ($empItems as $i) {
            if (($i['weight'] ?? 0) > $maxW) {
                throw ValidationException::withMessages(['items' => "وزن البند الشخصي يتجاوز الحد ($maxW)"]);
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
            // an own item changed after the manager's decision goes back to the manager for approval
            if (! $isOrg && $old && (($old['title'] ?? '') !== ($merged['title'] ?? '') || (float) ($old['weight'] ?? 0) !== (float) ($merged['weight'] ?? 0))) {
                $merged['mgr_approved'] = null;
            }

            return $merged;
        }, $d['items']);
        $report->update(['items' => array_values($items), 'dev' => array_merge($report->dev ?? [], $d['dev'] ?? [])]);

        return $this->detail($report->fresh()->load('employee:id,name,emp_no', 'template'), $u);
    }

    /**
     * §36: the month is locked after the first closeDays (5) WORKING days of the following month (the employee's
     * week pattern / company weekend), unless a reopen request lifted it.
     */
    public static function locked(MonthlyReport $r): bool
    {
        return now()->gt(self::lockAt($r)) && ! ($r->reopen_until && $r->reopen_until->gte(now()->startOfDay()));
    }

    public static function lockAt(MonthlyReport $r): Carbon
    {
        $n = max(1, (int) Settings::get('closeDays', $r->company_id, 5));
        $e = $r->employee;
        $nextStart = Carbon::parse($r->period.'-01')->addMonthNoOverflow()->startOfMonth();
        if (! $e) {
            return $nextStart->copy()->addDays($n - 1)->endOfDay();
        }

        return EvaluationService::addWorkDays($e, $nextStart->copy()->subDay(), $n);
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
        // §35: the first submission fixes lateness; a returned report that is re-submitted is not re-marked late
        $first = $report->first_submitted_at ?? $report->submitted_at;
        $report->update(['status' => 'submitted', 'submitted_at' => now(), 'first_submitted_at' => $first ?? now(), 'late' => $first ? (bool) $report->late : now()->gt($due->endOfDay()),
            'version' => $report->version + 1, 'return_note' => null]);
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
        $sub = EvaluationService::templateFor($e)->sub;
        $d = $request->validate(['action' => 'required|in:approve,return', 'note' => 'nullable|string|max:500', 'items' => 'nullable|array', 'items.*.id' => 'required|string',
            'items.*.mgr_score' => 'nullable|numeric|min:0|max:100', 'items.*.mgr_note' => 'nullable|string|max:500', 'items.*.approved' => 'nullable|boolean',
            'items.*.target' => 'nullable|string|max:5000', 'items.*.next_target' => 'nullable|string|max:5000', 'coop' => 'nullable|numeric|min:0|max:'.($sub['disc']['coop'] ?? 4),
            'quality' => 'nullable|numeric|min:0|max:20', 'hr_score' => 'nullable|numeric|min:0|max:10', 'hr_bonus' => 'nullable|numeric|min:0|max:10', 'bonus_reason' => 'nullable|string|max:500',
            'interaction' => 'nullable|numeric|min:0|max:10']);
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
            // §35: the manager's note is mandatory wherever the rating differs from the employee's
            foreach ($report->items as $i) {
                $ms = $scores[$i['id']]['mgr_score'] ?? null;
                if ($ms !== null && (float) $ms !== (float) ($i['self_score'] ?? $i['pct'] ?? 0) && trim((string) ($scores[$i['id']]['mgr_note'] ?? '')) === '') {
                    throw ValidationException::withMessages(['items' => 'اكتب ملاحظة لكل بند اختلف فيه تقييمك عن تقدير الموظف: '.($i['title'] ?? $i['id'])]);
                }
            }
            // §71: cooperation (out of 4) is the direct manager's own rating — mandatory at this stage
            if (! isset($d['coop'])) {
                throw ValidationException::withMessages(['coop' => 'تقدير التعاون إلزامي في مراجعة المشرف المباشر (§71)']);
            }
            // §35: the employee's own items count only after the manager approves them
            foreach ($report->items as $i) {
                $given = $scores[$i['id']]['approved'] ?? null;
                if (($i['source'] ?? 'org') === 'emp' && $given === null && ($i['mgr_approved'] ?? null) === null) {
                    throw ValidationException::withMessages(['items' => 'وافق على البند الشخصي أو ارفضه: '.($i['title'] ?? $i['id'])]);
                }
            }
            $items = array_map(function ($i) use ($scores) {
                $s = $scores[$i['id']] ?? [];
                $out = array_merge($i, ['mgr_score' => $s['mgr_score'] ?? $i['mgr_score'] ?? $i['self_score'] ?? $i['pct'] ?? 0, 'mgr_note' => $s['mgr_note'] ?? $i['mgr_note'] ?? null]);
                if (($i['source'] ?? 'org') === 'emp' && isset($s['approved'])) {
                    $out['mgr_approved'] = (bool) $s['approved'];
                }
                // §35: the manager may edit this month's (carried-over) target and the next month's targets
                foreach (['target', 'next_target'] as $f) {
                    if (isset($s[$f]) && trim((string) $s[$f]) !== trim((string) ($i[$f] ?? ''))) {
                        $out[$f] = $s[$f];
                        $out[$f.'_by_mgr'] = true;
                    }
                }

                return $out;
            }, $report->items);
            $report->update(['items' => $items, 'coop' => $d['coop'], 'status' => 'mgr_reviewed', 'mgr_at' => now(), 'mgr_by' => $u->id]);
            // carried targets: the next month's draft (if already opened) follows the manager's edit
            $next = MonthlyReport::where('employee_id', $e->id)->where('period', Carbon::parse($report->period.'-01')->addMonthNoOverflow()->format('Y-m'))->where('status', 'draft')->first();
            $carry = collect($items)->filter(fn ($i) => ! empty($i['next_target_by_mgr']))->keyBy('id');
            if ($next && $carry->isNotEmpty()) {
                $next->update(['items' => array_map(fn ($i) => isset($carry[$i['id']]) ? array_merge($i, ['target' => $carry[$i['id']]['next_target'], 'carried_from' => true]) : $i, $next->items ?? [])]);
            }
            Audit::notify(Access::hrUsersFor($e->company_id, 'PRF'), 'تقرير شهري بانتظار مراجعة الموارد البشرية', $e->name.' — '.$report->period, 'approval', ['screen' => 'reports']);
        } elseif ($stage === 'mgr_reviewed') {
            $dev = array_merge($report->dev ?? [], array_filter(['hr_score' => $d['hr_score'] ?? null, 'interaction' => $d['interaction'] ?? null], fn ($v) => $v !== null));
            // §71: the HR exceptional addition (≤ 10) is a reasoned decision recorded on the platform
            $bonus = (float) ($d['hr_bonus'] ?? $report->hr_bonus ?? 0);
            $reason = trim((string) ($d['bonus_reason'] ?? ''));
            if ($bonus > 0 && $reason === '') {
                throw ValidationException::withMessages(['bonus_reason' => 'سبب الدرجات الإضافية إلزامي: إنجاز استثنائي موثق بتوصية المشرف (§71)']);
            }
            $report->update(['dev' => $dev, 'hr_bonus' => $bonus, 'adjust_reason' => $bonus > 0 ? $reason : $report->adjust_reason, 'status' => 'hr_reviewed', 'hr_at' => now(), 'hr_by' => $u->id]);
            if ($bonus > 0) {
                Audit::log('monthly_reports', $report->id, 'hr_bonus', null, ['hr_bonus' => $bonus], $reason);
            }
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

    private const WEEK_ITEM_RULES = ['items' => 'nullable|array|max:30', 'items.*.axis' => 'nullable|string|max:120', 'items.*.title' => 'required|string|max:300', 'items.*.kpi' => 'nullable|string|max:300',
        'items.*.target' => 'nullable|string|max:500', 'items.*.achieved' => 'nullable|string|max:3000'];

    /** §34: the week's plan (axis / item / indicator / weekly target) goes up at the start of the week for the manager to approve. */
    public function weeklyPlan(Request $request, WeeklyReport $week)
    {
        abort_unless($week->employee_id === $request->user()->employee_id, 403);
        $d = $request->validate(self::WEEK_ITEM_RULES);
        $week->update(['items' => array_values($d['items'] ?? []), 'plan_status' => 'submitted']);
        $e = $week->employee;
        $mgr = $e->manager_id ? User::where('employee_id', $e->manager_id)->where('active', true)->value('id') : null;
        Audit::notify($mgr ?: Access::hrUsersFor($e->company_id, 'PRF'), 'خطة أسبوعية بانتظار اعتمادك', $e->name.' — أسبوع '.$week->week_start->toDateString(), 'approval', ['screen' => 'reports']);

        return $week;
    }

    public function weeklyPlanApprove(Request $request, WeeklyReport $week)
    {
        $u = $request->user();
        abort_unless(Access::for()->isManagerOf($week->employee) || $u->isHr() || $u->hasRole('super'), 403);
        $d = $request->validate(['items' => 'nullable|array|max:30', 'note' => 'nullable|string|max:500'] + array_slice(self::WEEK_ITEM_RULES, 1));
        $week->update(['items' => isset($d['items']) ? array_values($d['items']) : $week->items, 'plan_status' => 'approved', 'plan_approved_at' => now(), 'plan_approved_by' => $u->id, 'mgr_note' => $d['note'] ?? $week->mgr_note]);
        Audit::notify(User::where('employee_id', $week->employee_id)->value('id'), 'اعتُمدت خطتك الأسبوعية', 'أسبوع '.$week->week_start->toDateString(), 'ok', ['screen' => 'reports']);

        return $week;
    }

    /** Items to seed the weekly plan from (§34): the month's report targets (axis, item, indicator, monthly target). */
    public function weeklyFromMonthly(Request $request, WeeklyReport $week)
    {
        abort_unless($week->employee_id === $request->user()->employee_id || Access::for()->canSeeEmployee($week->employee), 403);
        $rep = MonthlyReport::where('employee_id', $week->employee_id)->where('period', $week->week_start->format('Y-m'))->first();
        $items = $rep?->items ?: self::itemsFrom(EvaluationService::reportTemplateFor($week->employee));

        return collect($items)->map(fn ($i) => ['axis' => $i['axis'] ?? $i['group'] ?? '', 'title' => $i['title'] ?? '', 'kpi' => $i['indicator'] ?? $i['kpi'] ?? '', 'target' => $i['target'] ?? '', 'achieved' => ''])->values();
    }

    /** End of the week (§34): what was achieved per item, obstacles, and the next week's plan. Late after the first day of the following week. */
    public function weeklySubmit(Request $request, WeeklyReport $week)
    {
        abort_unless($week->employee_id === $request->user()->employee_id, 403);
        $d = $request->validate(['done' => 'nullable|string|max:3000', 'next' => 'nullable|string|max:3000', 'blockers' => 'nullable|string|max:2000'] + self::WEEK_ITEM_RULES);
        $items = isset($d['items']) ? array_values($d['items']) : ($week->items ?? []);
        if (trim((string) ($d['done'] ?? '')) === '' && ! collect($items)->contains(fn ($i) => trim((string) ($i['achieved'] ?? '')) !== '')) {
            throw ValidationException::withMessages(['done' => 'اكتب المنجز مقابل البنود أو ملخص ما أُنجز']);
        }
        $due = EvaluationService::weekDue($week);
        $week->update(['done' => $d['done'] ?? $week->done, 'next' => $d['next'] ?? $week->next, 'blockers' => $d['blockers'] ?? $week->blockers, 'items' => $items,
            'status' => $week->status === 'submitted' ? 'submitted' : (now()->gt($due) ? 'late' : 'submitted'), 'submitted_at' => $week->submitted_at ?? now()]);
        if ($week->status === 'late' && ! $week->noted_at) {
            $week->employee->career()->create(['type' => 'تقرير أسبوعي متأخر', 'detail' => 'أسبوع '.$week->week_start->toDateString().' رُفع بعد موعده (§34)', 'occurred_on' => now()->toDateString()]);
            $week->update(['noted_at' => now()]);
        }

        return $week;
    }

    /** HR / the manager switch weekly reports on or off for one employee (§34). */
    public function weeklyToggle(Request $request, Employee $employee)
    {
        $u = $request->user();
        abort_unless(Access::for()->isManagerOf($employee) || $u->isHr() || $u->hasRole('super'), 403);
        $d = $request->validate(['required' => 'nullable|boolean']);
        $employee->update(['weekly_report_required' => $d['required'] ?? null]);
        Audit::log('employees', $employee->id, 'weekly_report', null, ['required' => $d['required'] ?? null]);

        return ['required' => EvaluationService::weeklyRequired($employee->fresh()), 'override' => $employee->weekly_report_required];
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
        // §35: next month's targets written in the previous report become this month's targets (the manager may still edit them)
        $prevPeriod = Carbon::parse($period.'-01')->subMonth()->format('Y-m');
        $prev = MonthlyReport::where('employee_id', $e->id)->where('period', $prevPeriod)->first();
        if ($prev) {
            $carry = collect($prev->items)->filter(fn ($i) => trim((string) ($i['next_target'] ?? '')) !== '')->keyBy('id');
            $items = array_map(function ($i) use ($carry) { if (isset($carry[$i['id']])) { $i['target'] = $carry[$i['id']]['next_target']; $i['carried_from'] = true; } return $i; }, $items);
        }

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
            'limits' => ['quality' => $evalTpl->sub['achieve']['quality'], 'coop' => $evalTpl->sub['disc']['coop'], 'emp_items' => $tpl && $tpl->allow_employee_items ? min(3, (int) $tpl->max_employee_items) : 0, 'emp_weight' => min(15, (float) ($tpl->employee_item_weight_max ?? 15)), 'hr_score' => $evalTpl->sub['dev']['hrScore'], 'interaction' => $evalTpl->sub['dev']['interact']],
            'locked' => self::locked($r), 'lock_at' => self::lockAt($r)->toDateString(), 'first_submitted_at' => $r->first_submitted_at, 'reopen_until' => $r->reopen_until?->toDateString(), 'adjust_reason' => $r->adjust_reason,
            'can_adjust' => $stage === 'approved' && $u->isHr() && $acc->can('PRF', 'approve', ['employee' => $e]),
            'can_edit' => $r->employee_id === $u->employee_id && in_array($stage, ['draft', 'returned'], true) && ! self::locked($r), 'can_review' => $canReview, 'review_role' => $canReview ? ['submitted' => 'mgr', 'mgr_reviewed' => 'hr', 'hr_reviewed' => 'ceo'][$stage] : null,
            'timeline' => array_values(array_filter([$r->submitted_at ? ['at' => $r->submitted_at, 'label' => 'رُفع'] : null, $r->mgr_at ? ['at' => $r->mgr_at, 'label' => 'راجعه المدير'] : null, $r->hr_at ? ['at' => $r->hr_at, 'label' => 'راجعته الموارد البشرية'] : null, $r->ceo_at ? ['at' => $r->ceo_at, 'label' => 'اعتمده الرئيس'] : null])),
            'preview' => $acc->can('PRF', 'view', ['employee' => $e]) || $r->employee_id === $u->employee_id ? EvaluationService::compute($e, $r->period) : null,
            // §35: the development achieved in the month is pulled from the development register automatically
            'dev_records' => \App\Models\DevRecord::where('employee_id', $e->id)->where('status', 'verified')->where('points_month', $r->period)->orderByDesc('points')->get(['id', 'program', 'provider', 'activity', 'hours', 'points'])
                ->map(fn ($x) => $x->toArray() + ['activity_name' => \App\Models\Lookup::where('group', 'dev_activities')->where('code', $x->activity)->value('name')]),
            'dev_points' => EvaluationService::devPoints($e, $r->period),
        ];
    }
}
