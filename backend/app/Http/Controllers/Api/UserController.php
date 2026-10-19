<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Delegation;
use App\Models\User;
use App\Services\Access;
use App\Services\ApprovalService;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Login accounts, roles, delegated permissions (hr_staff) and temporary delegations. */
class UserController extends Controller
{
    private function authorizeAdmin(Request $r): void
    {
        abort_unless($r->user()->hasRole('super', 'hr_mgr'), 403, 'إدارة الحسابات صلاحية الرئيس التنفيذي للمجموعة ومدير الموارد البشرية');
    }

    /** Roles the current user may assign. */
    private function assignable(User $me): array
    {
        return $me->hasRole('super') ? array_keys(User::ROLES) : array_values(array_diff(array_keys(User::ROLES), ['super', 'hr_mgr']));
    }

    /** Active accounts (name only) — lets any approver pick a delegate. */
    public function options(Request $request)
    {
        return User::where('active', true)->where('id', '!=', $request->user()->id)->orderBy('name')->get(['id', 'name', 'login', 'roles'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'login' => $u->login, 'role' => User::ROLES[$u->roles[0] ?? 'emp'] ?? '']);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        return User::with('employee:id,name,emp_no,company_id', 'employee.company:id,name')
            ->when($request->input('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('login', 'like', "%$s%")))
            ->orderBy('id')->get()->map(fn (User $u) => $u->only('id', 'name', 'login', 'email', 'roles', 'perms', 'company_id', 'active', 'last_login_at', 'must_change_password', 'invited_at', 'invite_accepted_at', 'created_at')
                + ['employee' => $u->employee ? $u->employee->only('id', 'name', 'emp_no') + ['company' => $u->employee->company?->name] : null]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $d = $this->validated($request);
        $password = $d['password'] ?? 'Hc@'.random_int(100000, 999999);
        $user = User::create([
            'name' => $d['name'] ?? (isset($d['employee_id']) ? \App\Models\Employee::find($d['employee_id'])?->name : null) ?? $d['login'],
            'login' => $d['login'], 'email' => $d['email'] ?? null, 'password' => $password,
            'employee_id' => $d['employee_id'] ?? null, 'roles' => $d['roles'], 'company_id' => $d['company_id'] ?? null,
            'active' => $d['active'] ?? true, 'must_change_password' => true,
        ]);
        Audit::log('users', $user->id, 'create', null, ['login' => $user->login, 'roles' => $user->roles]);

        $inv = \App\Services\InviteService::issue($user, false, $request->user());

        return response()->json(['user' => $user, 'temp_password' => $password, 'invite_url' => $inv['url'], 'emailed' => $inv['emailed']], 201);
    }

    /** Why the current admin may not change this account (null = allowed). */
    private function guardTarget(Request $request, User $user, string $action): ?string
    {
        $me = $request->user();
        if ($user->id === $me->id) {
            return 'لا يمكنك '.$action.' حسابك أنت';
        }
        if ($user->hasRole('super') && ! $me->hasRole('super')) {
            return 'حساب الرئيس التنفيذي للمجموعة يديره الرئيس التنفيذي للمجموعة فقط';
        }
        if ($user->hasRole('super') && in_array($action, ['حذف', 'إيقاف'], true)
            && User::where('active', true)->where('id', '!=', $user->id)->get()->filter(fn ($u) => $u->hasRole('super'))->isEmpty()) {
            return 'لا يمكن '.$action.' آخر حساب نشط للمدير العام';
        }

        return null;
    }

    /** Deletes the login account; the employee record stays. Accounts with history are suspended instead. */
    private function removeAccount(User $user): string
    {
        $user->tokens()->delete();
        // anyone who has used the system keeps the account (suspended) so decisions and the audit trail stay attributable
        $used = $user->last_login_at
            || \Illuminate\Support\Facades\DB::table('approval_actions')->where('user_id', $user->id)->orWhere('acting_for_user_id', $user->id)->exists()
            || \Illuminate\Support\Facades\DB::table('audit_logs')->where('user_id', $user->id)->exists();
        if ($used) {
            return 'has_history'; // never deleted: the caller suggests suspending instead
        }
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
                \Illuminate\Support\Facades\DB::table('device_tokens')->where('user_id', $user->id)->delete();
                $user->delete();
            });
            Audit::log('users', $user->id, 'delete', ['login' => $user->login]);

            return 'deleted';
        } catch (\Illuminate\Database\QueryException) {
            $user->forceFill(['active' => false, 'invite_token' => null, 'invite_secret' => null])->save();
            Audit::log('users', $user->id, 'deactivate', null, ['reason' => 'has history — kept for audit']);

            return 'deactivated';
        }
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        if ($why = $this->guardTarget($request, $user, 'حذف')) {
            abort(422, $why);
        }
        $r = $this->removeAccount($user);
        if ($r === 'has_history') {
            return response()->json(['result' => 'has_history', 'message' => 'لا يمكن حذف هذا الحساب لأن عليه حركات داخل النظام (دخول أو قرارات أو سجلات). يمكنك إيقافه بدل الحذف.'], 422);
        }

        return ['result' => $r, 'message' => 'حُذف الحساب'];
    }

    /** Bulk actions on many accounts: invite | activate | deactivate | delete. Returns per-action counts and skipped rows with reasons. */
    public function bulk(Request $request)
    {
        $this->authorizeAdmin($request);
        $d = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer|exists:users,id', 'action' => ['required', Rule::in(['invite', 'activate', 'deactivate', 'delete'])]]);
        $label = ['invite' => 'دعوة', 'activate' => 'تفعيل', 'deactivate' => 'إيقاف', 'delete' => 'حذف'][$d['action']];
        $done = $emailed = $deactivated = 0;
        $skipped = [];
        foreach (User::with('employee')->whereIn('id', $d['ids'])->get() as $u) {
            if ($d['action'] !== 'invite' && $d['action'] !== 'activate' && ($why = $this->guardTarget($request, $u, $label))) {
                $skipped[] = ['login' => $u->login, 'reason' => $why];
                continue;
            }
            switch ($d['action']) {
                case 'invite':
                    if (! $u->active) { $skipped[] = ['login' => $u->login, 'reason' => 'الحساب موقوف']; break; }
                    $inv = \App\Services\InviteService::issue($u, (bool) $u->invite_accepted_at, $request->user());
                    $inv['emailed'] ? $emailed++ : $skipped[] = ['login' => $u->login, 'reason' => $u->email ? 'تعذر الإرسال' : 'لا يوجد بريد'];
                    $done++;
                    break;
                case 'activate':
                    $u->update(['active' => true]); Audit::log('users', $u->id, 'activate'); $done++;
                    break;
                case 'deactivate':
                    $u->update(['active' => false]); $u->tokens()->delete(); Audit::log('users', $u->id, 'deactivate'); $done++;
                    break;
                case 'delete':
                    $r = $this->removeAccount($u);
                    $r === 'deleted' ? $done++ : $skipped[] = ['login' => $u->login, 'reason' => 'عليه حركات — أوقفه بدل الحذف'];
                    break;
            }
        }

        return ['action' => $d['action'], 'done' => $done, 'emailed' => $emailed, 'deactivated' => $deactivated, 'skipped' => $skipped];
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        abort_if($user->hasRole('super') && ! $request->user()->hasRole('super'), 403);
        $d = $this->validated($request, $user);
        $before = $user->only('login', 'roles', 'active', 'company_id');
        unset($d['password']);
        if ($request->has('hr_scope') && $request->user()->hasRole('super')) { // §7: companies an HR manager is limited to (empty = all)
            $scope = array_values(array_filter(array_map('intval', (array) $request->input('hr_scope', []))));
            $d['hr_scope'] = $scope ?: null;
        }
        $user->update($d);
        if (! $user->active) {
            $user->tokens()->delete(); // force sign-out on deactivation
        }
        Audit::log('users', $user->id, 'update', $before, $user->only('login', 'roles', 'active', 'company_id'));
        // §11: when an approver leaves the chain (role removed / deactivated) HR must name the replacement right away
        $lost = array_diff(array_intersect($before['roles'] ?? [], ['hr_mgr', 'ceo', 'fin', 'mgr', 'super']), $user->active ? $user->roles : []);
        $reports = $user->employee_id ? \App\Models\Employee::where('manager_id', $user->employee_id)->where('status', '!=', 'terminated')->count() : 0;
        $ceoOf = $user->employee_id ? \App\Models\Company::where('ceo_employee_id', $user->employee_id)->pluck('name')->all() : [];
        $pending = $this->pendingWith($user);
        $user->setAttribute('handover', ['needed' => (bool) ($lost || (! $user->active && ($reports || $ceoOf))) && ($reports || $ceoOf || $pending), 'lost_roles' => array_values($lost), 'reports' => $reports, 'ceo_of' => $ceoOf, 'pending' => $pending]);

        return $user;
    }

    /** Pending requests whose current stage this user decides. */
    private function pendingWith(User $u): int
    {
        return \App\Services\ApprovalService::inboxFor($u)->count();
    }

    /**
     * Handover (§11): the replacement takes over the direct reports, the company CEO seat(s) and therefore every pending
     * request that was waiting for the previous person. Pending requests with nobody left fall to the group CEO automatically.
     */
    public function handover(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        $d = $request->validate(['to_employee_id' => ['required', 'exists:employees,id', Rule::notIn([$user->employee_id])], 'reason' => 'nullable|string|max:300']);
        $to = \App\Models\Employee::findOrFail($d['to_employee_id']);
        $moved = ['reports' => 0, 'companies' => 0, 'requests' => 0];
        if ($user->employee_id) {
            $moved['reports'] = \App\Models\Employee::where('manager_id', $user->employee_id)->where('id', '!=', $to->id)->update(['manager_id' => $to->id]);
            $moved['companies'] = \App\Models\Company::where('ceo_employee_id', $user->employee_id)->update(['ceo_employee_id' => $to->id]);
        }
        $toUser = User::where('employee_id', $to->id)->where('active', true)->first();
        foreach (\App\Models\ApprovalRequest::with('employee')->where('status', 'pending')->get() as $r) {
            $now = \App\Services\ApprovalService::approvers($r);
            if ($toUser && in_array($toUser->id, $now, true)) {
                $moved['requests']++;
            }
        }
        if ($toUser && $moved['requests']) {
            Audit::notify($toUser->id, 'انتقلت إليك طلبات معلّقة', "{$moved['requests']} طلب بانتظار اعتمادك بعد تسلّمك مهام {$user->name}", 'approval', ['screen' => 'approvals']);
        }
        Audit::log('users', $user->id, 'handover', ['from' => $user->employee_id], ['to' => $to->id] + $moved, $d['reason'] ?? null);

        return $moved;
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        $tmp = 'Hc@'.random_int(100000, 999999);
        $user->update(['password' => $tmp, 'must_change_password' => true]);
        $user->tokens()->delete();
        Audit::log('users', $user->id, 'resetPassword');

        $inv = \App\Services\InviteService::issue($user, true, $request->user());

        return ['temp_password' => $tmp, 'login' => $user->login, 'email' => $user->email, 'invite_url' => $inv['url'], 'emailed' => $inv['emailed']];
    }

    /** (Re)send the invitation link — no temporary password involved. */
    public function invite(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        $inv = \App\Services\InviteService::issue($user, (bool) $user->invite_accepted_at, $request->user());

        return ['login' => $user->login, 'email' => $user->email, 'invite_url' => $inv['url'], 'emailed' => $inv['emailed'], 'expires_at' => $inv['expires_at']];
    }

    /** Delegated permissions for HR staff: companies × ops × actions × limits × validity. */
    public function updatePerms(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        $d = $request->validate([
            'companies' => 'array', 'companies.*' => 'integer|exists:companies,id',
            'ops' => 'array', 'limits' => 'array', 'valid_to' => 'nullable|date', 'reason' => 'nullable|string|max:300',
        ]);
        $ops = [];
        foreach ($d['ops'] ?? [] as $op => $actions) {
            if (! isset(Access::OPS[$op]) || in_array($op, Access::NON_DELEGABLE, true)) {
                continue;
            }
            $ops[$op] = array_values(array_intersect((array) $actions, Access::ACTIONS));
        }
        $before = $user->perms;
        // Approvals this user can no longer make fall back to the original approvers automatically.
        $user->update(['perms' => ['companies' => $d['companies'] ?? [], 'ops' => array_filter($ops), 'limits' => array_filter($d['limits'] ?? []),
            'valid_to' => $d['valid_to'] ?? null, 'granted_by' => $request->user()->id, 'granted_at' => now()->toDateString()]]);
        Audit::log('users', $user->id, 'permissions', $before, $user->perms, $d['reason'] ?? null);

        return $user;
    }

    public function roles(Request $request)
    {
        return ['roles' => User::ROLES, 'assignable' => $this->assignable($request->user()), 'ops' => Access::OPS, 'actions' => Access::ACTIONS, 'non_delegable' => Access::NON_DELEGABLE];
    }

    // ---------------- delegations ----------------
    public function delegations(Request $request)
    {
        $u = $request->user();

        return Delegation::with('fromUser:id,name', 'toUser:id,name')
            ->when(! $u->hasRole('super', 'hr_mgr'), fn ($q) => $q->where(fn ($w) => $w->where('from_user_id', $u->id)->orWhere('to_user_id', $u->id)))
            ->orderByDesc('id')->limit(200)->get();
    }

    public function storeDelegation(Request $request)
    {
        $u = $request->user();
        $d = $request->validate([
            'from_user_id' => 'nullable|exists:users,id', 'to_user_id' => 'required|exists:users,id|different:from_user_id',
            'ops' => 'array', 'ops.*' => Rule::in(array_keys(Access::OPS)), 'valid_from' => 'required|date', 'valid_to' => 'required|date|after_or_equal:valid_from',
            'reason' => 'required|string|max:300',
        ]);
        $from = $d['from_user_id'] ?? $u->id;
        abort_unless($from === $u->id || $u->hasRole('super', 'hr_mgr'), 403);
        if ($from == $d['to_user_id']) {
            throw ValidationException::withMessages(['to_user_id' => 'لا يمكن التفويض للنفس']);
        }
        $del = Delegation::create(['from_user_id' => $from, 'to_user_id' => $d['to_user_id'], 'ops' => array_values(array_diff($d['ops'] ?? [], Access::NON_DELEGABLE)),
            'valid_from' => $d['valid_from'], 'valid_to' => $d['valid_to'], 'reason' => $d['reason']]);
        Audit::log('delegations', $del->id, 'create', null, $del->toArray(), $d['reason']);
        Audit::notify($d['to_user_id'], 'فُوّضت بالاعتمادات', 'من '.User::find($from)->name.' حتى '.$d['valid_to'], 'info', ['screen' => 'approvals']);

        return $del->load('fromUser:id,name', 'toUser:id,name');
    }

    public function revokeDelegation(Request $request, Delegation $delegation)
    {
        $u = $request->user();
        abort_unless($delegation->from_user_id === $u->id || $u->hasRole('super', 'hr_mgr'), 403);
        $delegation->update(['status' => 'revoked']);
        Audit::log('delegations', $delegation->id, 'revoke');

        return $delegation;
    }

    // ---------------- approval flows ----------------
    public function flows()
    {
        return collect(ApprovalService::types())->map(fn ($t) => [
            'type' => $t->code, 'label' => $t->name, 'custom' => $t->version > 1, 'active' => $t->active, 'stages' => $t->flow,
        ])->values()->all();
    }

    public function flowRoles()
    {
        return ApprovalService::roleLabels();
    }

    public function saveFlow(Request $request, string $type)
    {
        $this->authorizeAdmin($request);
        $rt = ApprovalService::type($type);
        abort_unless($rt, 404);
        $d = $request->validate([
            'stages' => 'required|array|min:1|max:6', 'stages.*.role' => ['required', Rule::in(array_keys(ApprovalService::ROLES))],
            'stages.*.label' => 'nullable|string|max:60', 'stages.*.skip_if' => ['nullable', 'regex:/^\w+\s*(<=|>=|<|>|==)\s*[\d.]+$/'],
            'reason' => 'required|string|max:300',
        ]);
        $before = $rt->flow;
        $rt->update(['flow' => array_map(fn ($s) => array_filter($s), $d['stages']), 'version' => $rt->version + 1]);
        Audit::log('request_types', $rt->code, 'flow', $before, $rt->flow, $d['reason']);
        ApprovalService::flushTypes();

        return ['ok' => true, 'pending_unaffected' => ApprovalRequest::where('type', $type)->where('status', 'pending')->count()];
    }

    private function validated(Request $r, ?User $u = null): array
    {
        $d = $r->validate([
            'name' => 'nullable|string|max:150',
            'login' => ['required', 'string', 'max:80', Rule::unique('users')->ignore($u?->id)],
            'email' => ['nullable', 'email', Rule::unique('users')->ignore($u?->id)],
            'password' => 'nullable|string|min:8',
            'employee_id' => ['nullable', 'exists:employees,id', Rule::unique('users')->ignore($u?->id)],
            'roles' => 'required|array|min:1', 'roles.*' => Rule::in(array_keys(User::ROLES)),
            'company_id' => 'nullable|exists:companies,id', 'active' => 'boolean',
        ]);
        $bad = array_diff($d['roles'], $this->assignable($r->user()));
        if ($bad && (! $u || array_diff($bad, $u->roles ?? []))) {
            throw ValidationException::withMessages(['roles' => 'لا تملك صلاحية منح هذا الدور']);
        }
        if (in_array('ceo', $d['roles'], true) && empty($d['company_id'])) {
            throw ValidationException::withMessages(['company_id' => 'حدد الشركة لرئيس الشركة']);
        }
        $d['login'] = Str::of($d['login'])->trim()->toString();

        return $d;
    }
}
