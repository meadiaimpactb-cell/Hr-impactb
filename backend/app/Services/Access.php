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
        'CMP' => 'الشركات والباقات', 'RPT' => 'التقارير', 'ADM' => 'الصلاحيات', 'AUD' => 'التدقيق', 'COM' => 'الإعلانات',
        'SGN' => 'التوقيع الإلكتروني', 'INT' => 'التكامل', 'IMP' => 'الاستيراد',
    ];

    public const ACTIONS = ['view', 'add', 'edit', 'approve', 'export', 'publish', 'close'];

    public const NON_DELEGABLE = ['SEC', 'ADM'];

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

    /** Company ids in my scope. */
    public function companyIds(): array
    {
        $u = $this->user;
        if (! $u) {
            return [];
        }
        if ($u->hasRole('super', 'hr_mgr', 'fin')) {
            return Company::pluck('id')->all();
        }
        $ids = [];
        if ($u->hasRole('hr_staff')) {
            $ids = array_merge($ids, $this->hrStaffCompanies());
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

    private function hrStaffCompanies(): array
    {
        $p = $this->user->perms ?? [];
        if (! empty($p['valid_to']) && $p['valid_to'] < now()->toDateString()) {
            return [];
        }

        return array_map('intval', $p['companies'] ?? []);
    }

    /** Query of employees visible to the current user. */
    public function employees(): Builder
    {
        $u = $this->user;
        $q = Employee::query();
        if ($u->hasRole('super', 'hr_mgr', 'fin')) {
            return $q;
        }
        $companies = [];
        if ($u->hasRole('hr_staff')) {
            $companies = $this->hrStaffCompanies();
        }
        if ($u->hasRole('ceo') && $u->company_id) {
            $companies[] = $u->company_id;
        }
        if ($u->employee_id) {
            $companies = array_merge($companies, Company::where('ceo_employee_id', $u->employee_id)->pluck('id')->all());
        }

        return $q->where(function ($w) use ($companies, $u) {
            $w->whereIn('company_id', $companies ?: [0]);
            if ($u->employee_id) {
                $w->orWhere('manager_id', $u->employee_id)->orWhere('id', $u->employee_id);
            }
        });
    }

    public function canSeeEmployee(Employee $e): bool
    {
        return $this->employees()->whereKey($e->id)->exists();
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

        if ($u->hasRole('hr_staff') && ! in_array($op, self::NON_DELEGABLE, true)) {
            $p = $u->perms ?? [];
            $ok = in_array($action, $p['ops'][$op] ?? [], true)
                && (empty($p['valid_to']) || $p['valid_to'] >= now()->toDateString())
                && (! $companyId || in_array($companyId, array_map('intval', $p['companies'] ?? []), true));
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
        if ($u->hasRole('super', 'hr_mgr', 'hr_staff', 'fin', 'custodian')) {
            return $this->can($op, $action);
        }
        if ($u->hasRole('ceo')) {
            $cid = Employee::whereKey($u->employee_id)->value('company_id');

            return $this->can($op, $action, ['company_id' => \App\Models\Company::where('ceo_employee_id', $u->employee_id)->value('id') ?? $cid]);
        }
        $report = Employee::where('manager_id', $u->employee_id)->first();

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
        return self::$active ??= User::where('active', true)->get(['id', 'name', 'roles', 'perms', 'employee_id', 'company_id']);
    }

    public static function flushCache(): void
    {
        self::$active = null;
    }

    /** User ids of HR who serve a company (for notifications). */
    public static function hrUsersFor(?int $companyId, ?string $op = null): array
    {
        return self::activeUsers()->filter(fn (User $u) => $u->hasRole('hr_mgr')
            || ($u->hasRole('hr_staff') && in_array($companyId, array_map('intval', $u->perms['companies'] ?? []), true)
                && (! $op || array_intersect(['view', 'approve'], $u->perms['ops'][$op] ?? []))))
            ->pluck('id')->values()->all();
    }

    public static function usersWithRole(string $role): array
    {
        return self::activeUsers()->filter(fn (User $u) => $u->hasRole($role))->pluck('id')->values()->all();
    }
}
