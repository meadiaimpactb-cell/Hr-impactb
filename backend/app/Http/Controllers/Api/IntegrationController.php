<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSyncOutbox;
use App\Models\ExternalRef;
use App\Models\SyncOutbox;
use App\Services\Integrations\OdooSync;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Odoo (and future systems) integration: status, connection test, mappings, outbox, manual push / pull, inbound webhook. */
class IntegrationController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->user()->hasRole('super', 'hr_mgr'), 403, 'التكامل صلاحية المدير العام ومدير الموارد البشرية');
    }

    public function status(Request $request)
    {
        $this->admin($request);
        $counts = SyncOutbox::where('system', 'odoo')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return [
            'enabled' => OdooSync::enabled(),
            'settings' => ['url' => Settings::get('odooUrl'), 'db' => Settings::get('odooDb'), 'user' => Settings::get('odooUser'), 'has_key' => (bool) Settings::get('odooApiKey'),
                'entities' => Settings::get('odooSyncEntities', null, array_keys(OdooSync::ENTITIES)), 'auto' => (bool) Settings::get('odooAutoSync', null, true)],
            'entities' => OdooSync::ENTITIES,
            'counts' => ['queued' => $counts['queued'] ?? 0, 'sent' => $counts['sent'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'skipped' => $counts['skipped'] ?? 0],
            'linked' => ExternalRef::where('system', 'odoo')->selectRaw('entity, count(*) c')->groupBy('entity')->pluck('c', 'entity'),
            'last_sent' => SyncOutbox::where('system', 'odoo')->where('status', 'sent')->max('sent_at'),
        ];
    }

    /** Save connection settings (each through the policy engine → versioned, audited, per-company overridable later). */
    public function saveSettings(Request $request)
    {
        $this->admin($request);
        $d = $request->validate(['enabled' => 'boolean', 'url' => 'nullable|url|max:200', 'db' => 'nullable|string|max:80', 'user' => 'nullable|string|max:120',
            'api_key' => 'nullable|string|max:200', 'entities' => 'array', 'entities.*' => Rule::in(array_keys(OdooSync::ENTITIES)), 'auto' => 'boolean']);
        $reason = 'إعدادات التكامل مع أودو';
        Settings::set('odooEnabled', (bool) ($d['enabled'] ?? false), null, $reason);
        Settings::set('odooUrl', $d['url'] ?? null, null, $reason);
        Settings::set('odooDb', $d['db'] ?? null, null, $reason);
        Settings::set('odooUser', $d['user'] ?? null, null, $reason);
        if (! empty($d['api_key'])) {
            Settings::set('odooApiKey', $d['api_key'], null, $reason);
        }
        Settings::set('odooSyncEntities', $d['entities'] ?? [], null, $reason);
        Settings::set('odooAutoSync', (bool) ($d['auto'] ?? true), null, $reason);

        return $this->status($request);
    }

    public function test(Request $request)
    {
        $this->admin($request);
        try {
            $c = OdooSync::client();
            $v = $c->version();
            $uid = $c->login();

            return ['ok' => true, 'version' => $v['server_version'] ?? null, 'uid' => $uid];
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function mappings(Request $request)
    {
        $this->admin($request);

        return ['defaults' => config('hc.odooMappings'), 'custom' => Settings::get('odooMappings', null, []), 'effective' => OdooSync::mappings()];
    }

    public function saveMappings(Request $request)
    {
        $this->admin($request);
        $d = $request->validate(['mappings' => 'present|array', 'reason' => 'required|string|max:300']);
        Settings::set('odooMappings', $d['mappings'], null, $d['reason']);

        return ['ok' => true, 'effective' => OdooSync::mappings()];
    }

    /** Live Odoo field list for a model — helps the admin build a mapping without guessing. */
    public function fields(Request $request)
    {
        $this->admin($request);
        $model = $request->validate(['model' => 'required|string|max:60'])['model'];
        try {
            $f = OdooSync::client()->fieldsGet($model);

            return collect($f)->map(fn ($x, $k) => ['name' => $k, 'label' => $x['string'] ?? $k, 'type' => $x['type'] ?? '', 'required' => (bool) ($x['required'] ?? false), 'relation' => $x['relation'] ?? null])->values();
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function outbox(Request $request)
    {
        $this->admin($request);

        return SyncOutbox::with('creator:id,name')->where('system', 'odoo')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('entity'), fn ($q, $e) => $q->where('entity', $e))
            ->orderByDesc('id')->paginate(40)->through(fn ($r) => $r->toArray() + ['status_label' => SyncOutbox::STATUSES[$r->status] ?? $r->status]);
    }

    public function retry(Request $request, SyncOutbox $row)
    {
        $this->admin($request);
        $row->update(['status' => 'queued', 'run_after' => now(), 'attempts' => 0, 'last_error' => null]);
        ProcessSyncOutbox::dispatchSync($row->id);

        return $row->fresh();
    }

    /** Queue (and immediately push) one HC record. */
    public function push(Request $request)
    {
        $this->admin($request);
        $d = $request->validate(['entity' => ['required', Rule::in(array_keys(OdooSync::ENTITIES))], 'id' => 'required|integer']);
        abort_unless(OdooSync::enabled(), 422, 'التكامل غير مفعّل');
        $row = SyncOutbox::create(['system' => 'odoo', 'entity' => $d['entity'], 'entity_id' => $d['id'], 'action' => 'upsert', 'status' => 'queued', 'run_after' => now(), 'created_by' => $request->user()->id]);
        ProcessSyncOutbox::dispatchSync($row->id);

        return $row->fresh();
    }

    /** Queue every record of an entity (initial load). */
    public function pushAll(Request $request)
    {
        $this->admin($request);
        $entity = $request->validate(['entity' => ['required', Rule::in(array_keys(OdooSync::ENTITIES))]])['entity'];
        abort_unless(OdooSync::enabled(), 422, 'التكامل غير مفعّل');
        $ids = match ($entity) {
            'company' => \App\Models\Company::where('active', true)->pluck('id'),
            'employee' => \App\Models\Employee::active()->pluck('id'),
            'payslip' => \App\Models\Payslip::where('published', true)->pluck('id'),
            'advance' => \App\Models\Advance::pluck('id'),
            'expense_claim' => \App\Models\ExpenseClaim::whereIn('status', ['settled', 'to_salary'])->pluck('id'),
            'settlement' => \App\Models\Termination::whereIn('status', ['settled', 'paid'])->pluck('id'),
        };
        $n = 0;
        foreach ($ids as $id) {
            SyncOutbox::firstOrCreate(['system' => 'odoo', 'entity' => $entity, 'entity_id' => $id, 'action' => 'upsert', 'status' => 'queued'], ['run_after' => now(), 'created_by' => $request->user()->id]);
            $n++;
        }
        ProcessSyncOutbox::dispatch();

        return ['queued' => $n];
    }

    public function pull(Request $request)
    {
        $this->admin($request);
        $entity = $request->validate(['entity' => ['required', Rule::in(['company', 'employee'])]])['entity'];
        abort_unless(OdooSync::enabled(), 422, 'التكامل غير مفعّل');
        $row = SyncOutbox::create(['system' => 'odoo', 'entity' => $entity, 'entity_id' => 0, 'action' => 'pull', 'status' => 'queued', 'run_after' => now(), 'created_by' => $request->user()->id]);
        ProcessSyncOutbox::dispatchSync($row->id);

        return $row->fresh();
    }

    /** Run the queue now (for installations without a worker). */
    public function runNow(Request $request)
    {
        $this->admin($request);
        ProcessSyncOutbox::dispatchSync();

        return $this->status($request);
    }

    /** Inbound webhook from Odoo automated actions: {token, model, id, values}. */
    public function webhook(Request $request)
    {
        $token = Settings::get('odooWebhookToken');
        abort_unless($token && hash_equals((string) $token, (string) $request->input('token', $request->header('X-HC-Token'))), 401);
        $d = $request->validate(['model' => 'required|string', 'id' => 'required|integer', 'values' => 'array']);
        $entity = collect(OdooSync::mappings())->search(fn ($m) => ($m['model'] ?? null) === $d['model']);
        abort_unless($entity, 422, 'نموذج غير معيّن');
        $ref = ExternalRef::where(['system' => 'odoo', 'entity' => $entity, 'external_id' => (string) $d['id']])->first();
        $rec = $ref ? OdooSync::record($entity, $ref->entity_id) : null;
        if (! $rec) {
            return ['ok' => false, 'reason' => 'no local record'];
        }
        $pull = OdooSync::mappings()[$entity]['pull'] ?? [];
        $data = [];
        foreach ($pull as $hc => $odoo) {
            if (array_key_exists($odoo, $d['values'] ?? [])) {
                $v = $d['values'][$odoo];
                $data[$hc] = is_array($v) ? ($v[1] ?? null) : $v;
            }
        }
        unset($data['company_name']);
        if ($data) {
            $before = $rec->only(array_keys($data));
            $rec->fill($data)->save();
            \App\Services\Audit::log('odoo', "$entity#{$rec->id}", 'webhook', $before, $data);
        }

        return ['ok' => true, 'updated' => array_keys($data)];
    }
}
