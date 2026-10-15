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

        return ['records' => DevRecord::where('employee_id', $e->id)->orderByDesc('from')->get()->map(fn ($r) => $this->row($r)),
            'budget' => $e->benefit('devBudget'), 'programs_per_month' => $e->benefit('programsPerMonth'),
            'spent' => (float) DevRecord::where('employee_id', $e->id)->where('status', 'verified')->where('from', 'like', now()->year.'-%')->sum('cost'),
            'committed' => (float) DevRecord::where('employee_id', $e->id)->whereNotIn('status', ['rejected', 'cancelled'])->where('from', 'like', now()->year.'-%')->sum('cost')];
    }

    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('DEV', 'view'), 403);
        $ids = Access::for()->employees()->pluck('id');

        return DevRecord::with('employee:id,name,emp_no,company_id', 'employee.company:id,name')->whereIn('employee_id', $ids)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->limit(300)->get()->map(fn ($r) => $this->row($r));
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $d = $request->validate(['employee_id' => 'nullable|exists:employees,id', 'program' => 'required|string|max:160', 'provider' => 'nullable|string|max:120', 'kind' => 'nullable|string|max:60',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'hours' => 'nullable|numeric|min:0', 'cost' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:1000']);
        $target = ! empty($d['employee_id']) && (int) $d['employee_id'] !== $u->employee_id ? Employee::findOrFail($d['employee_id']) : $u->employee;
        abort_unless($target, 422);
        $assigned = $target->id !== $u->employee_id;
        abort_unless(! $assigned || Access::for()->can('DEV', 'add', ['employee' => $target]) || Access::for()->isManagerOf($target), 403);
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
        if (! empty($d['cost'])) {
            $budget = (float) ($target->benefit('devBudget') ?? 0);
            $year = substr($d['from'] ?? now()->toDateString(), 0, 4);
            $committed = (float) DevRecord::where('employee_id', $target->id)->whereNotIn('status', ['rejected', 'cancelled'])->where('from', 'like', "$year-%")->sum('cost');
            if ($budget > 0 && $committed + (float) $d['cost'] > $budget) {
                throw \Illuminate\Validation\ValidationException::withMessages(['cost' => 'التكلفة تتجاوز ميزانية التطوير: الميزانية '.number_format($budget).' ر.س، المستخدم '.number_format($committed).'، المتبقي '.number_format(max(0, $budget - $committed)).' ر.س']);
            }
        }
        $path = $request->file('certificate')?->store('dev/'.now()->format('Y'), 'local');
        $record->update(['status' => 'pending', 'certificate_path' => $path ?? $record->certificate_path, 'to' => $record->to ?? now()->toDateString()]);
        Audit::notify(Access::hrUsersFor($record->employee->company_id, 'DEV'), 'سجل تطوير بانتظار التحقق', $record->employee->name.' — '.$record->program, 'approval', ['screen' => 'development']);

        return $this->row($record->fresh()->load('employee:id,name,emp_no'));
    }

    public function verify(Request $request, DevRecord $record)
    {
        abort_unless(Access::for()->can('DEV', 'approve', ['employee' => $record->employee]) && $request->user()->isHr(), 403);
        $d = $request->validate(['decision' => 'required|in:verified,rejected', 'note' => 'nullable|string|max:500']);
        $record->update(['status' => $d['decision'], 'verified_by' => $request->user()->id, 'verified_at' => now(), 'notes' => $d['note'] ?? $record->notes]);
        Audit::log('dev_records', $record->id, $d['decision'], null, null, $d['note'] ?? null);
        Audit::notify(User::where('employee_id', $record->employee_id)->value('id'), $d['decision'] === 'verified' ? 'وُثّق سجل التطوير' : 'رُفض سجل التطوير', $record->program.(! empty($d['note']) ? ' — '.$d['note'] : ''), $d['decision'] === 'verified' ? 'ok' : 'bad', ['screen' => 'development']);
        if ($d['decision'] === 'verified') {
            $record->employee->addCareer('تطوير موثَّق', $record->program.($record->hours ? " ({$record->hours} ساعة)" : ''));
        }

        return $this->row($record->fresh()->load('employee:id,name,emp_no'));
    }

    private function row(DevRecord $r): array
    {
        return $r->only('id', 'program', 'provider', 'kind', 'from', 'to', 'hours', 'cost', 'source', 'status', 'notes', 'verified_at') + [
            'status_label' => DevRecord::STATUSES[$r->status] ?? $r->status, 'has_certificate' => (bool) $r->certificate_path,
            'employee' => $r->relationLoaded('employee') ? $r->employee?->only('id', 'name', 'emp_no') : null, 'company' => $r->relationLoaded('employee') && $r->employee?->relationLoaded('company') ? $r->employee->company?->name : null,
        ];
    }
}
