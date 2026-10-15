<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Lookup;
use App\Models\RequestType;
use App\Services\Access;
use App\Services\ApprovalService;
use App\Services\LeaveService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function inbox(Request $request)
    {
        return ApprovalService::inboxFor($request->user())->map(fn ($r) => $this->row($r));
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return ApprovalRequest::with('company:id,name')->where('employee_id', $e->id)->latest()->limit(200)->get()->map(fn ($r) => $this->row($r));
    }

    /** All requests in my scope (HR / managers), filterable. */
    public function index(Request $request)
    {
        $ids = Access::for()->employees()->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))->pluck('id');

        return ApprovalRequest::with('employee:id,name,emp_no', 'company:id,name')->whereIn('employee_id', $ids)
            ->when($request->input('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(30)->through(fn ($r) => $this->row($r));
    }

    public function show(Request $request, ApprovalRequest $approvalRequest)
    {
        $r = $approvalRequest->load('employee.company:id,name', 'actions.user:id,name', 'actions.actingFor:id,name');
        $u = $request->user();
        $approvers = ApprovalService::approvers($r);
        abort_unless($r->employee_id === $u->employee_id || in_array($u->id, $approvers, true) || Access::for()->canSeeEmployee($r->employee), 403);
        $extra = [];
        if ($r->type === 'leave') {
            $extra['balance'] = LeaveService::balance($r->employee, $r->data['type']);
            $extra['conflicts'] = Employee::where('manager_id', $r->employee->manager_id)->where('id', '!=', $r->employee_id)
                ->whereHas('user')->get(['id', 'name'])->filter(fn ($x) => ApprovalRequest::where('employee_id', $x->id)->where('type', 'leave')
                    ->whereIn('status', ['pending', 'approved'])->where('data->from', '<=', $r->data['to'])->where('data->to', '>=', $r->data['from'])->exists())->values();
        }

        return $this->row($r) + [
            'actions' => $r->actions, 'extra' => $extra,
            'can_decide' => $r->status === 'pending' && in_array($u->id, $approvers, true),
            'can_cancel' => $r->status === 'pending' && $r->created_by === $u->id,
            'is_mine' => $r->employee_id === $u->employee_id, 'attachment_path' => $r->attachment_path,
        ];
    }

    public function store(Request $request)
    {
        $type = $request->validate(['type' => 'required|in:'.implode(',', array_keys(ApprovalService::types(true)))])['type'];
        $rt = ApprovalService::type($type);
        $u = $request->user();
        $e = $u->employee;
        abort_unless($e, 422, 'الحساب غير مرتبط بموظف');
        abort_if($rt->roles && ! $u->hasRole(...$rt->roles), 403, 'هذا النوع من الطلبات ليس متاحًا لدورك');

        $data = match ($rt->handler) {
            'leave' => $this->leaveData($request, $e, $request->hasFile('attachment')),
            'correction' => $request->validate(['date' => 'required|date|before_or_equal:today', 'kind' => 'required|string|max:80', 'time' => 'nullable|date_format:H:i', 'reason' => 'required|string|max:1000']),
            'advance' => $this->advanceData($request, $e),
            'letter' => $request->validate(['kind' => 'required|string|max:80', 'to' => 'nullable|string|max:150', 'lang' => 'in:ar,en']),
            'trip' => $request->validate(['destination' => 'required|string|max:120', 'purpose' => 'required|string|max:1000', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from', 'estimate' => 'required|numeric|min:0', 'advance' => 'nullable|numeric|min:0']),
            'resignation' => $this->resignationData($request, $e),
            'allowance', 'salary_change', 'benefit', 'balance_adj' => $this->privilegedData($request, $rt->handler),
            default => $this->customData($request, $rt),
        };
        $target = $e;
        if ($rt->on_behalf) {
            $target = Employee::findOrFail($request->integer('employee_id'));
            abort_unless(Access::for()->can($rt->op, 'add', ['employee' => $target]) || Access::for()->isManagerOf($target), 403);
        } elseif (! $rt->self_service) {
            abort(403, 'هذا النوع لا يُقدَّم ذاتيًا');
        }
        $path = $request->file('attachment')?->store('requests/'.now()->format('Y/m'), 'local'); // only after validation & authorization
        $flow = match ($rt->handler) { 'leave' => $this->leaveFlow($data), 'trip' => $this->tripFlow($data, $e), default => null };
        $req = ApprovalService::submit($target, $type, $data, $flow, $path);

        return response()->json($this->row($req->load('company:id,name')), 201);
    }

    public function decide(Request $request, ApprovalRequest $approvalRequest)
    {
        $data = $request->validate(['decision' => 'required|in:approve,return', 'reason' => 'nullable|string|max:1000']);
        $r = ApprovalService::decide($approvalRequest->load('employee'), $data['decision'], $data['reason'] ?? null, $request->user());

        return $this->row($r);
    }

    public function bulkApprove(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'integer'])['ids'];
        $done = 0;
        foreach (ApprovalRequest::with('employee')->whereIn('id', $ids)->get() as $r) {
            try {
                ApprovalService::decide($r, 'approve', null, $request->user());
                $done++;
            } catch (\Throwable) {
            }
        }

        return ['approved' => $done];
    }

    public function cancel(Request $request, ApprovalRequest $approvalRequest)
    {
        abort_unless($approvalRequest->status === 'pending' && $approvalRequest->created_by === $request->user()->id, 403);
        $approvalRequest->update(['status' => 'cancelled', 'decided_at' => now()]);
        $approvalRequest->actions()->create(['stage' => $approvalRequest->stage, 'user_id' => $request->user()->id, 'decision' => 'cancel']);

        return $this->row($approvalRequest);
    }

    private function leaveData(Request $request, Employee $e, bool $hasFile): array
    {
        $v = $request->validate([
            'leave_type' => 'required|exists:leave_types,code', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from',
            'substitute_id' => 'nullable|exists:employees,id', 'delegate' => 'boolean', 'handover' => 'nullable|string|max:2000',
        ]);
        $lt = LeaveType::where('code', $v['leave_type'])->first();
        $days = LeaveService::days($v['from'], $v['to'], $e->loadMissing('schedule'));
        if ($days < 1) {
            throw ValidationException::withMessages(['to' => 'الفترة المختارة كلها أيام عطلة']);
        }
        if ($lt->attachment_required && ! $hasFile) {
            throw ValidationException::withMessages(['attachment' => "المرفق إلزامي لإجازة {$lt->name}"]);
        }
        if ($lt->max_per_case && $days > $lt->max_per_case) {
            throw ValidationException::withMessages(['to' => "الحد الأقصى لكل واقعة {$lt->max_per_case} يوم"]);
        }
        $overlap = ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->whereIn('status', ['pending', 'approved'])
            ->where('data->from', '<=', $v['to'])->where('data->to', '>=', $v['from'])->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['from' => 'يوجد طلب إجازة متداخل مع هذه الفترة']);
        }
        if ($lt->deducts && $lt->quota_key) {
            $b = LeaveService::balance($e, $lt);
            if ($b['available'] < $days && ! Settings::get('leaveAdvanceAllowed', $e->company_id, false)) {
                throw ValidationException::withMessages(['to' => "الرصيد المتاح {$b['available']} يوم أقل من المطلوب ({$days})"]);
            }
        }

        if (! empty($v['substitute_id']) && ApprovalRequest::where('employee_id', $v['substitute_id'])->where('type', 'leave')->whereIn('status', ['pending', 'approved'])
            ->where('data->from', '<=', $v['to'])->where('data->to', '>=', $v['from'])->exists()) {
            throw ValidationException::withMessages(['substitute_id' => 'البديل المختار في إجازة خلال الفترة نفسها']);
        }

        // Team coverage: colleagues under the same manager who are off in the same period.
        $teamOff = $e->manager_id ? Employee::where('manager_id', $e->manager_id)->where('id', '!=', $e->id)->get(['id', 'name'])
            ->filter(fn ($x) => ApprovalRequest::where('employee_id', $x->id)->where('type', 'leave')->whereIn('status', ['pending', 'approved'])
                ->where('data->from', '<=', $v['to'])->where('data->to', '>=', $v['from'])->exists())->pluck('name')->values()->all() : [];
        $maxAbsent = (int) Settings::get('maxAbsentPerTeam', $e->company_id, 0);

        return ['type' => $lt->code, 'type_name' => $lt->name, 'from' => $v['from'], 'to' => $v['to'], 'days' => $days,
            'substitute_id' => $v['substitute_id'] ?? null, 'delegate' => (bool) ($v['delegate'] ?? false), 'handover' => $v['handover'] ?? null,
            'team_off' => $teamOff, 'coverage_exceeded' => $maxAbsent > 0 && count($teamOff) >= $maxAbsent,
            'extra_approval' => $lt->extra_approval ?: []];
    }

    /** Leave flow = configured flow + leave-type extra approvals + exceptional HR stage when team coverage is exceeded. */
    private function leaveFlow(array $data): array
    {
        $flow = ApprovalService::flowFor('leave', $data);
        $roles = array_column($flow, 'role');
        foreach ($data['extra_approval'] ?? [] as $role) {
            if (isset(ApprovalService::roleLabels()[$role]) && ! in_array($role, $roles, true)) {
                $flow = ApprovalService::insertBeforeFinal($flow, ['role' => $role, 'label' => ApprovalService::roleLabels()[$role].' (اعتماد إضافي لنوع الإجازة)']);
                $roles[] = $role;
            }
        }
        if (! empty($data['coverage_exceeded']) && ! in_array('hr', $roles, true) && ! in_array('hr_mgr', $roles, true)) {
            $flow = ApprovalService::insertBeforeFinal($flow, ['role' => 'hr', 'label' => 'اعتماد استثنائي — تجاوز حد الغياب في الفريق']);
        }

        return $flow;
    }

    /** Trip flow = configured flow + finance stage when the estimate exceeds tripFinanceCap. */
    private function tripFlow(array $data, Employee $e): array
    {
        $flow = ApprovalService::flowFor('trip', $data);
        $cap = (float) Settings::get('tripFinanceCap', $e->company_id, 5000);
        if ((float) ($data['estimate'] ?? 0) + (float) ($data['advance'] ?? 0) > $cap && ! in_array('fin', array_column($flow, 'role'), true)) {
            $flow = ApprovalService::insertBeforeFinal($flow, ['role' => 'fin', 'label' => 'المدير المالي (تجاوز سقف الرحلة '.number_format($cap).')']);
        }

        return $flow;
    }

    /** Employee files the post-trip report on an approved trip request. */
    public function tripReport(Request $request, ApprovalRequest $approvalRequest)
    {
        abort_unless($approvalRequest->employee_id === $request->user()->employee_id && $approvalRequest->type === 'trip' && $approvalRequest->status === 'approved', 403);
        $d = $request->validate(['report' => 'required|string|max:5000']);
        $approvalRequest->update(['data' => $approvalRequest->data + ['report' => $d['report'], 'report_at' => now()->toDateTimeString()]]);
        $mgr = $approvalRequest->employee->manager_id ? \App\Models\User::where('employee_id', $approvalRequest->employee->manager_id)->value('id') : null;
        \App\Services\Audit::notify($mgr, 'تقرير رحلة عمل', $approvalRequest->employee->name.' — '.($approvalRequest->data['destination'] ?? ''), 'info', ['screen' => 'requests']);

        return $this->row($approvalRequest);
    }

    private function advanceData(Request $request, Employee $e): array
    {
        if (! $e->advances_enabled) {
            throw ValidationException::withMessages(['amount' => 'خاصية السلف غير مفعّلة لحسابك']);
        }
        $max = (int) Settings::get('advanceMaxInstallments', $e->company_id, 12);
        $cap = Settings::get('advanceCapSalaries', $e->company_id, 2) * $e->monthlyGross();
        $v = $request->validate(['amount' => "required|numeric|min:100|max:$cap", 'installments' => "required|integer|min:1|max:$max", 'purpose' => 'required|string|max:300']);

        return $v + ['monthly' => round($v['amount'] / $v['installments'], 2)];
    }

    private function resignationData(Request $request, Employee $e): array
    {
        $v = $request->validate(['requested_last' => 'required|date|after:today', 'reason' => 'required|string|max:2000']);
        $notice = (int) Settings::get($e->contract_type === 'full' ? 'noticeDaysMonthly' : 'noticeDaysOther', $e->company_id, 60);

        return $v + ['notice' => $notice, 'computed_last' => now()->addDays($notice)->toDateString()];
    }

    private function privilegedData(Request $request, string $type): array
    {
        $v = $request->validate(['employee_id' => 'required|exists:employees,id', 'after' => 'required', 'reason' => 'required|string|max:1000',
            'component' => ($type === 'allowance' ? 'required' : 'nullable').'|string|max:30', 'name' => ($type === 'allowance' ? 'required' : 'nullable').'|string|max:80', 'benefit' => ($type === 'benefit' ? 'required' : 'nullable').'|string|max:40',
            'leave_type' => 'nullable|string', 'effective' => 'nullable|date']);
        $e = Employee::with('company.tier', 'components')->findOrFail($v['employee_id']);
        $before = match ($type) {
            'salary_change' => $e->componentAmount('basic'),
            'allowance' => $e->componentAmount($v['component'] ?? ''),
            'benefit' => $e->benefit($v['benefit'] ?? ''),
            'balance_adj' => LeaveService::balance($e, $v['leave_type'] ?? 'annual')['available'],
        };

        return array_filter(['before' => $before, 'after' => is_numeric($v['after']) ? (float) $v['after'] : $v['after'], 'reason' => $v['reason'],
            'component' => $v['component'] ?? null, 'name' => $v['name'] ?? null, 'benefit' => $v['benefit'] ?? null,
            'type' => $type === 'balance_adj' ? ($v['leave_type'] ?? 'annual') : null, 'effective' => $v['effective'] ?? null], fn ($x) => $x !== null);
    }

    /** Custom request types: validate against the form schema defined in the request type. */
    private function customData(Request $request, RequestType $rt): array
    {
        $rules = [];
        $labels = [];
        foreach ($rt->form ?? [] as $f) {
            $k = $f['key'];
            $labels[$k] = $f['label'] ?? $k;
            $rules[$k] = [! empty($f['required']) ? 'required' : 'nullable', ...match ($f['type'] ?? 'text') {
                'number' => ['numeric'], 'date' => ['date'], 'bool' => ['boolean'], 'employee' => ['exists:employees,id'],
                'select' => [Rule::in($f['options'] ?? [])], 'lookup' => [Rule::in(Lookup::names($f['group'] ?? ''))],
                'textarea' => ['string', 'max:4000'], default => ['string', 'max:500'],
            }];
        }
        $v = $request->validate($rules, [], $labels);
        foreach ($rt->form ?? [] as $f) {
            if (($f['type'] ?? '') === 'employee' && ! empty($v[$f['key']])) {
                $v[$f['key'].'_name'] = Employee::find($v[$f['key']])?->name;
            }
        }

        return $v;
    }

    private function row(ApprovalRequest $r): array
    {
        $st = $r->flow[$r->stage] ?? null;

        return [
            'id' => $r->id, 'type' => $r->type, 'type_label' => ApprovalService::label($r->type), 'summary' => ApprovalService::summary($r),
            'data' => $r->data, 'flow' => $r->flow, 'stage' => $r->stage, 'stage_label' => $st['label'] ?? null, 'status' => $r->status,
            // who the request is waiting for right now (names), so the requester and HR can follow it
            'waiting_for' => $r->status === 'pending' ? \App\Models\User::whereIn('id', ApprovalService::approvers($r))->pluck('name')->all() : [],
            'employee' => $r->relationLoaded('employee') ? $r->employee?->only('id', 'name', 'emp_no') : null,
            'company' => $r->relationLoaded('company') ? $r->company?->name : null,
            'has_attachment' => (bool) $r->attachment_path,
            'created_at' => $r->created_at?->toIso8601String(), 'age_days' => (int) $r->created_at?->diffInDays(now()),
        ];
    }
}
