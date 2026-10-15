<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Lookup;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\SettingDefinition;
use App\Models\Tier;
use App\Models\User;
use App\Services\Access;
use App\Services\ApprovalService;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lookups, companies & tiers, settings (policy engine), notifications, audit. */
class AdminController extends Controller
{
    public function meta()
    {
        $ids = Access::for()->companyIds();

        return [
            'companies' => Company::whereIn('id', $ids)->orderBy('id')->get(['id', 'code', 'name', 'name_en', 'tier_id', 'website', 'default_schedule_id']),
            'job_titles' => JobTitle::orderBy('name')->get(['id', 'code', 'name', 'name_en']),
            'schedules' => Schedule::all(['id', 'name']),
            'locations' => Location::all(['id', 'name', 'scope', 'company_id', 'is_main', 'radius']),
            'leave_types' => LeaveType::where('active', true)->orderBy('sort')->get(['code', 'name', 'name_en', 'attachment_required', 'accrual']),
            'roles' => User::ROLES,
            'request_types' => ApprovalService::labels(),
            'request_type_defs' => collect(ApprovalService::types(true))->map(fn ($t) => $t->only('code', 'name', 'name_en', 'handler', 'form', 'self_service', 'on_behalf', 'roles', 'icon'))->values(),
            'ops' => Access::OPS,
            'lookups' => Lookup::grouped(),
            'departments' => array_values(array_unique(array_merge(Lookup::names('departments'), Employee::whereNotNull('dept')->where('dept', '!=', '')->distinct()->orderBy('dept')->pluck('dept')->all()))),
            'letter_kinds' => Lookup::names('letter_kinds'),
            'correction_kinds' => Lookup::names('correction_kinds'),
            'benefit_labels' => array_merge(config('hc.benefitLabels'), (array) Settings::get('benefitLabels')),
            'policy' => ['grace_minutes' => (int) Settings::get('graceMinutes', null, 0), 'daily_note_min' => (int) Settings::get('dailyNoteMin', null, 20), 'sla_days' => (int) Settings::conf('approvalSlaDays'), 'max_absent_per_team' => (int) Settings::get('maxAbsentPerTeam', null, 0), 'reactions_enabled' => (bool) Settings::get('reactionsEnabled', null, true), 'selfie_enabled' => (bool) Settings::get('selfieEnabled', null, false), 'kudos_enabled' => (bool) Settings::get('peerKudosEnabled', null, true), 'biometric_punch' => (bool) Settings::get('biometricPunch', null, true)],
        ];
    }

    // ---------- companies & tiers ----------
    public function companies()
    {
        abort_unless(auth()->user()->isHr() || auth()->user()->hasRole('fin'), 403);
        return Company::with('tier:id,name,color', 'ceo:id,name')->withCount(['employees' => fn ($q) => $q->where('status', '!=', 'terminated')])->orderBy('id')->get();
    }

    /** Deletes a company that has no employees, records or settings; otherwise asks to suspend it instead. */
    public function deleteCompany(Company $company)
    {
        abort_unless(auth()->user()->hasRole('super'), 403, 'حذف الشركات صلاحية المدير العام');
        abort_if($company->is_holding, 422, 'لا يمكن حذف الشركة القابضة');
        if (\App\Models\Employee::withTrashed()->where('company_id', $company->id)->exists()) {
            abort(422, 'لا يمكن حذف شركة لها موظفون — انقلهم أولًا أو أوقف الشركة بدل حذفها');
        }
        try {
            $company->delete();
        } catch (\Illuminate\Database\QueryException) {
            abort(422, 'للشركة سجلات مرتبطة — أوقفها بدل حذفها');
        }
        Audit::log('companies', $company->id, 'delete', ['code' => $company->code, 'name' => $company->name]);

        return ['ok' => true];
    }

    public function saveCompany(Request $request, ?Company $company = null)
    {
        abort_unless(Access::for()->can('CMP', $company ? 'edit' : 'add'), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('companies')->ignore($company?->id)], 'name' => 'required|string|max:150',
            'name_en' => 'nullable|string|max:150', 'legal_no' => 'nullable|string|max:50', 'tax_no' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:200|regex:/^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}(\/.*)?$/i',
            'country' => 'nullable|string|size:2', 'tier_id' => 'nullable|exists:tiers,id', 'default_schedule_id' => 'nullable|exists:schedules,id', 'ceo_employee_id' => 'nullable|exists:employees,id',
            'odoo_id' => 'nullable|string|max:30', 'prorate_rule' => 'in:actual,30', 'active' => 'boolean',
            'currency' => 'nullable|string|size:3', 'timezone' => 'nullable|timezone',
        ]);
        $data['code'] = strtoupper($data['code']);
        if (! $company && empty($data['tier_id'])) {
            // new company → its own package, named after it, starting from the default package's benefits
            $base = \App\Models\Tier::where('is_default', true)->first() ?? \App\Models\Tier::orderBy('rank')->first();
            $tier = \App\Models\Tier::create(['name' => 'باقة '.$data['name'], 'name_en' => ($data['name_en'] ?? $data['code']).' package', 'color' => $base?->color ?? '#1B82C3',
                'rank' => (int) \App\Models\Tier::max('rank') + 1, 'effective_from' => now()->toDateString(), 'benefits' => $base?->benefits ?? []]);
            $data['tier_id'] = $tier->id;
        }
        if (! empty($data['website']) && ! preg_match('#^https?://#i', $data['website'])) {
            $data['website'] = 'https://'.$data['website']; // typed as impactv.sa → stored as a full link
        }
        $before = $company?->toArray();
        $company ? $company->update($data) : $company = Company::create($data);
        Audit::log('companies', $company->id, $before ? 'update' : 'create', $before, $data);

        return $company;
    }

    public function tiers()
    {
        abort_unless(auth()->user()->isHr(), 403);
        return Tier::withCount('companies')->orderBy('rank')->get();
    }

    public function updateTier(Request $request, Tier $tier)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'تعديل الباقات صلاحية المدير العام');
        $data = $request->validate(['benefits' => 'required|array', 'reason' => 'required|string|max:300', 'name' => 'nullable|string|max:80']);
        $before = $tier->benefits;
        $tier->update(['benefits' => $data['benefits'], 'name' => $data['name'] ?? $tier->name, 'version' => $tier->version + 1, 'effective_from' => now()->toDateString()]);
        Audit::log('tiers', $tier->id, 'benefits', $before, $data['benefits'], $data['reason']);

        return $tier;
    }

    // ---------- settings ----------
    public function settings(Request $request)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);

        $rows = Setting::all()->groupBy('key');
        $out = [];
        foreach (SettingDefinition::orderBy('sort')->orderBy('id')->get() as $def) {
            [$key, $label, $group, $type] = [$def->key, $def->label, $def->group, $def->type];
            $all = $rows[$key] ?? collect();
            $global = $all->firstWhere('company_id', null);
            $mask = fn ($v) => $type === 'secret' && $v ? '••••••••' : $v;
            $out[] = [
                'key' => $key, 'label' => $label, 'label_en' => $def->label_en, 'group' => $group, 'type' => $type, 'description' => $def->description, 'builtin' => $def->builtin,
                'value' => $mask($global ? $global->value : config("hc.$key")),
                'version' => $global?->version, 'effective_from' => $global?->effective_from?->toDateString(), 'reason' => $global?->reason,
                'overrides' => $all->whereNotNull('company_id')->map(fn ($s) => ['company_id' => $s->company_id, 'value' => $mask($s->value)])->values(),
            ];
        }

        return $out;
    }

    public function updateSetting(Request $request)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);
        $d = $request->validate(['key' => 'required|string|max:60|exists:setting_definitions,key', 'value' => 'present', 'company_id' => 'nullable|exists:companies,id', 'reason' => 'required|string|max:300']);
        if ($d['value'] === '••••••••') {
            return ['unchanged' => true]; // masked secret sent back untouched
        }

        return Settings::set($d['key'], $d['value'], $d['company_id'] ?? null, $d['reason']);
    }

    // ---------- notifications ----------
    public function notifications(Request $request)
    {
        return AppNotification::where('user_id', $request->user()->id)->latest()->limit(100)->get();
    }

    public function unread(Request $request)
    {
        return ['count' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()];
    }

    public function markRead(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')
            ->when($request->input('id'), fn ($q, $id) => $q->whereKey($id))->update(['read_at' => now()]);

        return ['ok' => true];
    }

    public function storeTier(Request $request)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'إنشاء الباقات صلاحية المدير العام');
        $d = $request->validate(['name' => 'required|string|max:80', 'name_en' => 'nullable|string|max:80', 'color' => 'nullable|string|max:20',
            'copy_from' => 'nullable|exists:tiers,id', 'reason' => 'required|string|max:300']);
        $src = Tier::find($d['copy_from'] ?? null) ?? Tier::orderBy('rank')->first();
        $tier = Tier::create(['rank' => (int) Tier::max('rank') + 1, 'name' => $d['name'], 'name_en' => $d['name_en'] ?? null,
            'color' => $d['color'] ?? '#1B82C3', 'benefits' => $src?->benefits ?? [], 'effective_from' => now()->toDateString()]);
        Audit::log('tiers', $tier->id, 'create', null, $tier->toArray(), $d['reason']);

        return $tier->loadCount('companies');
    }

    public function auditEntities(Request $request)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);

        return AuditLog::query()->distinct()->orderBy('entity')->pluck('entity');
    }

    // ---------- audit ----------
    public function audit(Request $request)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);

        return AuditLog::with('user:id,name')
            ->when($request->input('entity'), fn ($q, $e) => $q->where('entity', $e))
            ->when($request->input('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('entity_id', $s)->orWhere('action', 'like', "%$s%")->orWhere('reason', 'like', "%$s%")))
            ->orderByDesc('id')->paginate(50);
    }
}
