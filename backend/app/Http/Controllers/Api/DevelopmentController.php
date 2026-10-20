<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DevRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Development & training records: self-reported or assigned, verified by HR; counted in the evaluation's development axis. */
class DevelopmentController extends Controller
{
    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        $period = $request->input('period', now()->format('Y-m'));

        return ['records' => DevRecord::where('employee_id', $e->id)->orderByDesc('from')->get()->map(fn ($r) => $this->row($r)),
            'budget' => $e->benefit('devBudget'), 'programs_per_month' => $e->benefit('programsPerMonth'), 'points' => \App\Services\EvaluationService::devPoints($e, $period) + ['period' => $period],
            'activities' => \App\Models\Lookup::where('group', 'dev_activities')->where('active', true)->orderBy('sort')->get(['code', 'name', 'name_en', 'meta']),
            'spent' => (float) DevRecord::where('employee_id', $e->id)->where('status', 'verified')->where('from', 'like', now()->year.'-%')->sum('cost'),
            'committed' => (float) DevRecord::where('employee_id', $e->id)->whereNotIn('status', ['rejected', 'cancelled'])->where('from', 'like', now()->year.'-%')->sum('cost'),
            // §79: annual recognition progress and the development star of the current / previous quarter in the company
            'recognition' => \App\Services\EvaluationService::devYear($e, (int) now()->year),
            'stars' => $this->starsFor(Employee::where('company_id', $e->company_id)->where('status', '!=', 'terminated')->with('company:id,name')->get())];
    }

    /** Stars of the current quarter (so far) and the previous one. */
    private function starsFor($emps): array
    {
        $q = (int) ceil(now()->month / 3);
        $prevY = $q === 1 ? (int) now()->year - 1 : (int) now()->year;
        $prevQ = $q === 1 ? 4 : $q - 1;

        return ['current' => \App\Services\EvaluationService::devStars($emps, (int) now()->year, $q), 'previous' => \App\Services\EvaluationService::devStars($emps, $prevY, $prevQ)];
    }

    /**
     * ?board=1 → §79 development leaderboard: points of the month per employee, the quarter's development stars per
     * company, and the annual recognition (≥ 1,500 points or ≥ 6 gold months).
     */
    public function board(Request $request): array
    {
        $acc = Access::for();
        $period = $request->input('period', now()->format('Y-m'));
        $year = (int) $request->input('year', substr($period, 0, 4));
        $quarter = (int) $request->input('quarter', ceil((int) substr($period, 5, 2) / 3));
        $emps = $acc->employees()->where('status', '!=', 'terminated')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->with('company:id,name')->get();
        $rows = $emps->map(function ($e) use ($period, $year) {
            $dp = \App\Services\EvaluationService::devPoints($e, $period);
            $yr = \App\Services\EvaluationService::devYear($e, $year);

            return ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name, 'total' => $dp['total'], 'level' => $dp['level'], 'level_label' => $dp['level_label'],
                'year_points' => $yr['points'], 'gold_months' => $yr['gold_months'], 'annual_recognition' => $yr['qualified']];
        })->sortByDesc('total')->values();

        return ['period' => $period, 'rows' => $rows, 'stars' => \App\Services\EvaluationService::devStars($emps, $year, $quarter),
            'recognized' => $rows->where('annual_recognition', true)->values()];
    }

    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('DEV', 'view'), 403);
        if ($request->boolean('board')) {
            return $this->board($request);
        }
        $ids = Access::for()->employees()->pluck('id');
        // §79: last month ended below 50 points → the manager is asked (once) to hold a guidance session
        $prev = now()->subMonthNoOverflow()->format('Y-m');
        foreach (Employee::whereIn('id', $ids)->where('status', '!=', 'terminated')->get() as $emp) {
            \App\Services\EvaluationService::devGuidance($emp, $prev);
        }

        return DevRecord::with('employee:id,name,emp_no,company_id,manager_id', 'employee.company:id,name')->whereIn('employee_id', $ids)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->limit(300)->get()->map(fn ($r) => $this->row($r));
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $d = $request->validate(['employee_id' => 'nullable|exists:employees,id', 'program' => 'required|string|max:160', 'provider' => 'nullable|string|max:120', 'kind' => 'nullable|string|max:60',
            'activity' => ['nullable', 'string', 'max:20', \Illuminate\Validation\Rule::exists('lookups', 'code')->where('group', 'dev_activities')],
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'hours' => 'nullable|numeric|min:0', 'cost' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:1000',
            'applied_note' => 'nullable|string|max:2000']);
        $pf = \App\Services\EvaluationService::pointsFor($d['activity'] ?? null, isset($d['hours']) ? (float) $d['hours'] : null);
        $d['activity'] = $pf['activity'];
        $target = ! empty($d['employee_id']) && (int) $d['employee_id'] !== $u->employee_id ? Employee::findOrFail($d['employee_id']) : $u->employee;
        abort_unless($target, 422);
        $assigned = $target->id !== $u->employee_id;
        abort_unless(! $assigned || Access::for()->can('DEV', 'add', ['employee' => $target]) || Access::for()->isManagerOf($target), 403);
        // §78: one activity counts once — the same programme on the same date for the same employee is refused
        $date = $d['to'] ?? $d['from'] ?? null;
        $dup = DevRecord::where('employee_id', $target->id)->where('status', '!=', 'rejected')
            ->whereRaw('LOWER(TRIM(program)) = ?', [mb_strtolower(trim($d['program']))])
            ->when($date, fn ($q) => $q->where(fn ($w) => $w->whereDate('from', $date)->orWhereDate('to', $date)), fn ($q) => $q->whereNull('from')->whereNull('to')->whereDate('created_at', now()->toDateString()))
            ->exists();
        if ($dup) {
            throw \Illuminate\Validation\ValidationException::withMessages(['program' => 'هذا النشاط مسجَّل مسبقًا بالتاريخ نفسه — لا يُحتسب النشاط الواحد إلا مرة واحدة (§78)']);
        }
        if (! empty($d['cost'])) {
            $budget = (float) ($target->benefit('devBudget') ?? 0);
            $year = substr($d['from'] ?? now()->toDateString(), 0, 4);
            $committed = (float) DevRecord::where('employee_id', $target->id)->whereNotIn('status', ['rejected', 'cancelled'])->where('from', 'like', "$year-%")->sum('cost');
            if ($budget > 0 && $committed + (float) $d['cost'] > $budget) {
                throw \Illuminate\Validation\ValidationException::withMessages(['cost' => 'التكلفة تتجاوز ميزانية التطوير: الميزانية '.number_format($budget).' ر.س، المستخدم '.number_format($committed).'، المتبقي '.number_format(max(0, $budget - $committed)).' ر.س']);
            }
        }
        $path = $request->file('certificate')?->store('dev/'.now()->format('Y'), 'local');
        $rec = DevRecord::create(array_merge($d, ['employee_id' => $target->id, 'source' => $assigned ? 'assigned' : 'self', 'status' => $assigned ? 'planned' : 'pending', 'certificate_path' => $path]));
        if ($assigned) {
            Audit::notify(User::where('employee_id', $target->id)->value('id'), 'برنامج تطوير مُسند إليك', $rec->program, 'info', ['screen' => 'development']);
        } else {
            Audit::notify(Access::hrUsersFor($target->company_id, 'DEV'), 'سجل تطوير بانتظار التحقق', $target->name.' — '.$rec->program, 'approval', ['screen' => 'development']);
        }

        return response()->json($this->row($rec->load('employee:id,name,emp_no')), 201);
    }

    /** Employee marks an assigned program done (→ pending) with optional certificate. */
    public function complete(Request $request, DevRecord $record)
    {
        abort_unless($record->employee_id === $request->user()->employee_id && $record->status === 'planned', 403);
        // §81: a program the employee was nominated for ends with a summary of what was learned and how it will be applied
        $d = $request->validate(['summary' => [$record->source === 'assigned' ? 'required' : 'nullable', 'string', 'min:30', 'max:4000']],
            ['summary.required' => 'اكتب ملخص ما تعلمته والتطبيق المتوقع في عملك (§81)', 'summary.min' => 'الملخص قصير — 30 حرفًا على الأقل']);
        $path = $request->file('certificate')?->store('dev/'.now()->format('Y'), 'local');
        $record->update(['status' => 'pending', 'certificate_path' => $path ?? $record->certificate_path, 'to' => $record->to ?? now()->toDateString()]
            + (! empty($d['summary']) ? ['summary' => $d['summary'], 'summary_at' => now()] : []));
        $mgr = $record->employee->manager_id ? User::where('employee_id', $record->employee->manager_id)->where('active', true)->value('id') : null;
        Audit::notify($mgr ? [$mgr] : Access::hrUsersFor($record->employee->company_id, 'DEV'), 'إثبات تطوير بانتظار اعتمادك', $record->employee->name.' — '.$record->program, 'approval', ['screen' => 'development']);

        return $this->row($record->fresh()->load('employee:id,name,emp_no,manager_id'));
    }

    /** §78: the direct manager approves the evidence (HR may too, and audits); points are then booked in the month. */
    public function verify(Request $request, DevRecord $record)
    {
        $u = $request->user();
        $acc = Access::for();
        abort_unless($acc->isManagerOf($record->employee) || ($u->isHr() && $acc->can('DEV', 'approve', ['employee' => $record->employee])) || $u->hasRole('super'), 403, 'يعتمد الإثبات المشرف المباشر أو الموارد البشرية');
        $d = $request->validate(['decision' => 'required|in:verified,rejected', 'note' => 'nullable|string|max:500', 'activity' => ['nullable', 'string', \Illuminate\Validation\Rule::exists('lookups', 'code')->where('group', 'dev_activities')]]);
        $pf = \App\Services\EvaluationService::pointsFor($d['activity'] ?? $record->activity, $record->hours);
        $month = ($record->to ?? $record->from ?? now())->format('Y-m');
        $record->update(['status' => $d['decision'], 'verified_by' => $u->id, 'verified_at' => now(), 'notes' => $d['note'] ?? $record->notes,
            'activity' => $pf['activity'], 'points' => $d['decision'] === 'verified' ? $pf['points'] : 0, 'points_month' => $d['decision'] === 'verified' ? $month : null]);
        Audit::log('dev_records', $record->id, $d['decision'], null, null, $d['note'] ?? null);
        Audit::notify(User::where('employee_id', $record->employee_id)->value('id'), $d['decision'] === 'verified' ? 'وُثّق سجل التطوير' : 'رُفض سجل التطوير', $record->program.(! empty($d['note']) ? ' — '.$d['note'] : ''), $d['decision'] === 'verified' ? 'ok' : 'bad', ['screen' => 'development']);
        if ($d['decision'] === 'verified') {
            $record->employee->addCareer('تطوير موثَّق', $record->program.($record->hours ? " ({$record->hours} ساعة)" : '').' — '.$record->points.' نقطة');
        }

        return $this->row($record->fresh()->load('employee:id,name,emp_no'));
    }

    private function row(DevRecord $r): array
    {
        $sumDays = (int) \App\Services\Settings::get('trainingSummaryDays', null, 3);
        $due = $r->source === 'assigned' && $r->to ? \App\Services\WorkCalendar::addDays(null, $r->to, $sumDays)->toDateString() : null;

        return $r->only('id', 'program', 'provider', 'kind', 'activity', 'points', 'points_month', 'hours', 'cost', 'source', 'status', 'notes', 'applied_note', 'verified_at', 'summary') + ['from' => $r->from?->format('Y-m-d'), 'to' => $r->to?->format('Y-m-d')] + [
            // §81: summary due 3 working days after the program ends
            'summary_due' => $due, 'summary_late' => $due && (! $r->summary_at ? now()->toDateString() > $due : $r->summary_at->toDateString() > $due),
            'status_label' => DevRecord::STATUSES[$r->status] ?? $r->status, 'has_certificate' => (bool) $r->certificate_path, 'certificate_path' => $r->certificate_path,
            'employee' => $r->relationLoaded('employee') ? $r->employee?->only('id', 'name', 'emp_no', 'manager_id') : null, 'company' => $r->relationLoaded('employee') && $r->employee?->relationLoaded('company') ? $r->employee->company?->name : null,
        ];
    }
}
