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
            'companies' => Company::with('tier:id,code,name,color')->whereIn('id', $ids)->orderBy('id')->get(['id', 'code', 'name', 'name_en', 'tier_id', 'website', 'default_schedule_id', 'logo_path', 'color', 'color2', 'updated_at']),
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
            'policy' => ['grace_minutes' => (int) Settings::get('graceMinutes', null, 0), 'daily_note_min' => (int) Settings::get('dailyNoteMin', null, 20), 'sla_days' => (int) Settings::conf('approvalSlaDays'), 'max_absent_per_team' => (int) Settings::get('maxAbsentPerTeam', null, 0), 'reactions_enabled' => (bool) Settings::get('reactionsEnabled', null, true), 'selfie_enabled' => (bool) Settings::get('selfieEnabled', null, false), 'kudos_enabled' => (bool) Settings::get('peerKudosEnabled', null, true), 'biometric_punch' => (bool) Settings::get('biometricPunch', null, true),
                'presence_check' => (bool) Settings::get('presenceCheckEnabled', null, true), 'location_tracking' => (bool) Settings::get('locationTrackingEnabled', null, false), 'track_interval' => (int) Settings::get('trackIntervalMinutes', null, 10)],
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
        abort_unless(auth()->user()->hasRole('super'), 403, 'حذف الشركات صلاحية الرئيس التنفيذي للمجموعة');
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
        // Policy §115 / owner's note: company data and identity are changed by the group CEO only
        abort_unless(auth()->user()->hasRole('super'), 403, 'تعديل بيانات الشركات صلاحية الرئيس التنفيذي للمجموعة');
        $data = $request->validate([
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'color2' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'code' => ['required', 'string', 'max:20', Rule::unique('companies')->ignore($company?->id)], 'name' => 'required|string|max:150',
            'name_en' => 'nullable|string|max:150', 'legal_no' => 'nullable|string|max:50', 'tax_no' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:200|regex:/^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}(\/.*)?$/i',
            'country' => 'nullable|string|size:2', 'tier_id' => 'nullable|exists:tiers,id', 'default_schedule_id' => 'nullable|exists:schedules,id', 'ceo_employee_id' => 'nullable|exists:employees,id',
            'odoo_id' => 'nullable|string|max:30', 'prorate_rule' => 'in:actual,30', 'active' => 'boolean',
            'currency' => 'nullable|string|size:3', 'timezone' => 'nullable|timezone',
        ]);
        $data['code'] = strtoupper($data['code']);
        if (! $company && empty($data['tier_id'])) {
            // policy §49: a new company starts in the first classification tier (تأسيس)
            $data['tier_id'] = (\App\Models\Tier::where('is_default', true)->first() ?? \App\Models\Tier::orderBy('rank')->first())?->id;
        }
        if (! empty($data['website']) && ! preg_match('#^https?://#i', $data['website'])) {
            $data['website'] = 'https://'.$data['website']; // typed as impactv.sa → stored as a full link
        }
        $before = $company?->toArray();
        $company ? $company->update($data) : $company = Company::create($data);
        Audit::log('companies', $company->id, $before ? 'update' : 'create', $before, $data);

        return $company;
    }

    /** Company logo: PNG/JPG/SVG/WebP up to 2 MB, stored on the public disk and served by /brand/{file}. */
    public function uploadLogo(Request $request, Company $company)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'تعديل هوية الشركة صلاحية الرئيس التنفيذي للمجموعة');
        $request->validate(['logo' => 'required|file|mimes:png,jpg,jpeg,svg,webp|max:2048']);
        $file = $request->file('logo');
        $path = 'brand/c'.$company->id.'-'.time().'.'.strtolower($file->getClientOriginalExtension() ?: 'png');
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
        if ($company->logo_path && $company->logo_path !== $path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
        }
        $company->update(['logo_path' => $path]);
        Audit::log('companies', $company->id, 'logo', null, ['logo_path' => $path]);

        return $company->fresh(['tier:id,code,name,color', 'ceo:id,name']);
    }

    public function deleteLogo(Request $request, Company $company)
    {
        abort_unless($request->user()->hasRole('super'), 403);
        if ($company->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
            Audit::log('companies', $company->id, 'logo_removed');
        }

        return $company->fresh(['tier:id,code,name,color', 'ceo:id,name']);
    }

    public function tiers()
    {
        abort_unless(auth()->user()->isHr() || auth()->user()->hasRole('fin'), 403);
        return Tier::withCount('companies')->orderBy('rank')->get();
    }

    public function updateTier(Request $request, Tier $tier)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'تعديل فئات التصنيف صلاحية الرئيس التنفيذي للمجموعة');
        $data = $request->validate(['benefits' => 'nullable|array', 'reason' => 'required|string|max:300', 'name' => 'nullable|string|max:80',
            'name_en' => 'nullable|string|max:80', 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']]);
        $before = $tier->only('name', 'name_en', 'color', 'benefits');
        $patch = array_filter(['name' => $data['name'] ?? null, 'name_en' => $data['name_en'] ?? null, 'color' => $data['color'] ?? null], fn ($v) => $v !== null);
        if (isset($data['benefits'])) {
            $patch += ['benefits' => $data['benefits'], 'version' => $tier->version + 1, 'effective_from' => now()->toDateString()];
        }
        $tier->update($patch);
        Audit::log('tiers', $tier->id, isset($data['benefits']) ? 'benefits' : 'update', $before, $patch, $data['reason']);

        return $tier->loadCount('companies');
    }

    /** Deleting a tier that still classifies companies requires the tier they move to (asked for in the UI). */
    public function deleteTier(Request $request, Tier $tier)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'حذف فئات التصنيف صلاحية الرئيس التنفيذي للمجموعة');
        abort_if(Tier::count() <= 1, 422, 'لا يمكن حذف آخر فئة');
        $d = $request->validate(['move_to' => ['nullable', 'integer', Rule::exists('tiers', 'id')->whereNot('id', $tier->id)], 'reason' => 'required|string|max:300']);
        $count = $tier->companies()->count();
        if ($count && empty($d['move_to'])) {
            abort(422, "الفئة مرتبطة بـ {$count} شركة — اختر الفئة التي تنتقل إليها هذه الشركات أولًا");
        }
        if ($count) {
            $tier->companies()->update(['tier_id' => $d['move_to']]);
        }
        $snapshot = $tier->toArray();
        $tier->delete();
        if ($tier->is_default && ($first = Tier::orderBy('rank')->first())) {
            $first->update(['is_default' => true]);
        }
        Audit::log('tiers', $snapshot['id'], 'delete', $snapshot, ['moved_to' => $d['move_to'] ?? null, 'companies' => $count], $d['reason']);

        return ['ok' => true, 'moved' => $count];
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
        abort_unless($request->user()->hasRole('super'), 403, 'إنشاء الباقات صلاحية الرئيس التنفيذي للمجموعة');
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
