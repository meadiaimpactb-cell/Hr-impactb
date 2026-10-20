<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $acc = Access::for();
        $q = $acc->employees()->with('company:id,name,code', 'jobTitle:id,name', 'manager:id,name', 'user:id,employee_id,login,active,last_login_at,invited_at,invite_accepted_at,photo_path')
            ->select('id', 'emp_no', 'name', 'name_en', 'company_id', 'job_title_id', 'manager_id', 'dept', 'hire_date', 'contract_type', 'punch_required', 'status', 'email', 'phone', 'email_enabled');

        $status = $request->input('status', 'active');
        if ($status === 'active') {
            $q->where('status', '!=', 'terminated');
        } elseif ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($c = $request->integer('company_id')) {
            $q->where('company_id', $c);
        }
        if ($s = trim((string) $request->input('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('name_en', 'like', "%$s%")->orWhere('emp_no', 'like', "%$s%")->orWhere('dept', 'like', "%$s%"));
        }
        $sort = in_array($request->input('sort'), ['name', 'emp_no', 'hire_date'], true) ? $request->input('sort') : 'emp_no';

        return $q->orderBy($sort, $request->input('dir') === 'desc' ? 'desc' : 'asc')->paginate(min(5000, $request->integer('per_page', 25))); // the portal table loads the whole scope at once (per_page=5000)
    }

    /** Lightweight list for select boxes. */
    public function options(Request $request)
    {
        // Colleagues in my company (names only) — used to pick a leave substitute.
        if ($request->boolean('colleagues') && ($me = $request->user()->employee)) {
            return Employee::where('company_id', $me->company_id)->where('status', 'active')->where('id', '!=', $me->id)
                ->orderBy('name')->get(['id', 'name', 'emp_no', 'company_id']);
        }

        return Access::for()->employees()->where('status', '!=', 'terminated')
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->orderBy('name')->get(['id', 'name', 'emp_no', 'company_id']);
    }

    public function show(Employee $employee)
    {
        $acc = Access::for();
        abort_unless($acc->canSeeEmployee($employee), 403);
        $employee->load('company.tier', 'jobTitle', 'manager:id,name', 'schedule', 'locations', 'career.user:id,name', 'user:id,employee_id,company_id,login,roles,active,last_login_at,photo_path');
        $salary = $acc->canSeeSalary($employee);
        $period = now()->format('Y-m');

        return [
            'employee' => $employee->makeHidden($salary ? [] : ['bank_iban']),
            'reports_count' => $employee->reports()->count(),
            'service' => $this->servicePretty($employee),
            'can' => ['edit' => $acc->can('EMP', 'edit', ['employee' => $employee]), 'salary' => $salary, 'hr' => $acc->user->isHr()],
            'salary' => $salary ? [
                'components' => $employee->activeComponents(),
                'gross' => $employee->monthlyGross(),
                'considered' => $employee->componentAmount('basic') + $employee->componentAmount('housing') + $employee->componentAmount('transport'),
                'gosi' => (function () use ($employee) {
                    $g = \App\Services\PayrollService::gosiFor($employee);
                    $base = min($g['wage_cap'], $employee->componentAmount('basic') + $employee->componentAmount('housing'));

                    return $g + ['base' => $base, 'employee' => round($base * $g['employee_rate'] / 100, 2), 'employer' => round($base * $g['employer_rate'] / 100, 2)];
                })(),
            ] : null,
            'attendance' => AttendanceService::summary($employee, $period),
            'balances' => LeaveService::balances($employee),
            'tier' => $employee->company?->tier?->only(['id', 'name', 'benefits']),
        ];
    }

    public function store(Request $request)
    {
        $acc = Access::for();
        if (! $request->user()->hasRole('super', 'hr_mgr') && ($own = $request->user()->employee?->company_id ?? $request->user()->company_id)) {
            $request->merge(['company_id' => $own]); // regular HR users register employees in their own company only
        }
        $data = $this->validated($request);
        $data['emp_no'] = $data['emp_no'] ?? EmployeeExtrasController::next((int) $data['company_id']);
        $data['schedule_id'] ??= Company::whereKey($data['company_id'])->value('default_schedule_id'); // the company's working hours by default
        $extra = $request->validate(['basic' => 'nullable|numeric|min:0', 'allowances' => 'nullable|array|max:30', 'allowances.*.name' => 'required|string|max:80', 'allowances.*.amount' => 'required|numeric|min:0']);
        abort_unless($acc->can('EMP', 'add', ['company_id' => $data['company_id']]), 403);
        if (! $request->boolean('quick')) { // §17: the employee is registered with complete data at hiring
            $missing = array_filter(['manager_id' => (int) ($data['job_level'] ?? 0) === 1 ? 'x' : ($data['manager_id'] ?? null), 'job_title_id' => $data['job_title_id'] ?? null,
                'schedule_id' => $data['schedule_id'] ?? null, 'national_id' => $data['national_id'] ?? null, 'bank_iban' => $data['bank_iban'] ?? null], fn ($v) => ! $v);
            $names = ['manager_id' => 'المشرف المباشر', 'job_title_id' => 'المسمى الوظيفي', 'schedule_id' => 'جدول الدوام', 'national_id' => 'رقم الهوية أو الإقامة أو الجواز', 'bank_iban' => 'الآيبان'];
            if ($missing) {
                throw \Illuminate\Validation\ValidationException::withMessages(array_map(fn ($k) => $names[$k].' إلزامي عند التعيين (§17)', array_combine(array_keys($missing), array_keys($missing))));
            }
        }

        $e = DB::transaction(function () use ($data, $request) {
            $basic = (float) $request->input('basic', 0);
            $locs = $data['location_ids'] ?? [];
            unset($data['location_ids']);
            $data['status'] = 'active';
            $data['service_companies'] = [$data['company_id']];
            $e = Employee::create($data);
            $e->locations()->sync($locs);
            $b = Company::with('tier')->find($e->company_id)->tier?->benefits ?? [];
            $comps = [['key' => 'basic', 'name' => 'الأجر الأساسي', 'amount' => $basic, 'source' => 'contract']];
            $comps[] = ['key' => 'housing', 'name' => 'بدل السكن', 'amount' => round($basic * ($b['housingPct'] ?? 0) / 100, 2), 'source' => 'tier'];
            // §39: transport = 10% of the basic wage, at least 350 a month (tier values)
            $comps[] = ['key' => 'transport', 'name' => 'بدل النقل', 'amount' => max(round($basic * (float) ($b['transportPct'] ?? 0) / 100, 2), (float) ($b['transportMin'] ?? $b['transport'] ?? 0)), 'source' => 'tier'];
            if (! empty($b['comm'])) {
                $comps[] = ['key' => 'comm', 'name' => 'بدل الاتصال', 'amount' => $b['comm'], 'source' => 'tier'];
            }
            // allowances typed in the form: same name as a package allowance replaces it, others are added
            $known = ['بدل السكن' => 'housing', 'بدل النقل' => 'transport', 'بدل المواصلات' => 'transport', 'بدل الاتصال' => 'comm', 'بدل الجوال' => 'comm'];
            foreach ($request->input('allowances', []) as $i => $al) {
                $key = $known[trim($al['name'])] ?? 'alw'.($i + 1);
                $comps = array_values(array_filter($comps, fn ($c) => $c['key'] !== $key));
                $comps[] = ['key' => $key, 'name' => trim($al['name']), 'amount' => (float) $al['amount'], 'source' => 'contract'];
            }
            $comps = array_values(array_filter($comps, fn ($c) => $c['key'] === 'basic' ? $basic > 0 : (float) $c['amount'] > 0));
            foreach ($comps as $c) {
                $e->components()->create($c + ['valid_from' => $e->hire_date]);
            }
            $e->insurance = ['class' => $e->insuranceClass() ?? 'C', 'dependents' => 0, 'card' => null];
            if ($e->bank_iban) {
                $e->iban_verified_at = now(); // §38: registered at hiring from the bank's IBAN certificate
            }
            $e->save();
            $e->career()->create(['type' => 'تعيين', 'detail' => $e->company->name.' · '.($e->jobTitle?->name).' · '.\App\Models\Lookup::name('contract_types', $e->contract_type) ?? $e->contract_type, 'occurred_on' => $e->hire_date, 'user_id' => auth()->id()]);
            if ($request->boolean('create_user')) {
                $tmp = $request->input('password') ?: 'Hc@'.random_int(10000, 99999);
                $user = User::create(['name' => $e->name, 'login' => $request->input('login') ?: $e->emp_no, 'email' => $e->email_enabled ? $e->email : null,
                    'password' => $tmp, 'employee_id' => $e->id, 'roles' => ['emp'], 'must_change_password' => true]);
                $e->setAttribute('temp_password', $tmp); // fallback, returned once so HR can hand it over
                $e->setAttribute('login', $user->login);
                $inv = \App\Services\InviteService::issue($user, false, $request->user()); // invitation link (emailed when SMTP is configured)
                $e->setAttribute('invite_url', $inv['url']);
                $e->setAttribute('emailed', $inv['emailed']);
            }
            Audit::log('employees', $e->id, 'create', null, ['name' => $e->name]);

            return $e;
        });

        return response()->json($e, 201);
    }

    public function update(Request $request, Employee $employee)
    {
        abort_unless(Access::for()->can('EMP', 'edit', ['employee' => $employee]), 403);
        $data = $this->validated($request, $employee);
        if (! $request->user()->isHr()) { // direct managers may only touch operational fields, never contract/payroll identity
            $data = array_intersect_key($data, array_flip(['dept', 'phone', 'email', 'name_en', 'schedule_id', 'location_ids', 'flex_enabled', 'daily_note_required']));
        }
        if (array_key_exists('bank_iban', $data) && strtoupper(preg_replace('/\s+/', '', (string) $data['bank_iban'])) !== strtoupper((string) $employee->bank_iban)) {
            // §38: after hiring the account changes only through a request with the bank certificate (HR verifies, finance approves)
            abort_unless($request->user()->hasRole('super'), 422, 'يُعدَّل الحساب البنكي بطلب «تعديل الحساب البنكي» مع شهادة البنك (§38)');
            $data['iban_verified_at'] = now();
        }
        if (array_key_exists('religion', $data) && \Illuminate\Support\Facades\Schema::hasColumn('employees', 'muslim')) {
            $data['muslim'] = $data['religion'] !== 'other'; // Ramadan hours (§24) follow the same field
        }
        $labels = ['job_title_id' => 'تغيير الوظيفة', 'manager_id' => 'تغيير المدير المباشر', 'company_id' => 'نقل بين الشركات', 'contract_type' => 'تغيير نوع الدوام', 'dept' => 'نقل بين الإدارات'];
        $before = $employee->only(array_keys($data));

        DB::transaction(function () use ($employee, $data, $labels) {
            if (array_key_exists('location_ids', $data)) {
                $employee->locations()->sync($data['location_ids'] ?? []);
                unset($data['location_ids']);
            }
            foreach ($labels as $k => $label) {
                if (array_key_exists($k, $data) && $employee->$k != $data[$k]) {
                    $name = fn ($v) => match ($k) {
                        'job_title_id' => JobTitle::find($v)?->name, 'manager_id' => Employee::find($v)?->name,
                        'company_id' => Company::find($v)?->name, 'contract_type' => \App\Models\Lookup::name('contract_types', $v) ?? $v, default => $v,
                    };
                    $employee->addCareer($label, ($name($employee->$k) ?? '—').' → '.($name($data[$k]) ?? '—'));
                }
            }
            $employee->update($data);
            if ($employee->user && (array_key_exists('email', $data) || array_key_exists('email_enabled', $data))) {
                $employee->user->forceFill(['email' => $employee->email_enabled ? $employee->email : null])->save(); // keep the login account's email in sync
            }
        });
        Audit::log('employees', $employee->id, 'update', $before, $data);

        return $employee->fresh();
    }

    private function validated(Request $r, ?Employee $e = null): array
    {
        return $r->validate([
            'emp_no' => [$e ? 'required' : 'nullable', 'string', 'max:30', Rule::unique('employees')->ignore($e?->id)],
            'name' => 'required|string|max:150', 'name_en' => 'nullable|string|max:150',
            'company_id' => 'required|exists:companies,id', 'job_title_id' => 'nullable|exists:job_titles,id', 'job_level' => 'nullable|integer|between:1,5',
            'religion' => 'nullable|in:muslim,other', 'probation_days' => 'nullable|integer|min:0|max:180',
            // §13: when the employee serves several companies, the allocation of his service totals 100%
            'service_allocation' => ['nullable', 'array', 'max:12', function ($a, $v, $fail) {
                $v = array_filter((array) $v, fn ($x) => (float) $x > 0);
                if ($v && abs(array_sum(array_map('floatval', $v)) - 100) > 0.01) {
                    $fail('مجموع نسب تخصيص الخدمة بين الشركات يجب أن يساوي 100% (§13)');
                }
                foreach (array_keys($v) as $cid) {
                    if (! \App\Models\Company::whereKey($cid)->exists()) {
                        $fail('شركة غير معروفة في نسب التخصيص');
                    }
                }
            }],
            'manager_id' => ['nullable', 'exists:employees,id', Rule::notIn([$e?->id])],
            'dept' => 'nullable|string|max:100', 'hire_date' => 'required|date',
            'contract_type' => ['required', Rule::exists('lookups', 'code')->where('group', 'contract_types')->where('active', true)], 'part_ratio' => 'nullable|numeric|min:0.1|max:1',
            'punch_required' => 'boolean', 'geo_tolerance' => 'nullable|integer|min:0|max:100000', 'punch_anywhere' => 'boolean', 'schedule_id' => 'nullable|exists:schedules,id', 'week_pattern' => 'in:5,6',
            'work_hours' => 'nullable|array|max:7', 'work_hours.*' => 'array|size:2', 'work_hours.*.*' => 'date_format:H:i',
            'country' => 'nullable|string|size:2', 'nationality' => 'nullable|string|max:60', 'id_type' => 'nullable|in:national,iqama,passport',
            'national_id' => ['nullable', 'string', 'max:20', Rule::unique('employees', 'national_id')->ignore($e?->id)->whereNull('deleted_at'), function ($a, $v, $fail) use ($r) {
                $type = $r->input('id_type', 'national');
                if ($type === 'passport' ? ! preg_match('/^[A-Za-z0-9]{5,20}$/', $v) : ! preg_match('/^\d{10}$/', $v)) {
                    $fail($type === 'passport' ? 'رقم الجواز: أحرف وأرقام (5–20)' : 'رقم الهوية أو الإقامة: 10 أرقام');
                }
            }],
            'birth_date' => 'nullable|date', 'gender' => 'nullable|in:m,f', 'phone' => ['nullable', 'regex:/^\+?\d{7,15}$/'],
            'email' => ['nullable', 'email', 'max:150', 'required_if_accepted:email_enabled', function ($a, $v, $fail) use ($e) { if ($v && \App\Models\Employee::where('email', $v)->where('id', '!=', $e?->id)->exists()) { $fail('هذا البريد مسجل لموظف آخر'); } }], 'email_enabled' => 'boolean', 'eosb_saudi' => 'boolean', 'gosi_scheme' => 'nullable|in:old,new,exempt,expat', 'flex_enabled' => 'boolean',
            'daily_note_required' => 'boolean', 'advances_enabled' => 'boolean', 'bank_iban' => ['nullable', 'string', 'max:40', function ($attr, $v, $fail) {
                // ISO 7064 mod 97-10 check; Saudi IBANs are SA + 22 digits
                $v = strtoupper(preg_replace('/\s+/', '', $v));
                if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $v)) {
                    return $fail('صيغة الآيبان غير صحيحة: رمز الدولة ثم رقمان ثم رقم الحساب');
                }
                if (str_starts_with($v, 'SA') && ! preg_match('/^SA\d{22}$/', $v)) {
                    return $fail('الآيبان السعودي يتكون من SA ثم 22 رقمًا');
                }
                $num = preg_replace_callback('/[A-Z]/', fn ($m) => (string) (ord($m[0]) - 55), substr($v, 4).substr($v, 0, 4));
                $r = 0;
                foreach (str_split($num) as $d) {
                    $r = ($r * 10 + (int) $d) % 97;
                }
                if ($r !== 1) {
                    $fail('رقم الآيبان غير صحيح');
                }
            }],
            'location_ids' => 'nullable|array', 'location_ids.*' => 'exists:locations,id',
        ]);
    }

    private function servicePretty(Employee $e): string
    {
        $d = $e->hire_date->diff(now());

        return "{$d->y} سنة و{$d->m} شهر و{$d->d} يوم";
    }
}
