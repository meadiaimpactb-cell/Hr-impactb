<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\Lookup;
use App\Models\RequestType;
use App\Services\Access;
use App\Services\ApprovalService;
use App\Services\LeaveService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'trip' => $this->tripData($request, $e),
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
        $flow = match ($rt->handler) { 'leave' => $this->leaveFlow($data), 'trip' => $this->tripFlow($data, $e), 'custody' => $this->custodyFlow($data, $target), default => null };
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

    /**
     * Business trip (§65–68): domestic or international, both dates required, itemised estimate, and the per-diem /
     * hotel cap computed from the company's classification tier and the employee's level.
     */
    private function tripData(Request $request, Employee $e): array
    {
        $v = $request->validate(['destination' => 'required|string|max:120', 'purpose' => 'required|string|max:1000', 'scope' => 'required|in:domestic,intl',
            'from' => 'required|date', 'to' => 'required|date|after_or_equal:from', 'estimate' => 'nullable|numeric|min:0', 'advance' => 'nullable|numeric|min:0',
            'cost_flight' => 'nullable|numeric|min:0', 'cost_lodging' => 'nullable|numeric|min:0', 'cost_allowance' => 'nullable|numeric|min:0', 'cost_transport' => 'nullable|numeric|min:0', 'cost_fees' => 'nullable|numeric|min:0']);
        $days = Carbon::parse($v['from'])->diffInDays(Carbon::parse($v['to'])) + 1;
        $calc = self::tripRates($e, $days);
        $items = array_filter(array_intersect_key($v, array_flip(['cost_flight', 'cost_lodging', 'cost_allowance', 'cost_transport', 'cost_fees'])), fn ($x) => $x !== null && $x !== '');
        $estimate = $items ? array_sum(array_map('floatval', $items)) : (float) ($v['estimate'] ?? 0);
        if ($estimate <= 0) {
            throw ValidationException::withMessages(['estimate' => 'أدخل تفاصيل التكلفة التقديرية أو إجماليها']);
        }
        if (! empty($v['advance']) && (float) $v['advance'] > (float) Settings::get('custodyCashCap', $e->company_id, 3000)) {
            $calc['advance_over_cap'] = true; // the group CEO is added to the chain below (§44)
        }

        return array_merge($v, ['estimate' => round($estimate, 2), 'days' => $days], $calc);
    }

    /** Per-diem by level (ceo / director / manager / specialist / staff) and hotel cap, from the tier benefits (§66–67). */
    public static function tripRates(Employee $e, int $days): array
    {
        $u = User::where('employee_id', $e->id)->first();
        $isCeo = \App\Models\Company::where('ceo_employee_id', $e->id)->exists() || ($u && $u->hasRole('super', 'ceo'));
        $level = $isCeo ? 'Ceo' : (($u && $u->hasRole('hr_mgr', 'fin')) ? 'Dir' : (($u && $u->hasRole('mgr')) || Employee::where('manager_id', $e->id)->exists() ? 'Mgr' : 'Staff'));
        $perDiem = (float) ($e->benefit('perDiem'.$level) ?? 0);
        $hotel = (float) ($e->benefit('hotelCap') ?? 0);

        return ['level' => $level, 'per_diem' => $perDiem, 'allowance_total' => round($perDiem * $days, 2), 'hotel_cap' => $hotel, 'hotel_total_cap' => round($hotel * max(0, $days - 1), 2)];
    }

    /** Trip flow (§65): domestic = manager → HR → finance; international adds the company CEO after the manager and the group CEO last. */
    private function tripFlow(array $data, Employee $e): array
    {
        $flow = ApprovalService::flowFor('trip', $data);
        $roles = array_column($flow, 'role');
        if (($data['scope'] ?? 'domestic') === 'intl') {
            if (! in_array('ceo', $roles, true)) {
                array_splice($flow, 1, 0, [['role' => 'ceo', 'label' => 'الرئيس التنفيذي للشركة (رحلة خارج المملكة)']]);
            }
            if (! in_array('super', $roles, true)) {
                $flow[] = ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة (رحلة خارج المملكة)'];
            }
        } elseif (! empty($data['advance_over_cap']) && ! in_array('super', $roles, true)) {
            $flow[] = ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة (عهدة نقدية فوق 3,000)'];
        }

        return $flow;
    }

    /** Custody flow (§44): cash → manager → finance (+ group CEO above the cap); in-kind → manager → HR. No new cash custody before the previous one is settled. */
    private function custodyFlow(array $data, Employee $e): array
    {
        $amount = (float) ($data['amount'] ?? 0);
        $flow = ApprovalService::flowFor('custody', $data);
        if ($amount <= 0) {
            return $flow;
        }
        $open = \App\Models\CustodyItem::where('employee_id', $e->id)->whereIn('status', ['in_use', 'pending_ack', 'return_requested'])
            ->whereHas('type', fn ($q) => $q->where('is_cash', true))->exists();
        if ($open) {
            throw ValidationException::withMessages(['amount' => 'لا تُصرف عهدة نقدية جديدة قبل تسوية العهدة النقدية السابقة (اللائحة §44)']);
        }
        $flow = [['role' => 'mgr', 'label' => 'المشرف المباشر'], ['role' => 'fin', 'label' => 'المدير المالي (اعتماد العهدة النقدية)']];
        $cap = (float) Settings::get('custodyCashCap', $e->company_id, 3000);
        if ($amount > $cap) {
            $flow[] = ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة (تجاوز سقف العهدة النقدية '.number_format($cap).')'];
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
        // §42: not during probation (180 days) and not while a previous advance is still being repaid — the group CEO may except
        $probation = (int) Settings::get('probationDays', $e->company_id, 180);
        if ($e->hire_date && $e->hire_date->copy()->addDays($probation)->gt(now()) && ! $request->user()->hasRole('super')) {
            throw ValidationException::withMessages(['amount' => 'لا تُمنح السلفة خلال فترة التجربة إلا باستثناء من الرئيس التنفيذي للمجموعة (اللائحة §42)']);
        }
        if (\App\Models\Advance::where('employee_id', $e->id)->where('status', 'repaying')->exists() && ! $request->user()->hasRole('super')) {
            throw ValidationException::withMessages(['amount' => 'لا تُمنح سلفة جديدة قبل سداد السلفة السابقة كاملة (اللائحة §42)']);
        }
        $max = (int) Settings::get('advanceMaxInstallments', $e->company_id, 12);
        $min = (int) Settings::get('advanceMinAmount', $e->company_id, 100);
        $cap = Settings::get('advanceCapSalaries', $e->company_id, 2) * $e->monthlyGross();
        $v = $request->validate(['amount' => "required|numeric|min:$min|max:$cap", 'installments' => "required|integer|min:1|max:$max", 'purpose' => 'required|string|max:300']);

        return $v + ['monthly' => round($v['amount'] / $v['installments'], 2)];
    }

    private function resignationData(Request $request, Employee $e): array
    {
        $v = $request->validate(['requested_last' => 'required|date|after:today', 'reason' => 'required|string|max:2000']);
        $notice = (int) Settings::get($e->contract_type === 'full' ? 'noticeDaysMonthly' : 'noticeDaysOther', $e->company_id, 30); // §108: the employee gives 30 days

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
