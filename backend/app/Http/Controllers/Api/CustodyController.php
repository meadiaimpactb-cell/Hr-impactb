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
 * → return requested → HR inspects → back to stock, or lost. Secrets are encrypted at
 * rest and every reveal is logged with a reason. Cash custody is settled by an expense
 * claim that finance either approves or converts into a salary deduction.
 */
class CustodyController extends Controller
{
    private function acc(Request $r): Access { return Access::for($r->user()); }

    private function canManage(Request $r, ?int $companyId = null): bool
    {
        return $this->acc($r)->can('CUS', 'edit', $companyId ? ['company_id' => $companyId] : []);
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
        $empIds = $acc->canAny('CUS', 'view') ? $acc->employees()->pluck('id') : collect([$request->user()->employee_id]);

        return CustodyItem::with('type:id,name,requires_ack,has_secret,is_cash', 'company:id,name', 'employee:id,name,emp_no')
            ->where(fn ($q) => $q->whereIn('company_id', $companies)->orWhereIn('employee_id', $empIds))
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
        abort_unless($u->employee_id === $item->employee_id || $this->acc($request)->can('CUS', 'view', ['company_id' => $item->company_id]), 403);

        return $this->row($item) + [
            'history' => $item->history, 'fields' => $item->fields, 'condition' => $item->condition,
            'has_secret' => (bool) $item->secret, 'secret_rotated_at' => $item->secret_rotated_at,
            'secret_logs' => $this->acc($request)->can('SEC', 'view') ? $item->secretLogs()->with('user:id,name')->limit(20)->get() : [],
            'claims' => $item->claims()->latest()->get(),
            'can_manage' => $this->canManage($request, $item->company_id),
            'can_reveal' => (bool) $item->secret && $this->acc($request)->can('SEC', 'view'),
            'can_set_secret' => $item->type->has_secret && $this->acc($request)->can('SEC', 'edit'),
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
        $item->update($this->validated($request, $item));
        Audit::log('custody_items', $item->id, 'update', $before, $item->only('ident', 'fields', 'value', 'condition'));

        return $this->row($item->load('type', 'company:id,name', 'employee:id,name,emp_no'));
    }

    /** Hand an available item to an employee. Requires ack unless the type says otherwise. */
    public function hand(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id), 403);
        abort_unless($item->status === 'available', 422, 'العهدة ليست متاحة للتسليم');
        $d = $request->validate(['employee_id' => 'required|exists:employees,id']);
        $item->employee_id = $d['employee_id'];
        $item->status = $item->type->requires_ack ? 'pending_ack' : 'in_use';
        $item->log($item->status === 'pending_ack' ? 'طلب تسليم' : 'تسليم', null, $d['employee_id']);
        $item->save();
        Audit::log('custody_items', $item->id, 'hand', null, ['employee' => $d['employee_id']]);
        $this->notifyEmployee($d['employee_id'], $item->status === 'pending_ack' ? 'عهدة بانتظار إقرارك' : 'سُلّمت لك عهدة', $item->ident);

        return $this->refresh($item);
    }

    /** The employee confirms receipt (recorded with timestamp + device as the acknowledgement). */
    public function ack(Request $request, CustodyItem $item)
    {
        abort_unless($request->user()->employee_id === $item->employee_id, 403);
        abort_unless($item->status === 'pending_ack', 422, 'لا يوجد إقرار مطلوب');
        $item->status = 'in_use';
        $item->log('إقرار استلام', 'وُقّع من '.substr((string) $request->userAgent(), 0, 60));
        $item->save();
        Audit::log('custody_items', $item->id, 'ack');

        return $this->refresh($item);
    }

    public function requestReturn(Request $request, CustodyItem $item)
    {
        abort_unless($request->user()->employee_id === $item->employee_id, 403);
        abort_unless($item->status === 'in_use', 422);
        $item->status = 'return_requested';
        $item->log('طلب استرداد', $request->input('note'));
        $item->save();
        Audit::notify(Access::hrUsersFor($item->company_id), 'طلب استرداد عهدة', $request->user()->name.' — '.$item->ident, 'info', ['screen' => 'custody']);

        return $this->refresh($item);
    }

    /** HR/custodian takes the item back for inspection (from in_use or return_requested). */
    public function receive(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id), 403);
        abort_unless(in_array($item->status, ['in_use', 'return_requested'], true), 422);
        $item->status = 'inspecting';
        $item->log('استلام للفحص');
        $item->save();

        return $this->refresh($item);
    }

    /** Close the inspection: back to stock, or lost/damaged (no automatic deduction — handled by policy/decision). */
    public function close(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id), 403);
        abort_unless($item->status === 'inspecting', 422, 'يجب استلام العهدة للفحص أولًا');
        $d = $request->validate(['result' => 'required|in:ok,lost', 'note' => 'required|string|max:500']);
        $prev = $item->employee_id;
        $item->log($d['result'] === 'ok' ? 'فحص وإغلاق — سليمة' : 'فحص — مفقودة/تالفة', $d['note'], $prev);
        $item->status = $d['result'] === 'ok' ? 'available' : 'lost';
        $item->employee_id = $d['result'] === 'ok' ? null : $prev;
        $item->save();
        Audit::log('custody_items', $item->id, 'close', null, ['result' => $d['result']], $d['note']);

        return $this->refresh($item);
    }

    public function transfer(Request $request, CustodyItem $item)
    {
        abort_unless($this->canManage($request, $item->company_id), 403);
        abort_unless($item->status === 'in_use', 422);
        $d = $request->validate(['employee_id' => 'required|exists:employees,id|not_in:'.$item->employee_id]);
        $item->log('نقل من '.($item->employee?->name ?? '—'), null, $d['employee_id']);
        $item->employee_id = $d['employee_id'];
        $item->status = $item->type->requires_ack ? 'pending_ack' : 'in_use';
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
        $empIds = $this->acc($request)->employees()->pluck('id');

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
        if ($claimed > $item->value) {
            throw ValidationException::withMessages(['invoices' => 'مجموع الفواتير يتجاوز قيمة العهدة']);
        }
        $path = $request->file('attachment')?->store('claims/'.now()->format('Y/m'), 'local');
        // status set explicitly: Eloquent does not read the column default back into the in-memory model
        $c = ExpenseClaim::create(['custody_item_id' => $item->id, 'employee_id' => $e->id, 'details' => $d['details'], 'invoices' => $d['invoices'],
            'claimed' => $claimed, 'status' => 'pending_finance', 'attachment_path' => $path]);
        Audit::notify(Access::usersWithRole('fin'), 'تسوية عهدة مالية بانتظار اعتمادك', $e->name.' — '.$item->ident, 'info', ['screen' => 'custody']);
        Audit::log('expense_claims', $c->id, 'create', null, ['claimed' => $claimed]);

        return response()->json($this->claimRow($c->load('item:id,ident,value')), 201);
    }

    /**
     * Finance decides: settle (item closes), or convert the unspent remainder into a one-instalment
     * salary deduction, or return the claim for correction.
     */
    public function decideClaim(Request $request, ExpenseClaim $claim)
    {
        abort_unless($request->user()->hasRole('fin', 'super'), 403, 'قرار التسوية للمدير المالي');
        abort_unless($claim->status === 'pending_finance', 422);
        $d = $request->validate(['decision' => 'required|in:settle,to_salary,return', 'note' => 'nullable|string|max:500']);

        return DB::transaction(function () use ($claim, $d, $request) {
            $item = $claim->item;
            $claim->decided_by = $request->user()->id;
            $claim->decided_at = now();
            $claim->decision_note = $d['note'] ?? null;

            if ($d['decision'] === 'return') {
                $claim->status = 'returned';
            } else {
                $claim->settled = $claim->claimed;
                $claim->status = $d['decision'] === 'settle' ? 'settled' : 'to_salary';
                $remainder = round($item->value - $claim->claimed, 2);
                if ($d['decision'] === 'to_salary' && $remainder > 0) {
                    $period = now()->addMonthNoOverflow()->format('Y-m');
                    Advance::create(['employee_id' => $claim->employee_id, 'amount' => $remainder, 'purpose' => 'رصيد عهدة مالية غير مسوّى — '.$item->ident,
                        'installments' => 1, 'monthly_amount' => $remainder, 'paid' => 0, 'remaining' => $remainder, 'status' => 'repaying',
                        'start_period' => $period, 'schedule' => [['period' => $period, 'amount' => $remainder, 'status' => 'due']]]);
                }
                $item->log('تسوية العهدة المالية — '.ExpenseClaim::STATUSES[$claim->status], $d['note'] ?? null);
                $item->status = 'available';
                $item->employee_id = null;
                $item->save();
            }
            $claim->save();
            Audit::log('expense_claims', $claim->id, $d['decision'], null, ['status' => $claim->status], $d['note'] ?? null);
            $this->notifyEmployee($claim->employee_id, 'قرار تسوية العهدة المالية: '.ExpenseClaim::STATUSES[$claim->status], $d['note'] ?? '');

            return $this->claimRow($claim->load('item:id,ident,value', 'employee:id,name,emp_no'));
        });
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
        return ['id' => $i->id, 'ident' => $i->ident, 'type' => $i->type?->only('id', 'name', 'requires_ack', 'has_secret', 'is_cash'), 'company' => $i->company?->only('id', 'name'),
            'employee' => $i->employee?->only('id', 'name', 'emp_no'), 'value' => $i->value, 'status' => $i->status, 'status_label' => CustodyItem::STATUSES[$i->status] ?? $i->status,
            'has_secret' => (bool) $i->secret, 'handed_at' => collect($i->history)->last(fn ($h) => in_array($h['event'], ['تسليم', 'إقرار استلام'], true))['at'] ?? null];
    }

    private function claimRow(ExpenseClaim $c): array
    {
        return ['id' => $c->id, 'employee' => $c->employee?->only('id', 'name', 'emp_no'), 'item' => $c->item?->only('id', 'ident', 'value'), 'details' => $c->details,
            'invoices' => $c->invoices, 'claimed' => $c->claimed, 'settled' => $c->settled, 'remainder' => round(($c->item?->value ?? 0) - $c->claimed, 2),
            'status' => $c->status, 'status_label' => ExpenseClaim::STATUSES[$c->status], 'decision_note' => $c->decision_note, 'created_at' => $c->created_at, 'decided_at' => $c->decided_at];
    }

    private function notifyEmployee(int $employeeId, string $title, string $body): void
    {
        Audit::notify(User::where('employee_id', $employeeId)->value('id'), $title, $body, 'info', ['screen' => 'custody']);
    }
}
