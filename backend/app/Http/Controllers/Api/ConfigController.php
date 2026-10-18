<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Lookup;
use App\Models\LookupGroup;
use App\Models\RequestType;
use App\Models\SettingDefinition;
use App\Services\Access;
use App\Services\ApprovalService;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Configuration that used to be code: request types, setting definitions, generic lookup lists. */
class ConfigController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->user()->hasRole('super', 'hr_mgr'), 403);
    }

    // ---------- request types ----------
    public function requestTypes()
    {
        $counts = ApprovalRequest::selectRaw('type, count(*) c')->groupBy('type')->pluck('c', 'type');

        return RequestType::orderBy('sort')->orderBy('id')->get()->map(fn ($t) => $t->toArray() + ['requests_count' => $counts[$t->code] ?? 0]);
    }

    public function saveRequestType(Request $request, ?RequestType $type = null)
    {
        $this->admin($request);
        $d = $request->validate([
            'code' => [$type ? 'nullable' : 'required', 'alpha_dash', 'max:30', Rule::unique('request_types')->ignore($type?->id)],
            'name' => 'required|string|max:80', 'name_en' => 'nullable|string|max:80', 'op' => ['required', Rule::in(array_keys(Access::OPS))],
            'flow' => 'required|array|min:1|max:6', 'flow.*.role' => ['required', Rule::in(array_keys(ApprovalService::ROLES))],
            'flow.*.label' => 'nullable|string|max:60', 'flow.*.skip_if' => ['nullable', 'regex:/^\w+\s*(<=|>=|<|>|==)\s*[\d.]+$/'],
            'form' => 'nullable|array|max:20', 'form.*.key' => 'required|alpha_dash|max:30', 'form.*.label' => 'required|string|max:80',
            'form.*.label_en' => 'nullable|string|max:80', 'form.*.type' => ['required', Rule::in(RequestType::FIELD_TYPES)],
            'form.*.required' => 'boolean', 'form.*.options' => 'nullable|array', 'form.*.options.*' => 'string|max:80', 'form.*.group' => 'nullable|string|max:40',
            'self_service' => 'boolean', 'on_behalf' => 'boolean', 'active' => 'boolean', 'sort' => 'nullable|integer', 'icon' => 'nullable|string|max:30',
            'roles' => 'nullable|array', 'roles.*' => Rule::in(array_keys(\App\Models\User::ROLES)),
            'reason' => 'required|string|max:300',
        ]);
        $reason = $d['reason'];
        unset($d['reason']);
        $d['flow'] = array_map(fn ($s) => array_filter($s), $d['flow']);
        if ($type?->builtin) {
            unset($d['code'], $d['form']); // built-in types keep their code and coded form
        } else {
            $d['handler'] = null;
            $d['builtin'] = false;
            $keys = array_column($d['form'] ?? [], 'key');
            if (count($keys) !== count(array_unique($keys))) {
                throw ValidationException::withMessages(['form' => 'مفاتيح الحقول يجب ألا تتكرر']);
            }
        }
        $before = $type?->toArray();
        if ($type) {
            $type->update($d + ['version' => $type->version + 1]);
        } else {
            $type = RequestType::create($d + ['builtin' => false, 'handler' => null]);
        }
        Audit::log('request_types', $type->code, $before ? 'update' : 'create', $before, $type->toArray(), $reason);
        ApprovalService::flushTypes();

        return $type;
    }

    public function deleteRequestType(Request $request, RequestType $type)
    {
        $this->admin($request);
        abort_if($type->builtin, 422, 'الأنواع المدمجة تُعطَّل ولا تُحذف');
        if (ApprovalRequest::where('type', $type->code)->exists()) {
            $type->update(['active' => false]);
            ApprovalService::flushTypes();

            return ['deactivated' => true];
        }
        $type->delete();
        ApprovalService::flushTypes();
        Audit::log('request_types', $type->code, 'delete', $type->toArray());

        return ['deleted' => true];
    }

    // ---------- setting definitions ----------
    public function saveSettingDefinition(Request $request, ?SettingDefinition $def = null)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'تعريف القواعد صلاحية الرئيس التنفيذي للمجموعة');
        $d = $request->validate([
            'key' => [$def ? 'nullable' : 'required', 'regex:/^[a-zA-Z][a-zA-Z0-9_.]{1,59}$/', Rule::unique('setting_definitions')->ignore($def?->id)],
            'label' => 'required|string|max:120', 'label_en' => 'nullable|string|max:120', 'group' => 'required|string|max:60',
            'type' => ['required', Rule::in(SettingDefinition::TYPES)], 'description' => 'nullable|string|max:300', 'sort' => 'nullable|integer',
        ]);
        if ($def?->builtin) {
            unset($d['key'], $d['type']); // built-in keys are read by code: name & type are fixed
        }
        $before = $def?->toArray();
        $def ? $def->update($d) : $def = SettingDefinition::create($d + ['builtin' => false]);
        Audit::log('setting_definitions', $def->key, $before ? 'update' : 'create', $before, $def->toArray());

        return $def;
    }

    public function deleteSettingDefinition(Request $request, SettingDefinition $def)
    {
        abort_unless($request->user()->hasRole('super'), 403);
        abort_if($def->builtin, 422, 'القواعد المدمجة لا تُحذف');
        \App\Models\Setting::where('key', $def->key)->delete();
        $def->delete();
        \App\Services\Settings::flush();

        return ['deleted' => true];
    }

    // ---------- lookup lists ----------
    public function lookupGroups()
    {
        $counts = Lookup::selectRaw('`group`, count(*) c')->groupBy('group')->pluck('c', 'group');

        return LookupGroup::orderBy('id')->get()->map(fn ($g) => $g->toArray() + ['items_count' => $counts[$g->code] ?? 0]);
    }

    public function saveLookupGroup(Request $request, ?LookupGroup $group = null)
    {
        $this->admin($request);
        $d = $request->validate([
            'code' => [$group ? 'nullable' : 'required', 'regex:/^[a-z][a-z0-9_]{1,39}$/', Rule::unique('lookup_groups')->ignore($group?->id)],
            'name' => 'required|string|max:120', 'name_en' => 'nullable|string|max:120',
            'meta_fields' => 'nullable|array|max:8', 'meta_fields.*.key' => 'required|alpha_dash|max:30', 'meta_fields.*.label' => 'required|string|max:60',
            'meta_fields.*.type' => ['required', Rule::in(['text', 'number', 'bool'])],
        ]);
        if ($group?->builtin) {
            unset($d['code']);
        }
        $group ? $group->update($d) : $group = LookupGroup::create($d);
        Audit::log('lookup_groups', $group->code, 'save', null, $group->toArray());

        return $group;
    }

    public function lookupItems(string $group)
    {
        return Lookup::where('group', $group)->orderBy('sort')->orderBy('id')->get();
    }

    public function saveLookupItem(Request $request, string $group, ?Lookup $item = null)
    {
        $this->admin($request);
        abort_unless(LookupGroup::where('code', $group)->exists(), 404);
        $d = $request->validate([
            'code' => ['nullable', 'alpha_dash', 'max:40', Rule::unique('lookups')->where('group', $group)->ignore($item?->id)],
            'name' => 'required|string|max:120', 'name_en' => 'nullable|string|max:120', 'meta' => 'nullable|array', 'sort' => 'nullable|integer', 'active' => 'boolean',
        ]);
        $d['code'] = ($d['code'] ?? null) ?: ($item?->code ?? 'k'.((int) Lookup::where('group', $group)->count() + 1).'_'.substr(md5($d['name']), 0, 4));
        $item ? $item->update($d) : $item = Lookup::create($d + ['group' => $group]);
        Audit::log('lookups', "$group/{$item->code}", 'save', null, $item->toArray());

        return $item;
    }

    public function deleteLookupItem(Request $request, string $group, Lookup $item)
    {
        $this->admin($request);
        abort_unless($item->group === $group, 404);
        // Never lose history: names are stored on requests, so deactivating is always safe; delete only if never referenced.
        $used = ApprovalRequest::where('data->kind', $item->name)->exists();
        if ($used) {
            $item->update(['active' => false]);

            return ['deactivated' => true];
        }
        $item->delete();
        Cache::forget('hc.lookups');

        return ['deleted' => true];
    }
}
