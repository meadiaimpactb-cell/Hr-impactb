<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Role + scope based access control.
 * Operations (OPS) × actions × scope (companies) × limits × validity, as in the HC spec.
 */
class Access
{
    public const OPS = [
        'EMP' => 'ملفات الموظفين', 'PAY' => 'الرواتب والقسائم', 'ALW' => 'البدلات', 'ATT' => 'الحضور', 'LVE' => 'الإجازات',
        'CUS' => 'العهد', 'SEC' => 'كشف الأسرار', 'ADV' => 'السلف', 'VIO' => 'المخالفات', 'TRM' => 'إنهاء الخدمة',
        'PRF' => 'التقارير والتقييم', 'DEV' => 'التطوير', 'DOC' => 'الوثائق', 'ORG' => 'الهيكل والوظائف',
        'CMP' => 'الشركات والتصنيفات', 'RPT' => 'التقارير', 'ADM' => 'الصلاحيات', 'AUD' => 'التدقيق', 'COM' => 'الإعلانات',
        'SGN' => 'التوقيع الإلكتروني', 'INT' => 'التكامل', 'IMP' => 'الاستيراد',
    ];

    public const ACTIONS = ['view', 'add', 'edit', 'approve', 'export', 'publish', 'close'];

    public const NON_DELEGABLE = ['SEC', 'ADM'];

    /**
     * What the permissions editor can never grant (the policy keeps it with specific roles): secrets and permissions,
     * the audit log (oversight — HR manager and group CEO, §116), company data and classifications (group CEO), the
     * Odoo integration (system setting); and payroll approval / publication (finance and the group CEO — segregation
     * of duties). A delegate may still view, prepare and export payroll.
     */
    public const NOT_GRANTABLE = ['SEC', 'ADM', 'AUD', 'CMP', 'INT'];

    public const NOT_GRANTABLE_ACTIONS = ['PAY' => ['approve', 'publish']];

    public static function grantable(string $op, string $action): bool
    {
        return isset(self::OPS[$op]) && ! in_array($op, self::NOT_GRANTABLE, true) && ! in_array($action, self::NOT_GRANTABLE_ACTIONS[$op] ?? [], true);
    }

    public function __construct(public ?User $user) {}

    public static function for(?User $user = null): self
    {
        return new self($user ?? auth()->user());
    }

    public function emp(): ?Employee
    {
        return $this->user?->employee;
    }

    public function isManagerOf(Employee|int $e): bool
    {
        $e = $e instanceof Employee ? $e : Employee::find($e);

        return $e && $this->user?->employee_id && $e->manager_id === $this->user->employee_id;
    }

    public function isCeoOf(int|string|null $companyId): bool
    {
        $companyId = $companyId ? (int) $companyId : null;
        if (! $companyId || ! $this->user) {
            return false;
        }
        if ($this->user->hasRole('ceo') && $this->user->company_id === $companyId) {
            return true;
        }

        return $this->user->employee_id && Company::whereKey($companyId)->where('ceo_employee_id', $this->user->employee_id)->exists();
    }

    /** Company ids in my scope; with $op, HR staff keep only the companies where that operation was granted to them. */
    public function companyIds(?string $op = null, string $action = 'view'): array
    {
        $u = $this->user;
        if (! $u) {
            return [];
        }
        if ($u->hasRole('hr_mgr') && $u->hr_scope) {
            return array_map('intval', (array) $u->hr_scope); // §7: an HR manager limited to specific companies
        }
        if ($u->hasRole('super', 'hr_mgr', 'fin')) {
            return Company::pluck('id')->all();
        }
        $ids = [];
        if ($u->hasRole('hr_staff')) {
            $ids = array_merge($ids, $this->hrStaffCompanies($op, $action));
        }
        if ($u->hasRole('ceo') && $u->company_id) {
            $ids[] = $u->company_id;
        }
        if ($u->employee_id) {
            $ids = array_merge($ids, Company::where('ceo_employee_id', $u->employee_id)->pluck('id')->all());
            if (($e = $this->emp()) && $u->hasRole('custodian')) { // custodians keep assets for their own company; plain employees have no company scope
                $ids[] = $e->company_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function hrStaffCompanies(?string $op = null, string $action = 'view'): array
    {
        $p = $this->user->perms ?? [];
        if (! empty($p['valid_to']) && $p['valid_to'] < now()->toDateString()) {
            return [];
        }
        $ids = array_map('intval', $p['companies'] ?? []);

        return $op ? array_values(array_filter($ids, fn ($c) => in_array($action, self::staffOps($p, $c)[$op] ?? [], true))) : $ids;
    }

    /**
     * HR staff operations × actions inside one company: the company's own matrix when the HR manager customised it,
     * otherwise the shared matrix. Without a company: everything granted anywhere (list screens narrow by company later).
     */
    public static function staffOps(array $p, ?int $companyId = null): array
    {
        $per = $p['per_company'] ?? [];
        if ($companyId) {
            return $per[$companyId]['ops'] ?? $per[(string) $companyId]['ops'] ?? ($p['ops'] ?? []);
        }
        $all = $p['ops'] ?? [];
        foreach ($per as $c) {
            foreach ($c['ops'] ?? [] as $op => $actions) {
                $all[$op] = array_values(array_unique(array_merge($all[$op] ?? [], $actions)));
            }
        }

        return $all;
    }

    /** Approval limit of an HR staff member for an operation in a company (null = no limit). */
    public static function staffLimit(array $p, string $op, ?int $companyId = null): mixed
    {
        $per = $p['per_company'][$companyId] ?? $p['per_company'][(string) $companyId] ?? null;

        return ($per ? ($per['limits'][$op] ?? null) : null) ?? ($p['limits'][$op] ?? null);
    }

    /**
     * The company the user is working on (the company selector at the top of the portal, sent as X-Company): every
     * list in the system is narrowed to it. Ignored unless that company is within the user's scope. null = all companies.
     */
    public function focus(): ?int
    {
        $r = request();
        $id = (int) ($r?->header('X-Company') ?: 0);

        return $id && in_array($id, $this->companyIds(), true) ? $id : null;
    }

    /** Query of employees visible to the current user, narrowed to the selected company (lists). $op narrows HR staff per company. */
    public function employees(?string $op = null, string $action = 'view'): Builder
    {
        $q = $this->employeesAll($op, $action);
        if ($f = $this->focus()) {
            $q->where('employees.company_id', $f);
        }

        return $q;
    }

    /** Every employee in the user's scope regardless of the selected company (permission checks). */
    public function employeesAll(?string $op = null, string $action = 'view'): Builder
    {
        $u = $this->user;
        $q = Employee::query();
        if ($u->hasRole('hr_mgr') && $u->hr_scope) {
            return $q->whereIn('company_id', array_map('intval', (array) $u->hr_scope));
        }
        // finance sees every employee in its own areas only (pay, advances, end of service, reports); elsewhere its team
        if ($u->hasRole('super', 'hr_mgr') || ($u->hasRole('fin') && (! $op || in_array($op, ['PAY', 'ADV', 'TRM', 'RPT'], true)))) {
            return $q;
        }
        $companies = [];
        $named = [];
        if ($u->hasRole('hr_staff')) {
            $companies = $this->hrStaffCompanies($op, $action);
            // a delegation limited to named employees covers only them (inside its companies)
            if ($companies && ! empty($u->perms['employees'])) {
                $named = Employee::whereIn('id', array_map('intval', $u->perms['employees']))->whereIn('company_id', $companies)->pluck('id')->all();
                $companies = [];
            }
        }
        if ($u->hasRole('ceo') && $u->company_id) {
            $companies[] = $u->company_id;
        }
        if ($u->employee_id) {
            $companies = array_merge($companies, Company::where('ceo_employee_id', $u->employee_id)->pluck('id')->all());
        }

        return $q->where(function ($w) use ($companies, $u, $named) {
            $w->whereIn('company_id', $companies ?: [0]);
            if ($named) {
                $w->orWhereIn('id', $named);
            }
            if ($u->employee_id) {
                $w->orWhere('manager_id', $u->employee_id)->orWhere('id', $u->employee_id);
            }
        });
    }

    public function canSeeEmployee(Employee $e): bool
    {
        return $this->employeesAll()->whereKey($e->id)->exists();
    }

    /** can('LVE','approve',['company_id'=>..]|['employee'=>Employee]) */
    public function can(string $op, string $action = 'view', array $ctx = []): bool
    {
        $u = $this->user;
        if (! $u || ! $u->active) {
            return false;
        }
        if ($u->hasRole('super', 'hr_mgr')) {
            return true;
        }
        $emp = $ctx['employee'] ?? null;
        $companyId = isset($ctx['company_id']) ? (int) $ctx['company_id'] : $emp?->company_id;

        if ($u->hasRole('hr_staff') && self::grantable($op, $action)) {
            $p = $u->perms ?? [];
            $ok = in_array($action, self::staffOps($p, $companyId ?: null)[$op] ?? [], true)
                && (empty($p['valid_to']) || $p['valid_to'] >= now()->toDateString())
                && (! $companyId || in_array($companyId, array_map('intval', $p['companies'] ?? []), true))
                && (empty($p['employees']) || ! $emp || in_array((int) $emp->id, array_map('intval', $p['employees']), true));
            if ($ok) {
                return true;
            }
        }
        if ($u->hasRole('fin') && in_array($op, ['ADV', 'TRM', 'RPT', 'PAY'], true)) {
            return in_array($action, ['view', 'approve', 'export'], true) || $op === 'ADV';
        }
        if ($u->hasRole('custodian') && $op === 'CUS') { // IT / asset keeper: full custody handling, no secrets reveal
            return true;
        }
        if ($u->hasRole('ceo') && $this->isCeoOf($companyId)) {
            if (in_array($op, ['ADM', 'SEC', 'CMP'], true)) {
                return false;
            }

            return ! ($op === 'PAY' && in_array($action, ['approve', 'publish'], true));
        }
        if ($emp && $this->isManagerOf($emp) && in_array($op, ['EMP', 'ATT', 'LVE', 'PRF', 'DEV', 'DOC', 'CUS'], true)) {
            return in_array($action, ['view', 'approve', 'edit'], true);
        }
        if ($emp && $u->employee_id === $emp->id) {
            return $action === 'view';
        }

        return false;
    }

    /** Scope-level check (for list screens): can this user do $action on $op for *some* employee in their scope? */
    public function canAny(string $op, string $action = 'view'): bool
    {
        $u = $this->user;
        if (! $u || ! $u->active) {
            return false;
        }
        if ($u->hasRole('super', 'hr_mgr', 'hr_staff', 'fin', 'custodian') && $this->can($op, $action)) {
            return true;
        }
        if ($u->hasRole('ceo') && ! $u->hasRole('super', 'hr_mgr', 'hr_staff', 'fin', 'custodian')) {
            $cid = Employee::whereKey($u->employee_id)->value('company_id');

            return $this->can($op, $action, ['company_id' => \App\Models\Company::where('ceo_employee_id', $u->employee_id)->value('id') ?? $cid]);
        }
        // a finance / HR-staff / custody user who also manages people still follows his own team, like any manager
        $report = $u->employee_id ? Employee::where('manager_id', $u->employee_id)->first() : null;

        return $report ? $this->can($op, $action, ['employee' => $report]) : false;
    }

    public function canSeeSalary(Employee $e): bool
    {
        return $this->can('PAY', 'view', ['employee' => $e]);
    }

    private static ?\Illuminate\Support\Collection $active = null;

    /** Active users, loaded once per request (approver resolution runs per inbox row). */
    public static function activeUsers(): \Illuminate\Support\Collection
    {
        return self::$active ??= User::where('active', true)->get(['id', 'name', 'roles', 'perms', 'employee_id', 'company_id', 'hr_scope']);
    }

    public static function flushCache(): void
    {
        self::$active = null;
    }

    /**
     * HR managers who serve a company (§7): a company that has its own HR manager (one whose scope names it) sends its
     * requests to him only; otherwise to the group's HR managers (no scope). Any of them may approve.
     */
    public static function hrManagersFor(?int $companyId): array
    {
        $mgrs = self::activeUsers()->filter(fn (User $u) => $u->hasRole('hr_mgr'));
        if ($companyId) {
            $own = $mgrs->filter(fn (User $u) => $u->hr_scope && in_array($companyId, array_map('intval', (array) $u->hr_scope), true));
            if ($own->isNotEmpty()) {
                return $own->pluck('id')->values()->all();
            }
        }

        return $mgrs->filter(fn (User $u) => ! $u->hr_scope)->pluck('id')->values()->all() ?: $mgrs->pluck('id')->values()->all();
    }

    /** User ids of HR who serve a company (for notifications). */
    public static function hrUsersFor(?int $companyId, ?string $op = null): array
    {
        $mgrs = self::hrManagersFor($companyId);

        return self::activeUsers()->filter(fn (User $u) => ($u->hasRole('hr_mgr') && in_array($u->id, $mgrs, true))
            || ($u->hasRole('hr_staff') && in_array($companyId, array_map('intval', $u->perms['companies'] ?? []), true)
                && (! $op || array_intersect(['view', 'approve'], self::staffOps($u->perms ?? [], $companyId)[$op] ?? []))))
            ->pluck('id')->values()->all();
    }

    public static function usersWithRole(string $role): array
    {
        return self::activeUsers()->filter(fn (User $u) => $u->hasRole($role))->pluck('id')->values()->all();
    }
}
