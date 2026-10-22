<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Models\CustodyItem;
use App\Models\CustodySecretLog;
use App\Models\CustodyType;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Custody lifecycle: prepare → hand (pending_ack) → employee acknowledges → in_use
 * → return requested → HR inspects → back to stock, or lost / damaged (§43). Secrets are encrypted at
 * rest and every reveal is logged with a reason. Cash custody is settled by an expense claim within the
 * §44 deadlines; finance settles, returns it for documents, or closes it by refund / salary deduction.
 * §46 monthly reconciliation of advances and cash custody lives here too.
 */
class CustodyController extends Controller
{
    private function acc(Request $r): Access { return Access::for($r->user()); }

    private function canManage(Request $r, ?int $companyId = null, ?CustodyItem $item = null, ?int $employeeId = null): bool
    {
        $acc = $this->acc($r);
        if ($acc->can('CUS', 'edit', $companyId ? ['company_id' => $companyId] : [])) {
            return true;
        }
        // owner's note: finance also hands and takes back cash custody, and the direct manager his own team's custody
        if ($item && $item->type?->is_cash && $r->user()->hasRole('fin') && in_array((int) $item->company_id, array_map('intval', $acc->companyIds()), true)) {
            return true;
        }
        $emp = $employeeId ? \App\Models\Employee::find($employeeId) : $item?->employee;

        return $emp && $acc->isManagerOf($emp) && (! $companyId || (int) $emp->company_id === (int) $companyId);
    }

    /** Companies whose available stock this user may hand out: his team's companies (manager), cash custody (finance). */
    private function handCompanies(Request $r): array
    {
        $u = $r->user();
        $ids = $u->employee_id ? \App\Models\Employee::where('manager_id', $u->employee_id)->distinct()->pluck('company_id')->all() : [];

        return array_values(array_unique(array_map('intval', [...$ids, ...($u->hasRole('fin') ? $this->acc($r)->companyIds() : [])])));
    }

    // ---------------- types ----------------

    public function types() { return CustodyType::withCount('items')->orderBy('id')->get(); }

    public function saveType(Request $request, ?CustodyType $type = null)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);
        $d = $request->validate(['name' => 'required|string|max:80', 'name_en' => 'nullable|string|max:80', 'fields' => 'array', 'fields.*' => 'string|max:60',
            'requires_ack' => 'boolean', 'has_secret' => 'boolean', 'is_cash' => 'boolean', 'dept' => 'nullable|string|max:60', 'active' => 'boolean']);
        $d['fields'] = array_values(array_filter($d['fields'] ?? []));
        $type ? $type->update($d) : $type = CustodyType::create($d);
        Audit::log('custody_types', $type->id, 'save', null, $d);

        return $type;
    }

    // ---------------- items ----------------

    public function index(Request $request)
    {
        $acc = $this->acc($request);
        $companies = array_values(array_filter($acc->companyIds(), fn ($c) => $acc->can('CUS', 'view', ['company_id' => $c])));
        if ($f = $acc->focus()) {
            $companies = array_values(array_intersect($companies, [$f]));
        }
        $empIds = $acc->canAny('CUS', 'view') ? $acc->employees('CUS')->pluck('id') : collect([$request->user()->employee_id]);

        $team = $request->user()->employee_id ? \App\Models\Employee::where('manager_id', $request->user()->employee_id)->pluck('id') : collect();
        $hand = $this->handCompanies($request);

        return CustodyItem::with('type:id,name,requires_ack,has_secret,is_cash', 'company:id,name', 'employee:id,name,emp_no')
            ->where(fn ($q) => $q->whereIn('company_id', $companies)->orWhereIn('employee_id', $empIds)->orWhereIn('employee_id', $team)
                ->orWhere(fn ($q) => $q->where('status', 'available')->whereIn('company_id', $hand)))
            ->when($acc->focus(), fn ($q, $f) => $q->where('company_id', $f))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->orderByDesc('id')->get()->map(fn ($i) => $this->row($i));
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return CustodyItem::with('type:id,name,requires_ack,has_secret,is_cash', 'company:id,name')->where('employee_id', $e->id)
            ->whereNotIn('status', ['available'])->orderByDesc('id')->get()->map(fn ($i) => $this->row($i));
    }

    public function show(Request $request, CustodyItem $item)
    {
        $item->load('type', 'company:id,name', 'employee:id,name,emp_no');
        $u = $request->user();
        abort_unless($u->employee_id === $item->employee_id || $this->acc($request)->can('CUS', 'view', ['company_id' => $item->company_id]) || $this->canManage($request, $item->company_id, $item)
            || ($item->status === 'available' && in_array((int) $item->company_id, $this->handCompanies($request), true)), 403);

        return $this->row($item) + [
            'history' => $item->history, 'fields' => $item->fields, 'condition' => $item->condition,
            'has_secret' => (bool) $item->secret, 'secret_rotated_at' => $item->secret_rotated_at,
            'secret_logs' => $this->acc($request)->can('SEC', 'view') ? $item->secretLogs()->with('user:id,name')->limit(20)->get() : [],
            'claims' => $item->claims()->latest()->get(),
            'can_manage' => $this->canManage($request, $item->company_id, $item),
            'can_hand' => $item->status === 'available' && ($this->canManage($request, $item->company_id, $item) || in_array((int) $item->company_id, $this->handCompanies($request), true)),
            'can_reveal' => (bool) $item->secret && $this->acc($request)->can('SEC', 'view'),
            'can_set_secret' => $item->type->has_secret && $this->acc($request)->can('SEC', 'edit'),
            // §43 acknowledgement: the undertaking to accept (pending) or the one accepted, with time and device
            'ack_text' => $item->ack_text ?: ($item->status === 'pending_ack' ? CustodyItem::ACK_TEXT : null),
            'ack_device' => $item->ack_device, 'ack_ip' => $u->employee_id === $item->employee_id || $this->canManage($request, $item->company_id) ? $item->ack_ip : null,
            'overspend_note' => $item->overspend_note,
            'can_overspend' => (bool) $item->type->is_cash && in_array($item->status, CustodyItem::OPEN, true) && $u->hasRole('fin', 'super'),
            'can_purpose_end' => (bool) $item->type->is_cash && in_array($item->status, CustodyItem::OPEN, true)
                && ($this->canManage($request, $item->company_id) || $u->hasRole('fin', 'super') || $u->employee_id === $item->employee_id || $this->acc($request)->isManagerOf((int) $item->employee_id)),
        ];
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        abort_unless($this->canManage($request, $d['company_id']), 403);
        $item = new CustodyItem($d + ['status' => 'available', 'history' => []]);
        $item->log('تجهيز');
        $item->save();
        Audit::log('custody_items', $item->id, 'create', null, ['ident' => $item->ident]);

        return response()->json($this->row($item->load('type', 'company:id,name')), 201);
    }

    public function update(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id), 403);
        $before = $item->only('ident', 'fields', 'value', 'condition');
        $d = $this->validated($request, $item);
        if (isset($d['company_id']) && (int) $d['company_id'] !== (int) $item->company_id) { // moving an item to another company needs rights there too
            abort_unless($this->canManage($request, (int) $d['company_id']), 403, 'لا صلاحية لك على الشركة المنقول إليها');
            abort_if($item->employee_id, 422, 'استرد العهدة من الموظف قبل نقلها لشركة أخرى');
        }
        $item->update($d);
        Audit::log('custody_items', $item->id, 'update', $before, $item->only('ident', 'fields', 'value', 'condition'));

        return $this->row($item->load('type', 'company:id,name', 'employee:id,name,emp_no'));
    }

    /** Hand an available item to an employee. Requires ack unless the type says otherwise. */
    public function hand(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id, $item, $request->integer('employee_id') ?: null), 403, 'تسلّم العهدة لموظفي فريقك فقط');
        abort_unless($item->status === 'available', 422, 'العهدة ليست متاحة للتسليم');
        $d = $request->validate(['employee_id' => ['required', \Illuminate\Validation\Rule::exists('employees', 'id')->where('company_id', $item->company_id)], 'purpose_end' => 'nullable|date'], ['employee_id.exists' => 'الموظف ليس من موظفي الشركة المالكة للعهدة']);
        $item->employee_id = $d['employee_id'];
        if (CustodyItem::hasDeadlineColumns()) { // a new hand-over: new disbursement, new acknowledgement
            $item->disbursed_at = now();
            $item->purpose_end = $d['purpose_end'] ?? null;
            $item->ack_text = $item->ack_at = $item->ack_device = $item->ack_ip = null;
        }
        $item->status = $item->type->requires_ack || $item->type->is_cash ? 'pending_ack' : 'in_use'; // §43–44: cash custody is always acknowledged
        $item->log($item->status === 'pending_ack' ? 'طلب تسليم' : 'تسليم', null, $d['employee_id']);
        $item->save();
        Audit::log('custody_items', $item->id, 'hand', null, ['employee' => $d['employee_id']]);
        $this->notifyEmployee($d['employee_id'], $item->status === 'pending_ack' ? 'عهدة بانتظار إقرارك' : 'سُلّمت لك عهدة', $item->ident);

        return $this->refresh($item);
    }

    /**
     * §43: the employee acknowledges receipt electronically, accepting the undertaking text, which is stored
     * verbatim with the timestamp, device and IP.
     */
    public function ack(Request $request, CustodyItem $item)
    {
        abort_unless($request->user()->employee_id === $item->employee_id, 403);
        abort_unless($item->status === 'pending_ack', 422, 'لا يوجد إقرار مطلوب');
        $request->validate(['agree' => 'accepted'], ['agree.accepted' => 'يلزم الموافقة على نص الإقرار والتعهد']);
        $device = mb_substr((string) $request->userAgent(), 0, 255);
        $item->status = 'in_use';
        if (CustodyItem::hasDeadlineColumns()) {
            $item->ack_text = CustodyItem::ACK_TEXT;
            $item->ack_at = now();
            $item->ack_device = $device;
            $item->ack_ip = $request->ip();
        }
        $item->log('إقرار استلام', 'وافق على نص الإقرار والتعهد — '.mb_substr($device, 0, 60));
        $item->save();
        Audit::log('custody_items', $item->id, 'ack', null, ['ack_at' => now()->toDateTimeString(), 'ip' => $request->ip()]);

        return $this->refresh($item);
    }

    public function requestReturn(Request $request, CustodyItem $item)
    {
        abort_unless($request->user()->employee_id === $item->employee_id, 403);
        abort_unless($item->status === 'in_use', 422);
        $item->status = 'return_requested';
        $item->log('طلب استرداد', $request->input('note'));
        $item->save();
        $mgr = $item->employee?->manager_id ? \App\Models\User::where('employee_id', $item->employee->manager_id)->value('id') : null;
        Audit::notify(array_values(array_filter([...Access::hrUsersFor($item->company_id, 'CUS'), ...Access::usersWithRole('custodian'), $mgr])), 'طلب إعادة عهدة', $request->user()->name.' — '.$item->ident.': استلمها للفحص', 'approval', ['screen' => 'custody']);

        return $this->refresh($item);
    }

    /** HR/custodian takes the item back for inspection (from in_use or return_requested). */
    public function receive(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id, $item), 403);
        abort_unless(in_array($item->status, ['in_use', 'return_requested'], true), 422);
        $item->status = 'inspecting';
        $item->log('استلام للفحص');
        $item->save();

        return $this->refresh($item);
    }

    /** Close the inspection: back to stock, or lost, or damaged (§43; no automatic deduction — §41 needs proven liability). */
    public function close(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id, $item), 403);
        abort_unless($item->status === 'inspecting', 422, 'يجب استلام العهدة للفحص أولًا');
        $d = $request->validate(['result' => 'required|in:ok,lost,damaged', 'note' => 'required|string|max:500']);
        $prev = $item->employee_id;
        $item->log(match ($d['result']) { 'ok' => 'فحص وإغلاق — سليمة', 'lost' => 'فحص — مفقودة', default => 'فحص — تالفة' }, $d['note'], $prev);
        $item->status = $d['result'] === 'ok' ? 'available' : $d['result'];
        $item->employee_id = $d['result'] === 'ok' ? null : $prev;
        $item->save();
        Audit::log('custody_items', $item->id, 'close', null, ['result' => $d['result']], $d['note']);

        return $this->refresh($item);
    }

    public function transfer(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id, $item) && $this->canManage($request, $item->company_id, $item, $request->integer('employee_id') ?: null), 403);
        abort_unless($item->status === 'in_use', 422);
        $d = $request->validate(['employee_id' => ['required', 'not_in:'.$item->employee_id, \Illuminate\Validation\Rule::exists('employees', 'id')->where('company_id', $item->company_id)]], ['employee_id.exists' => 'الموظف ليس من موظفي الشركة المالكة للعهدة']);
        $item->log('نقل من '.($item->employee?->name ?? '—'), null, $d['employee_id']);
        $item->employee_id = $d['employee_id'];
        if (CustodyItem::hasDeadlineColumns()) {
            $item->ack_text = $item->ack_at = $item->ack_device = $item->ack_ip = null;
        }
        $item->status = $item->type->requires_ack || $item->type->is_cash ? 'pending_ack' : 'in_use';
        $item->save();
        Audit::log('custody_items', $item->id, 'transfer', null, ['to' => $d['employee_id']]);
        $this->notifyEmployee($d['employee_id'], 'عهدة منقولة إليك بانتظار إقرارك', $item->ident);

        return $this->refresh($item);
    }

    // ---------------- secrets ----------------

    public function setSecret(Request $request, CustodyItem $item)
    {
        abort_unless($item->type->has_secret, 422, 'هذا النوع لا يحمل سرًا');
        abort_unless($this->acc($request)->can('SEC', 'edit'), 403, 'حفظ الأسرار صلاحية SEC مستقلة');
        $d = $request->validate(['secret' => 'required|string|max:500']);
        $item->update(['secret' => Crypt::encryptString($d['secret']), 'secret_rotated_at' => now()]);
        CustodySecretLog::create(['custody_item_id' => $item->id, 'user_id' => $request->user()->id, 'action' => 'rotate', 'ip' => $request->ip()]);
        Audit::log('custody_items', $item->id, 'secretRotate');

        return ['ok' => true];
    }

    /** One-time reveal, always with a reason, always logged. */
    public function reveal(Request $request, CustodyItem $item)
    {
        abort_unless($item->secret, 404, 'لا سر محفوظ');
        abort_unless($this->acc($request)->can('SEC', 'view'), 403, 'الكشف عن الأسرار صلاحية SEC مستقلة — غير ممنوحة لك');
        $d = $request->validate(['reason' => 'required|string|max:300']);
        CustodySecretLog::create(['custody_item_id' => $item->id, 'user_id' => $request->user()->id, 'action' => 'reveal', 'reason' => $d['reason'], 'ip' => $request->ip()]);
        Audit::log('custody_items', $item->id, 'secretReveal', null, null, $d['reason']);

        return ['secret' => Crypt::decryptString($item->secret)];
    }

    // ---------------- expense claims (cash custody settlement) ----------------

    public function claims(Request $request)
    {
        $empIds = $this->acc($request)->employees('CUS')->pluck('id');

        return ExpenseClaim::with('employee:id,name,emp_no', 'item:id,ident,value')->whereIn('employee_id', $empIds)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->latest()->get()
            ->map(fn ($c) => $this->claimRow($c));
    }

    public function myClaims(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return ExpenseClaim::with('item:id,ident,value')->where('employee_id', $e->id)->latest()->get()->map(fn ($c) => $this->claimRow($c));
    }

    public function storeClaim(Request $request, CustodyItem $item)
    {
        $e = $request->user()->employee;
        abort_unless($e && $item->employee_id === $e->id, 403);
        abort_unless($item->type->is_cash && $item->status === 'in_use', 422, 'التسوية للعهد المالية القائمة فقط');
        if ($item->claims()->where('status', 'pending_finance')->exists()) {
            throw ValidationException::withMessages(['item' => 'توجد تسوية معلّقة لهذه العهدة']);
        }
        $d = $request->validate(['details' => 'required|string|max:2000', 'invoices' => 'required|array|min:1',
            'invoices.*.name' => 'required|string|max:120', 'invoices.*.amount' => 'required|numeric|min:0']);
        $claimed = round(array_sum(array_column($d['invoices'], 'amount')), 2);
        // §44: invoices may exceed the custody amount only with a prior approval (and within its limit, if set)
        if ($claimed > $item->value) {
            if (! $item->overspend_approved) {
                throw ValidationException::withMessages(['invoices' => 'مجموع الفواتير يتجاوز قيمة العهدة — لا يجوز إلا بموافقة مسبقة من المدير المالي (§44)']);
            }
            if ($item->overspend_limit && $claimed > $item->value + $item->overspend_limit) {
                throw ValidationException::withMessages(['invoices' => 'مجموع الفواتير يتجاوز الحد المعتمد مسبقًا ('.number_format($item->value + $item->overspend_limit, 2).')']);
            }
        }
        // §44: the settlement is documented by the original invoices — the scanned invoices are attached to it
        $request->validate(['attachment' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,heic'], ['attachment.required' => 'أرفق صور الفواتير والمستندات المؤيدة للتسوية (§44)']);
        $path = $request->file('attachment')->store('claims/'.now()->format('Y/m'), 'local');
        // status set explicitly: Eloquent does not read the column default back into the in-memory model
        $c = ExpenseClaim::create(['custody_item_id' => $item->id, 'employee_id' => $e->id, 'details' => $d['details'], 'invoices' => $d['invoices'],
            'claimed' => $claimed, 'status' => 'pending_finance', 'attachment_path' => $path]);
        Audit::notify(Access::usersWithRole('fin'), 'تسوية عهدة مالية بانتظار اعتمادك', $e->name.' — '.$item->ident, 'info', ['screen' => 'custody']);
        Audit::log('expense_claims', $c->id, 'create', null, ['claimed' => $claimed]);

        return response()->json($this->claimRow($c->load('item:id,ident,value')), 201);
    }

    /**
     * §44 finance decisions: (1) settle — approve and close; (2) return — back to the employee with the reason;
     * (3) close the unspent remainder either by refund to the company account (amount/date recorded) or by
     * deduction from next month's salary (one instalment, subject to the §42 half-wage rule — never forgiven).
     */
    public function decideClaim(Request $request, ExpenseClaim $claim)
    {
        abort_unless($request->user()->hasRole('fin', 'super'), 403, 'قرار التسوية للمدير المالي');
        abort_unless($claim->status === 'pending_finance', 422);
        abort_if($claim->employee_id && $claim->employee_id === $request->user()->employee_id, 403, 'لا يجوز اعتماد تسوية عهدتك بنفسك (§45/§46)');
        $d = $request->validate(['decision' => 'required|in:settle,to_salary,return,refund', 'note' => 'nullable|required_if:decision,return|string|max:500',
            'refund_amount' => 'nullable|required_if:decision,refund|numeric|min:0.01', 'refund_date' => 'nullable|required_if:decision,refund|date', 'refund_ref' => 'nullable|string|max:120'],
            ['note.required_if' => 'سبب الإرجاع إلزامي', 'refund_amount.required_if' => 'مبلغ الرد إلزامي', 'refund_date.required_if' => 'تاريخ الرد إلزامي']);
        $item = $claim->item;
        $remainder = round($item->value - $claim->claimed, 2);
        if ($d['decision'] === 'settle' && $remainder > 0.009) {
            throw ValidationException::withMessages(['decision' => 'يتبقى '.number_format($remainder, 2).' غير مصروف: اختر الإقفال برد المبلغ لحساب الشركة أو بخصمه من الراتب']);
        }
        if (in_array($d['decision'], ['refund', 'to_salary'], true) && $remainder <= 0) {
            throw ValidationException::withMessages(['decision' => 'لا يوجد مبلغ متبقٍ — اختر اعتماد التسوية وإقفال العهدة']);
        }
        if ($d['decision'] === 'refund' && round((float) $d['refund_amount'], 2) > $remainder) {
            throw ValidationException::withMessages(['refund_amount' => 'مبلغ الرد أكبر من المتبقي ('.number_format($remainder, 2).')']);
        }

        return DB::transaction(function () use ($claim, $d, $request, $item, $remainder) {
            $claim->decided_by = $request->user()->id;
            $claim->decided_at = now();
            $claim->decision_note = $d['note'] ?? null;

            if ($d['decision'] === 'return') {
                $claim->status = 'returned';
                $item->log('إرجاع التسوية لاستكمال المستندات', $d['note']);
                $item->save();
            } else {
                $claim->settled = $claim->claimed;
                $claim->status = ['settle' => 'settled', 'refund' => 'refunded', 'to_salary' => 'to_salary'][$d['decision']];
                $salary = $remainder;
                if ($d['decision'] === 'refund') {
                    $claim->refund_amount = round((float) $d['refund_amount'], 2);
                    $claim->refund_date = $d['refund_date'];
                    $claim->refund_ref = $d['refund_ref'] ?? null;
                    $salary = round($remainder - $claim->refund_amount, 2); // a partial refund: the rest from the salary
                }
                if ($d['decision'] === 'to_salary' || $salary > 0.009 && $d['decision'] === 'refund') {
                    $claim->salary_amount = $salary;
                    $period = now()->addMonthNoOverflow()->format('Y-m');
                    Advance::create(['employee_id' => $claim->employee_id, 'amount' => $salary, 'purpose' => 'رصيد عهدة مالية غير مسوّى — '.$item->ident,
                        'installments' => 1, 'monthly_amount' => $salary, 'paid' => 0, 'remaining' => $salary, 'status' => 'repaying',
                        'start_period' => $period, 'schedule' => [['period' => $period, 'amount' => $salary, 'status' => 'due']]]);
                }
                $item->log('تسوية العهدة المالية — '.ExpenseClaim::STATUSES[$claim->status], trim(($d['note'] ?? '').($claim->refund_amount ? ' · رُدّ '.number_format($claim->refund_amount, 2).' بتاريخ '.$claim->refund_date->format('d/m/Y') : '')));
                $item->status = 'available';
                $item->employee_id = null;
                $item->overspend_approved = false;
                $item->overspend_limit = null;
                $item->save();
            }
            $claim->save();
            Audit::log('expense_claims', $claim->id, $d['decision'], null, ['status' => $claim->status, 'refund' => $claim->refund_amount, 'salary' => $claim->salary_amount], $d['note'] ?? null);
            $this->notifyEmployee($claim->employee_id, 'قرار تسوية العهدة المالية: '.ExpenseClaim::STATUSES[$claim->status], $d['note'] ?? '');

            return $this->claimRow($claim->load('item:id,ident,value', 'employee:id,name,emp_no'));
        });
    }

    /** §44: prior approval allowing the invoices of a cash custody to exceed its amount (finance / group CEO). */
    public function overspend(Request $request, CustodyItem $item)
    {
        abort_unless($request->user()->hasRole('fin', 'super'), 403, 'الموافقة المسبقة للمدير المالي');
        abort_unless($item->type->is_cash && in_array($item->status, CustodyItem::OPEN, true), 422, 'للعهد النقدية القائمة فقط');
        $d = $request->validate(['approved' => 'required|boolean', 'limit' => 'nullable|numeric|min:0', 'note' => 'required|string|max:500']);
        $item->overspend_approved = $d['approved'];
        $item->overspend_limit = $d['approved'] ? ($d['limit'] ?? null) : null;
        $item->overspend_by = $request->user()->id;
        $item->overspend_note = $d['note'];
        $item->log($d['approved'] ? 'موافقة مسبقة على تجاوز قيمة العهدة'.(! empty($d['limit']) ? ' حتى '.number_format($d['limit'], 2) : '') : 'إلغاء الموافقة على التجاوز', $d['note']);
        $item->save();
        Audit::log('custody_items', $item->id, 'overspend', null, ['approved' => $d['approved'], 'limit' => $d['limit'] ?? null], $d['note']);

        return $this->refresh($item);
    }

    /** Purpose end date (or return from the mission) — starts the 10-working-day settlement clock (§44). */
    public function purposeEnd(Request $request, CustodyItem $item)
    {
        $u = $request->user();
        abort_unless($this->canManage($request, $item->company_id) || $u->hasRole('fin', 'super') || $u->employee_id === $item->employee_id
            || Access::for($u)->isManagerOf((int) $item->employee_id), 403);
        $d = $request->validate(['purpose_end' => 'required|date']);
        $item->purpose_end = $d['purpose_end'];
        $item->log('تحديد تاريخ انتهاء الغرض', $d['purpose_end']);
        $item->save();

        return $this->refresh($item);
    }

    /** Who may see the monthly reconciliation, and for which companies: finance and the group CEO (all), a company CEO (own). */
    private function reconCompanies(Request $request): array
    {
        $u = $request->user();
        $acc = $this->acc($request);
        $ids = $acc->companyIds();
        if (! $u->hasRole('fin', 'super')) {
            $ids = array_values(array_filter($ids, fn ($c) => $acc->isCeoOf($c)));
        }
        if ($f = $acc->focus()) {
            $ids = array_values(array_intersect($ids, [$f]));
        }
        if ($c = $request->integer('company_id')) {
            $ids = array_values(array_intersect($ids, [$c]));
        }

        return $ids;
    }

    /** §46: monthly reconciliation of outstanding advances and open cash custody per employee per company. */
    public function reconciliation(Request $request)
    {
        $ids = $this->reconCompanies($request);
        abort_unless($ids || $request->user()->hasRole('fin', 'super'), 403, 'تقرير المطابقة للمدير المالي والرئيس التنفيذي');

        return self::reconciliationData($ids, $request->input('period'));
    }

    public static function reconciliationData(array $companyIds, ?string $period = null): array
    {
        $period = $period && preg_match('/^\d{4}-\d{2}$/', $period) ? $period : now()->format('Y-m');
        $companies = \App\Models\Company::whereIn('id', $companyIds)->pluck('name', 'id');
        $empIds = Employee::withTrashed()->whereIn('company_id', $companyIds)->pluck('id');
        $advances = Advance::whereIn('employee_id', $empIds)->where('status', 'repaying')->where('remaining', '>', 0)->get()->groupBy('employee_id');
        $cash = CustodyItem::with('type:id,is_cash')->whereIn('employee_id', $empIds)->whereIn('status', CustodyItem::OPEN)
            ->whereHas('type', fn ($q) => $q->where('is_cash', true))->get()->groupBy('employee_id');
        $emps = Employee::withTrashed()->whereIn('id', $advances->keys()->merge($cash->keys())->unique())->get(['id', 'name', 'emp_no', 'company_id', 'status']);
        $rows = $emps->map(function ($e) use ($advances, $cash, $companies, $period) {
            $adv = $advances[$e->id] ?? collect();
            $overdueInst = $adv->flatMap(fn ($a) => $a->overdueInstalments($period));
            $items = $cash[$e->id] ?? collect();
            $overdueItems = $items->filter(fn ($i) => $i->isOverdue());
            $dues = $items->map(fn ($i) => $i->settle_due?->toDateString() ?? $i->computeSettleDue())->filter()->sort()->values();

            return ['employee_id' => $e->id, 'name' => $e->name, 'emp_no' => $e->emp_no, 'company_id' => $e->company_id, 'company' => $companies[$e->company_id] ?? '—',
                'terminated' => $e->status === 'terminated',
                'advances_count' => $adv->count(), 'advances_amount' => round($adv->sum('amount'), 2), 'advances_balance' => round($adv->sum('remaining'), 2),
                'overdue_instalments' => $overdueInst->count(), 'overdue_instalments_amount' => round($overdueInst->sum(fn ($s) => (float) $s['amount']), 2),
                'cash_count' => $items->count(), 'cash_amount' => round($items->sum('value'), 2),
                'cash_overdue' => $overdueItems->count(), 'cash_overdue_amount' => round($overdueItems->sum('value'), 2), 'next_settle_due' => $dues->first(),
                'cash_items' => $items->map(fn ($i) => ['id' => $i->id, 'ident' => $i->ident, 'value' => $i->value, 'settle_due' => $i->settle_due?->toDateString() ?? $i->computeSettleDue(), 'overdue' => $i->isOverdue()])->values(),
                'overdue' => $overdueInst->isNotEmpty() || $overdueItems->isNotEmpty()];
        })->sortBy([['overdue', 'desc'], ['company', 'asc'], ['name', 'asc']])->values();
        $byCompany = $rows->groupBy('company_id')->map(fn ($g, $cid) => ['company_id' => $cid, 'company' => $companies[$cid] ?? '—', 'employees' => $g->count(),
            'advances_balance' => round($g->sum('advances_balance'), 2), 'overdue_instalments_amount' => round($g->sum('overdue_instalments_amount'), 2),
            'cash_amount' => round($g->sum('cash_amount'), 2), 'cash_overdue_amount' => round($g->sum('cash_overdue_amount'), 2), 'overdue_employees' => $g->where('overdue', true)->count()])->values();

        return ['period' => $period, 'generated_at' => now()->toDateTimeString(), 'rows' => $rows, 'companies' => $byCompany,
            'totals' => ['advances_balance' => round($rows->sum('advances_balance'), 2), 'overdue_instalments_amount' => round($rows->sum('overdue_instalments_amount'), 2),
                'cash_amount' => round($rows->sum('cash_amount'), 2), 'cash_overdue_amount' => round($rows->sum('cash_overdue_amount'), 2), 'overdue_employees' => $rows->where('overdue', true)->count()]];
    }

    // ---------------- helpers ----------------

    private function validated(Request $r, ?CustodyItem $item = null): array
    {
        $d = $r->validate(['custody_type_id' => ($item ? 'sometimes|' : 'required|').'exists:custody_types,id', 'company_id' => ($item ? 'sometimes|' : 'required|').'exists:companies,id',
            'ident' => 'required|string|max:150', 'fields' => 'nullable|array', 'value' => 'nullable|numeric|min:0', 'condition' => 'nullable|string|max:120']);
        $d['value'] = $d['value'] ?? 0;

        return $d;
    }

    private function refresh(CustodyItem $item): array
    {
        return $this->row($item->fresh()->load('type', 'company:id,name', 'employee:id,name,emp_no')) + ['history' => $item->history];
    }

    private function row(CustodyItem $i): array
    {
        $cash = (bool) $i->type?->is_cash;
        $open = $i->employee_id && in_array($i->status, CustodyItem::OPEN, true);

        return ['id' => $i->id, 'ident' => $i->ident, 'type' => $i->type?->only('id', 'name', 'requires_ack', 'has_secret', 'is_cash'), 'company' => $i->company?->only('id', 'name'),
            'employee' => $i->employee?->only('id', 'name', 'emp_no'), 'value' => $i->value, 'status' => $i->status, 'status_label' => CustodyItem::STATUSES[$i->status] ?? $i->status,
            'has_secret' => (bool) $i->secret, 'handed_at' => collect($i->history)->last(fn ($h) => in_array($h['event'], ['تسليم', 'إقرار استلام'], true))['at'] ?? null,
            'disbursed_at' => $i->disbursed_at, 'purpose_end' => $i->purpose_end?->toDateString(),
            'settle_due' => $cash && $open ? ($i->settle_due?->toDateString() ?? $i->computeSettleDue()) : null,
            'overdue' => $cash && $open && $i->isOverdue(), 'ack_at' => $i->ack_at,
            'overspend_approved' => (bool) $i->overspend_approved, 'overspend_limit' => $i->overspend_limit];
    }

    private function claimRow(ExpenseClaim $c): array
    {
        return ['id' => $c->id, 'employee' => $c->employee?->only('id', 'name', 'emp_no'), 'item' => $c->item?->only('id', 'ident', 'value'), 'details' => $c->details,
            'invoices' => $c->invoices, 'claimed' => $c->claimed, 'settled' => $c->settled, 'remainder' => round(($c->item?->value ?? 0) - $c->claimed, 2),
            'status' => $c->status, 'status_label' => ExpenseClaim::STATUSES[$c->status] ?? $c->status, 'decision_note' => $c->decision_note, 'created_at' => $c->created_at, 'decided_at' => $c->decided_at,
            'refund_amount' => $c->refund_amount, 'refund_date' => $c->refund_date?->toDateString(), 'refund_ref' => $c->refund_ref, 'salary_amount' => $c->salary_amount];
    }

    private function notifyEmployee(int $employeeId, string $title, string $body): void
    {
        Audit::notify(User::where('employee_id', $employeeId)->value('id'), $title, $body, 'info', ['screen' => 'custody']);
    }
}
