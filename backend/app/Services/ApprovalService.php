<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\ApprovalRequest;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Delegation;
use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\RequestType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unified approval engine. Flows are data: default per type, overridable in settings "approvalFlows".
 * A stage may be skipped with a simple rule, e.g. "days<=2".
 */
class ApprovalService
{
    public const ROLES = [
        'mgr' => 'المدير المباشر', 'hr' => 'الموارد البشرية', 'hr_staff' => 'موظف الموارد البشرية', 'hr_mgr' => 'مدير الموارد البشرية',
        'ceo' => 'رئيس الشركة', 'fin' => 'المدير المالي', 'super' => 'المدير العام',
    ];

    private static ?array $types = null;

    /** Request types are data (request_types table); cached per request. */
    public static function types(bool $activeOnly = false): array
    {
        self::$types ??= RequestType::orderBy('sort')->orderBy('id')->get()->keyBy('code')->all();

        return $activeOnly ? array_filter(self::$types, fn ($t) => $t->active) : self::$types;
    }

    public static function flushTypes(): void
    {
        self::$types = null;
    }

    public static function type(string $code): ?RequestType
    {
        return self::types()[$code] ?? null;
    }

    public static function roleLabels(): array
    {
        return array_merge(self::ROLES, (array) Settings::get('roleLabels', null, []));
    }

    public static function labels(bool $activeOnly = false): array
    {
        return array_map(fn ($t) => $t->name, self::types($activeOnly));
    }

    public static function label(string $type): string
    {
        return self::type($type)?->name ?? $type;
    }

    public static function flowFor(string $type, array $data): array
    {
        $flow = self::type($type)?->flow ?: [['role' => 'mgr']];
        $flow = array_values(array_filter($flow, function ($s) use ($data) {
            if (empty($s['skip_if']) || ! preg_match('/^(\w+)\s*(<=|>=|<|>|==)\s*([\d.]+)$/', $s['skip_if'], $m)) {
                return true;
            }
            $v = (float) ($data[$m[1]] ?? 0);
            $n = (float) $m[3];
            $skip = match ($m[2]) { '<=' => $v <= $n, '>=' => $v >= $n, '<' => $v < $n, '>' => $v > $n, default => $v == $n };

            return ! $skip;
        }));

        $roles = self::roleLabels();

        return array_map(fn ($s) => ['role' => $s['role'], 'label' => $s['label'] ?? ($roles[$s['role']] ?? $s['role'])], $flow ?: [['role' => 'mgr']]);
    }

    public static function submit(Employee $e, string $type, array $data, ?array $flow = null, ?string $attachment = null): ApprovalRequest
    {
        if (! self::type($type)?->active) {
            throw ValidationException::withMessages(['type' => 'هذا النوع من الطلبات غير مفعّل حاليًا']);
        }

        return DB::transaction(function () use ($e, $type, $data, $flow, $attachment) {
            $req = ApprovalRequest::create([
                'type' => $type, 'employee_id' => $e->id, 'company_id' => $e->company_id, 'data' => $data,
                'flow' => $flow ?? self::flowFor($type, $data), 'stage' => 0, 'status' => 'pending',
                'created_by' => auth()->id(), 'attachment_path' => $attachment,
            ]);
            $req->actions()->create(['stage' => 0, 'user_id' => auth()->id(), 'decision' => 'submit']);
            Audit::log('requests', $req->id, 'create', null, ['type' => $type, 'employee' => $e->id]);
            self::skipStages($req);
            if ($req->stage >= count($req->flow)) { // every stage skipped
                $req->status = 'approved';
                $req->decided_at = now();
                $req->save();
                self::apply($req);

                return $req;
            }
            $req->save();
            Audit::notify(self::approvers($req), 'طلب '.self::label($type).' بانتظار اعتمادك', $e->name.' — '.self::summary($req), 'approval', ['screen' => 'approvals'], $req->id);

            return $req;
        });
    }

    /**
     * Skips the company-CEO stage when it does not apply: the company has no CEO, the CEO is the requester, or the
     * CEO is the group CEO (who gives the final approval anyway). Each skip is recorded on the request.
     */
    public static function skipStages(ApprovalRequest $r): void
    {
        while ($r->stage < count($r->flow) && ($r->flow[$r->stage]['role'] ?? null) === 'ceo') {
            $ceoEmp = Company::find($r->company_id)?->ceo_employee_id;
            $ceoUser = $ceoEmp ? User::where('employee_id', $ceoEmp)->where('active', true)->first() : null;
            $why = ! $ceoUser ? 'لا يوجد مدير تنفيذي للشركة' : ($ceoEmp === $r->employee_id ? 'مقدم الطلب هو المدير التنفيذي للشركة' : ($ceoUser->hasRole('super') ? 'المدير التنفيذي للشركة هو المدير التنفيذي للمجموعة (يعتمد في المرحلة الأخيرة)' : null));
            if (! $why) {
                return;
            }
            $r->actions()->create(['stage' => $r->stage, 'user_id' => auth()->id() ?? 0, 'decision' => 'skip', 'reason' => 'تخطٍّ تلقائي: '.$why]);
            $r->stage++;
        }
    }

    /** Puts an extra stage before the final group-CEO approval (which always stays last). */
    public static function insertBeforeFinal(array $flow, array $stage): array
    {
        $last = end($flow);
        if ($last && ($last['role'] ?? null) === 'super') {
            array_splice($flow, count($flow) - 1, 0, [$stage]);

            return $flow;
        }
        $flow[] = $stage;

        return $flow;
    }

    /** User ids allowed to decide the current stage (incl. active delegations, excl. requester). */
    public static function approvers(ApprovalRequest $r, ?int $stage = null): array
    {
        $stage ??= $r->stage;
        $st = $r->flow[$stage] ?? null;
        if (! $st) {
            return [];
        }
        $e = $r->relationLoaded('employee') ? $r->employee : $r->employee()->first();
        $cid = $r->company_id;
        $ids = match ($st['role']) {
            // direct manager, and the HR manager may also decide this stage (either one is enough)
            'mgr' => ($e?->manager_id && ($u = User::where('employee_id', $e->manager_id)->where('active', true)->value('id'))) ? array_values(array_unique([$u, ...Access::usersWithRole('hr_mgr')])) : self::hrApprovers($r),
            'hr' => self::hrApprovers($r),
            'hr_staff' => self::hrApprovers($r, staffOnly: true) ?: Access::usersWithRole('hr_mgr'),
            'hr_mgr' => Access::usersWithRole('hr_mgr'),
            'ceo' => ($ceo = Company::find($cid)?->ceo_employee_id) && ($u = User::where('employee_id', $ceo)->value('id')) ? [$u] : Access::usersWithRole('super'),
            'fin' => Access::usersWithRole('fin'),
            'super' => Access::usersWithRole('super'),
            'user' => [$st['user_id'] ?? 0],
            default => [],
        };
        $requester = User::where('employee_id', $r->employee_id)->value('id');
        $ids = array_values(array_diff($ids, [$requester]));
        if (! $ids) { // conflict of interest → escalate
            $ids = array_values(array_diff(Access::usersWithRole('super'), [$requester]));
        }
        $delegates = Delegation::active()->whereIn('from_user_id', $ids)->get()
            ->filter(fn ($d) => empty($d->ops) || in_array(self::op($r->type), $d->ops, true))->pluck('to_user_id')->all();

        return array_values(array_diff(array_unique([...$ids, ...$delegates]), [$requester]));
    }

    /**
     * HR approvers for a request: every HR manager, plus HR staff whose delegated permissions
     * cover this company + operation with the "approve" action, are still valid, and whose
     * limit (days for leaves, amount for advances) is not exceeded.
     */
    public static function hrApprovers(ApprovalRequest $r, bool $staffOnly = false): array
    {
        $op = self::op($r->type);
        $metric = match ($op) { 'LVE' => (float) ($r->data['days'] ?? 0), 'ADV' => (float) ($r->data['amount'] ?? 0), default => null };
        $today = now()->toDateString();
        $staff = Access::activeUsers()->filter(function (User $u) use ($r, $op, $metric, $today) {
            if (! $u->hasRole('hr_staff')) {
                return false;
            }
            $p = $u->perms ?? [];
            if (! empty($p['valid_to']) && $p['valid_to'] < $today) {
                return false;
            }
            if (! in_array($r->company_id, array_map('intval', $p['companies'] ?? []), true) || ! in_array('approve', $p['ops'][$op] ?? [], true)) {
                return false;
            }
            $limit = $p['limits'][$op] ?? null;

            return $limit === null || $limit === '' || $metric === null || $metric <= (float) $limit;
        })->pluck('id')->all();

        return $staffOnly ? array_values($staff) : array_values(array_unique([...Access::usersWithRole('hr_mgr'), ...$staff]));
    }

    public static function op(string $type): string
    {
        return self::type($type)?->op ?? 'EMP';
    }

    public static function decide(ApprovalRequest $r, string $decision, ?string $reason, User $by): ApprovalRequest
    {
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'الطلب ليس بانتظار الاعتماد']);
        }
        $approvers = self::approvers($r);
        if (! in_array($by->id, $approvers, true)) {
            abort(403, 'لست ضمن معتمدي هذه المرحلة');
        }
        if ($decision === 'return' && ! $reason) {
            throw ValidationException::withMessages(['reason' => 'سبب الإرجاع إلزامي']);
        }

        return DB::transaction(function () use ($r, $decision, $reason, $by, $approvers) {
            // Re-read under a row lock so two approvers of the same stage cannot both advance it
            $fresh = ApprovalRequest::lockForUpdate()->find($r->id);
            if ($fresh->status !== 'pending' || $fresh->stage !== $r->stage) {
                throw ValidationException::withMessages(['request' => 'تغيّرت حالة الطلب — أعد التحميل']);
            }
            $r->stage = $fresh->stage;
            $original = $approvers[0];
            $actingFor = Delegation::active()->where('to_user_id', $by->id)->where('from_user_id', $original)->exists() ? $original : null;
            $r->actions()->create(['stage' => $r->stage, 'user_id' => $by->id, 'acting_for_user_id' => $actingFor, 'decision' => $decision, 'reason' => $reason]);
            $requester = User::where('employee_id', $r->employee_id)->value('id');

            if ($decision === 'approve') {
                $r->stage++;
                // the next stage falls to the same person only: approve it in the same step instead of asking twice
                self::skipStages($r);
                while ($r->stage < count($r->flow) && self::approvers($r) === [$by->id]) {
                    $r->actions()->create(['stage' => $r->stage, 'user_id' => $by->id, 'decision' => 'approve', 'reason' => 'اعتماد تلقائي: نفس المعتمد للمرحلة السابقة']);
                    $r->stage++;
                    self::skipStages($r);
                }
                if ($r->stage >= count($r->flow)) {
                    $r->status = 'approved';
                    $r->decided_at = now();
                    $r->save();
                    self::apply($r);
                    Audit::notify($requester, 'اعتُمد طلب '.self::label($r->type), self::summary($r), 'ok', ['screen' => 'requests']);
                } else {
                    $r->save();
                    Audit::notify(self::approvers($r), 'طلب '.self::label($r->type).' بانتظار اعتمادك', $r->employee->name.' — '.self::summary($r), 'approval', ['screen' => 'approvals'], $r->id);
                    Audit::notify($requester, 'تقدّم طلبك إلى المرحلة التالية', self::summary($r), 'info', ['screen' => 'requests']);
                }
            } else {
                $r->status = 'returned';
                $r->decided_at = now();
                $r->save();
                Audit::notify($requester, 'أُرجع طلب '.self::label($r->type), $reason, 'bad', ['screen' => 'requests']);
            }
            Audit::log('requests', $r->id, $decision, null, ['status' => $r->status, 'stage' => $r->stage], $reason);

            return $r;
        });
    }

    /** Side-effects once a request is fully approved. */
    public static function apply(ApprovalRequest $r): void
    {
        $e = $r->employee;
        $d = $r->data;
        switch (self::type($r->type)?->handler) {
            case 'leave':
                LeaveLedger::create(['employee_id' => $e->id, 'year' => (int) substr($d['from'], 0, 4), 'leave_type' => $d['type'], 'entry' => 'used',
                    'days' => -($d['days'] ?? 0), 'note' => 'استهلاك — إجازة معتمدة', 'request_id' => $r->id, 'occurred_on' => $d['from']]);
                if (! empty($d['delegate']) && ! empty($d['substitute_id'])) {
                    $from = User::where('employee_id', $e->id)->value('id');
                    $to = User::where('employee_id', $d['substitute_id'])->value('id');
                    if ($from && $to) {
                        Delegation::create(['from_user_id' => $from, 'to_user_id' => $to, 'ops' => [], 'valid_from' => $d['from'], 'valid_to' => $d['to'], 'reason' => 'تفويض تلقائي مع الإجازة #'.$r->id]);
                    }
                }
                $e->addCareer('إجازة معتمدة', ($d['type_name'] ?? $d['type'])." {$d['days']} يوم ({$d['from']} → {$d['to']})");
                if (! empty($d['substitute_id']) && ($sub = User::where('employee_id', $d['substitute_id'])->value('id'))) {
                    Audit::notify($sub, 'أنت البديل خلال إجازة '.$e->name, "من {$d['from']} إلى {$d['to']}: تستلم المهام الطارئة".(! empty($d['handover']) ? ' — '.$d['handover'] : ''), 'info', ['screen' => 'requests']);
                }
                break;
            case 'correction':
                $rec = AttendanceRecord::firstOrNew(['employee_id' => $e->id, 'work_date' => $d['date']]);
                $rec->status = 'present';
                if (! empty($d['time'])) {
                    $t = Carbon::parse($d['date'].' '.$d['time']);
                    if (str_contains($d['kind'], 'انصراف')) {
                        $rec->check_out = $t;
                    } else {
                        $rec->check_in = $t;
                    }
                }
                if ($rec->check_in) {
                    $rec->late_minutes = AttendanceService::lateMinutes($e, $rec->check_in);
                }
                if ($rec->check_in && $rec->check_out) {
                    $rec->early_minutes = AttendanceService::earlyMinutes($e, $rec->check_in, $rec->check_out);
                }
                $rec->pending = false;
                $rec->corrected = true;
                $rec->save();
                break;
            case 'balance_adj':
                LeaveLedger::create(['employee_id' => $e->id, 'year' => now()->year, 'leave_type' => $d['type'], 'entry' => 'adjust',
                    'days' => $d['after'] - $d['before'], 'note' => 'تعديل معتمد: '.($d['reason'] ?? ''), 'request_id' => $r->id, 'occurred_on' => now()->toDateString()]);
                break;
            case 'allowance':
            case 'salary_change':
                $isBasic = self::type($r->type)?->handler === 'salary_change';
                $key = $isBasic ? 'basic' : ($d['component'] ?? throw ValidationException::withMessages(['component' => 'البدل غير محدد']));
                $from = $d['effective'] ?? now()->toDateString();
                $e->components()->where('key', $key)->whereNull('valid_to')->update(['valid_to' => Carbon::parse($from)->subDay()->toDateString()]);
                $e->components()->create(['key' => $key, 'name' => $d['name'] ?? 'الأجر الأساسي', 'amount' => $d['after'], 'source' => 'custom', 'valid_from' => $from, 'approval_request_id' => $r->id]);
                $e->addCareer($isBasic ? 'تغيير الأجر' : 'تغيير بدل', ($d['name'] ?? 'الأساسي').": {$d['before']} → {$d['after']}");
                break;
            case 'benefit':
                $cb = $e->custom_benefits ?? [];
                $cb[$d['benefit']] = is_numeric($d['after']) ? (float) $d['after'] : $d['after'];
                $e->update(['custom_benefits' => $cb]);
                $e->addCareer('استثناء مزايا', "{$d['benefit']}: {$d['before']} → {$d['after']}");
                break;
            case 'resignation':
                app(\App\Http\Controllers\Api\TerminationController::class)->createCase($e, 'resign', $d['computed_last'], $d['reason'] ?? null, auth()->id(), $r->id);
                break;
            case 'reopen': // month lock lifted for N days (one employee, or every report of the company for that period)
                $until = now()->addDays((int) ($d['days'] ?? 2))->toDateString();
                \App\Models\MonthlyReport::where('period', $d['period'] ?? '')->where(fn ($q) => $q->where('employee_id', $e->id)->orWhere('company_id', $e->company_id))->update(['reopen_until' => $until]);
                \App\Models\MonthlyReport::firstOrCreate(['employee_id' => $e->id, 'period' => $d['period'] ?? now()->format('Y-m')], ['company_id' => $e->company_id, 'items' => [], 'status' => 'draft', 'reopen_until' => $until]);
                break;
            case 'policy': // approved policy change lands in the settings table with the request as its reason
                Settings::set($d['key'], is_numeric($d['value']) ? $d['value'] + 0 : (in_array(strtolower((string) $d['value']), ['true', 'false'], true) ? $d['value'] === 'true' : $d['value']), ! empty($d['company_id']) ? (int) $d['company_id'] : null, 'طلب تعديل سياسة #'.$r->id.' — '.($d['reason'] ?? ''));
                break;
            case 'custody': // custodians prepare and hand the item from the custody screen
                Audit::notify([...Access::usersWithRole('custodian'), ...Access::hrUsersFor($e->company_id, 'CUS')], 'طلب عهدة معتمد — جهّز وسلّم', $e->name.' — '.($d['kind'] ?? ''), 'approval', ['screen' => 'custody']);
                break;
            case 'letter':
                \App\Http\Controllers\Api\LetterController::issue($e, $d['kind'], $d['to'] ?? null, $d['lang'] ?? 'ar', auth()->id(), $r->id);
                break;
            case 'trip':
                $e->addCareer('رحلة عمل', ($d['destination'] ?? '').' · '.($d['from'] ?? '').' → '.($d['to'] ?? ''));
                if (! empty($d['advance']) && (float) $d['advance'] > 0 && ($ct = \App\Models\CustodyType::where('is_cash', true)->where('active', true)->first())) {
                    $item = new \App\Models\CustodyItem(['custody_type_id' => $ct->id, 'company_id' => $e->company_id, 'ident' => 'عهدة رحلة — '.($d['destination'] ?? '').' #'.$r->id, 'fields' => [],
                        'value' => (float) $d['advance'], 'employee_id' => $e->id, 'status' => 'in_use', 'history' => [], 'trip_request_id' => $r->id]);
                    $item->log('تسليم', 'عهدة مالية لرحلة عمل معتمدة', $e->id);
                    $item->save();
                    Audit::notify(Access::usersWithRole('fin'), 'عهدة مالية لرحلة عمل', $e->name.' — '.number_format((float) $d['advance'], 2).' ر.س', 'info', ['screen' => 'custody']);
                }
                break;
            case 'advance':
                $n = (int) ($d['installments'] ?? 1);
                $per = round(((float) $d['amount']) / $n, 2);
                $start = now()->addMonthNoOverflow();
                $schedule = [];
                for ($i = 0; $i < $n; $i++) {
                    $amt = $i === $n - 1 ? round($d['amount'] - $per * ($n - 1), 2) : $per; // last instalment absorbs rounding
                    $schedule[] = ['period' => $start->copy()->addMonthsNoOverflow($i)->format('Y-m'), 'amount' => $amt, 'status' => 'due'];
                }
                Advance::create(['employee_id' => $e->id, 'amount' => $d['amount'], 'purpose' => $d['purpose'] ?? null, 'installments' => $n,
                    'monthly_amount' => $per, 'paid' => 0, 'remaining' => $d['amount'], 'status' => 'repaying',
                    'start_period' => $schedule[0]['period'], 'schedule' => $schedule, 'request_id' => $r->id]);
                $e->addCareer('سلفة معتمدة', number_format($d['amount'], 2)." ر.س على {$n} أقساط");
                break;
        }
    }

    public static function summary(ApprovalRequest $r): string
    {
        $d = $r->data;

        return match ($r->type) {
            'leave' => ($d['type_name'] ?? $d['type'])." · {$d['days']} يوم · {$d['from']} → {$d['to']}",
            'advance' => number_format($d['amount'] ?? 0).' ر.س على '.($d['installments'] ?? 1).' أقساط',
            'correction' => ($d['kind'] ?? '').' · '.($d['date'] ?? ''),
            'allowance', 'salary_change' => ($d['name'] ?? 'الأساسي').': '.self::humanValue($d['before'] ?? null).' → '.self::humanValue($d['after'] ?? null),
            'balance_adj', 'benefit' => self::benefitLabel($d['type'] ?? $d['benefit'] ?? '').': '.self::humanValue($d['before'] ?? null).' → '.self::humanValue($d['after'] ?? null),
            'trip' => ($d['destination'] ?? '').' · '.($d['from'] ?? '').' → '.($d['to'] ?? ''),
            'letter' => ($d['kind'] ?? '').' — '.($d['to'] ?? ''),
            'resignation' => 'آخر يوم محسوب '.($d['computed_last'] ?? ''),
            default => self::customSummary($r->type, $d),
        };
    }

    /** Benefit/balance key → Arabic label (config + Settings overrides). */
    public static function benefitLabel(string $key): string
    {
        $labels = array_merge((array) config('hc.benefitLabels'), (array) Settings::get('benefitLabels'));

        return (string) ($labels[$key] ?? \App\Models\LeaveType::where('code', $key)->value('name') ?? $key);
    }

    /** true/false → نعم/لا, numbers formatted, null → —. */
    public static function humanValue($v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_bool($v) || $v === 'true' || $v === 'false') {
            return ($v === true || $v === 'true') ? 'نعم' : 'لا';
        }
        if (is_numeric($v)) {
            return (float) $v == (int) $v ? number_format((float) $v) : number_format((float) $v, 2);
        }

        return (string) $v;
    }

    /** Custom (form-defined) types: first two non-empty fields in form order. */
    private static function customSummary(string $type, array $d): string
    {
        $out = [];
        foreach (self::type($type)?->form ?? [] as $f) {
            $v = $d[$f['key']] ?? null;
            if ($v !== null && $v !== '' && ! is_array($v) && count($out) < 2) {
                $out[] = ($f['label'] ?? $f['key']).': '.(is_bool($v) ? ($v ? 'نعم' : 'لا') : $v);
            }
        }

        return implode(' · ', $out);
    }

    /** Pending requests the given user can decide now (efficient: filters in PHP over pending set). */
    public static function inboxFor(User $u)
    {
        return ApprovalRequest::with('employee:id,name,emp_no,company_id,manager_id', 'company:id,name')
            ->where('status', 'pending')->orderBy('created_at')->get()
            ->filter(fn ($r) => in_array($u->id, self::approvers($r), true))->values();
    }
}
