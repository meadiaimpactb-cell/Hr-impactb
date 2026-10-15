<?php

namespace App\Services\Integrations;

use App\Models\Advance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\ExternalRef;
use App\Models\Payslip;
use App\Models\SyncOutbox;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HC ⇄ Odoo synchronisation.
 *  - queue():   record an event in sync_outbox (called by observers / services; no-op when disabled)
 *  - process(): push one outbox row (create or write on the mapped Odoo model, keep external_refs)
 *  - pull():    import master data from Odoo into HC (employees / companies)
 * Field mappings are data: config/hc.php "odooMappings" overridden by setting "odooMappings".
 */
class OdooSync
{
    public const SYSTEM = 'odoo';

    public const ENTITIES = ['company' => 'الشركات', 'employee' => 'الموظفون', 'payslip' => 'قسائم الرواتب', 'advance' => 'السلف', 'expense_claim' => 'تسويات العهد المالية', 'settlement' => 'تصفيات نهاية الخدمة'];

    public static function enabled(): bool
    {
        return (bool) Settings::get('odooEnabled', null, false) && Settings::get('odooUrl');
    }

    public static function client(): OdooClient
    {
        foreach (['odooUrl', 'odooDb', 'odooUser', 'odooApiKey'] as $k) {
            if (! Settings::get($k)) {
                throw new RuntimeException("إعداد أودو ناقص: $k");
            }
        }

        return new OdooClient(Settings::get('odooUrl'), Settings::get('odooDb'), Settings::get('odooUser'), Settings::get('odooApiKey'));
    }

    public static function mappings(): array
    {
        $custom = (array) Settings::get('odooMappings', null, []);

        return array_replace_recursive(config('hc.odooMappings', []), $custom);
    }

    /** Record a sync event; deduplicates queued rows for the same record. */
    public static function queue(string $entity, int $id, string $action = 'upsert', ?array $payload = null): ?SyncOutbox
    {
        if (! self::enabled() || ! in_array($entity, (array) Settings::get('odooSyncEntities', null, array_keys(self::ENTITIES)), true)) {
            return null;
        }
        $existing = SyncOutbox::where(['system' => self::SYSTEM, 'entity' => $entity, 'entity_id' => $id, 'action' => $action, 'status' => 'queued'])->first();
        if ($existing) {
            $existing->update(['payload' => $payload, 'run_after' => now()]);

            return $existing;
        }

        return SyncOutbox::create(['system' => self::SYSTEM, 'entity' => $entity, 'entity_id' => $id, 'action' => $action, 'payload' => $payload,
            'status' => 'queued', 'run_after' => now(), 'created_by' => auth()->id()]);
    }

    /** Push one outbox row to Odoo. Returns the row with its final status. */
    public static function process(SyncOutbox $row, ?OdooClient $client = null): SyncOutbox
    {
        $row->attempts++;
        try {
            $client ??= self::client();
            $result = match ($row->action) {
                'upsert' => self::upsert($client, $row->entity, $row->entity_id),
                'pull' => self::pull($client, $row->entity),
                default => throw new RuntimeException('إجراء غير معروف: '.$row->action),
            };
            $row->fill(['status' => $result['status'] ?? 'sent', 'response' => $result, 'sent_at' => now(), 'last_error' => null])->save();
        } catch (\Throwable $e) {
            $backoff = min(6 * 60, 5 * (2 ** $row->attempts)); // minutes: 10, 20, 40 … max 6h
            $row->fill(['status' => $row->attempts >= 6 ? 'failed' : 'queued', 'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'run_after' => now()->addMinutes($backoff)])->save();
        }

        return $row;
    }

    /** Create or update the Odoo record mapped to an HC entity. */
    public static function upsert(OdooClient $client, string $entity, int $id): array
    {
        $map = self::mappings()[$entity] ?? throw new RuntimeException("لا يوجد تعيين لكيان $entity");
        $record = self::record($entity, $id) ?? throw new RuntimeException("السجل $entity#$id غير موجود");
        $values = self::toOdoo($client, $entity, $record, $map);
        $hash = md5(json_encode($values));
        $ref = ExternalRef::firstOrNew(['system' => self::SYSTEM, 'entity' => $entity, 'entity_id' => $id]);
        if ($ref->exists && $ref->hash === $hash) {
            return ['status' => 'skipped', 'reason' => 'unchanged', 'external_id' => $ref->external_id];
        }
        if (! $ref->exists && ! empty($map['match'])) { // try to find an existing Odoo record before creating (e.g. by employee code)
            $found = $client->search($map['model'], [[$map['match'], '=', $values[$map['match']] ?? null]]);
            if ($found) {
                $ref->external_id = (string) $found[0];
            }
        }
        if ($ref->external_id) {
            $client->write($map['model'], (int) $ref->external_id, $values);
            $action = 'write';
        } else {
            $ref->external_id = (string) $client->create($map['model'], $values);
            $action = 'create';
        }
        $ref->fill(['external_model' => $map['model'], 'hash' => $hash, 'synced_at' => now()])->save();
        Audit::log('odoo', "$entity#$id", $action, null, ['model' => $map['model'], 'external_id' => $ref->external_id]);

        return ['status' => 'sent', 'action' => $action, 'model' => $map['model'], 'external_id' => $ref->external_id];
    }

    /** Import employees / companies from Odoo (creates or updates HC rows, keeps external_refs). */
    public static function pull(OdooClient $client, string $entity): array
    {
        $map = self::mappings()[$entity] ?? throw new RuntimeException("لا يوجد تعيين لكيان $entity");
        $pullFields = $map['pull'] ?? throw new RuntimeException("الكيان $entity لا يدعم الاستيراد");
        $rows = $client->searchRead($map['model'], $map['pull_domain'] ?? [], array_values($pullFields), 500);
        $created = $updated = 0;
        foreach ($rows as $r) {
            $data = [];
            foreach ($pullFields as $hc => $odoo) {
                $v = $r[$odoo] ?? null;
                $data[$hc] = is_array($v) ? ($v[1] ?? null) : ($v === false ? null : $v); // many2one → display name
            }
            $ref = ExternalRef::where(['system' => self::SYSTEM, 'entity' => $entity, 'external_id' => (string) $r['id']])->first();
            $local = $ref ? self::record($entity, $ref->entity_id) : self::matchLocal($entity, $data);
            DB::transaction(function () use ($entity, $local, $data, $r, $map, &$created, &$updated) {
                if ($local) {
                    $local->fill(array_filter(array_diff_key($data, ['company_name' => 1]), fn ($v) => $v !== null))->save();
                    $updated++;
                } else {
                    $local = self::createLocal($entity, $data);
                    $created++;
                }
                ExternalRef::updateOrCreate(['system' => self::SYSTEM, 'entity' => $entity, 'entity_id' => $local->id],
                    ['external_model' => $map['model'], 'external_id' => (string) $r['id'], 'synced_at' => now()]);
            });
        }
        Audit::log('odoo', $entity, 'pull', null, ['created' => $created, 'updated' => $updated]);

        return ['status' => 'sent', 'pulled' => count($rows), 'created' => $created, 'updated' => $updated];
    }

    public static function record(string $entity, int $id): mixed
    {
        return match ($entity) {
            'company' => Company::find($id),
            'employee' => Employee::with('company', 'jobTitle', 'manager')->find($id),
            'payslip' => Payslip::with('employee.company')->find($id),
            'advance' => Advance::with('employee.company')->find($id),
            'expense_claim' => ExpenseClaim::with('employee.company', 'item')->find($id),
            'settlement' => \App\Models\Termination::with('employee.company')->find($id),
            default => null,
        };
    }

    /** Build Odoo values from mapping: 'odoo_field' => 'hc.dotted.path' | ['const' => x] | ['ref' => entity, 'path' => …] */
    private static function toOdoo(OdooClient $client, string $entity, $record, array $map): array
    {
        $out = [];
        foreach ($map['fields'] ?? [] as $odooField => $src) {
            if (is_array($src)) {
                if (array_key_exists('const', $src)) {
                    $out[$odooField] = $src['const'];
                } elseif (isset($src['ref'])) { // link to another synced entity by its external id
                    $localId = data_get($record, $src['path']);
                    $ext = $localId ? ExternalRef::where(['system' => self::SYSTEM, 'entity' => $src['ref'], 'entity_id' => $localId])->value('external_id') : null;
                    if ($ext) {
                        $out[$odooField] = (int) $ext;
                    }
                } elseif (isset($src['lines'])) { // one2many lines, e.g. payslip earnings/deductions
                    $out[$odooField] = self::lines($record, $src);
                }
                continue;
            }
            $v = data_get($record, $src);
            if ($v instanceof \DateTimeInterface) {
                $v = $v->format('Y-m-d');
            }
            if ($v !== null) {
                $out[$odooField] = $v;
            }
        }

        return $out;
    }

    private static function lines($record, array $src): array
    {
        $rows = [];
        foreach ((array) data_get($record, $src['lines']) as $line) {
            $vals = [];
            foreach ($src['fields'] as $odooField => $path) {
                $vals[$odooField] = is_array($path) ? ($path['const'] ?? null) : data_get($line, $path);
            }
            $rows[] = [0, 0, $vals]; // Odoo command: create
        }

        return $rows;
    }

    private static function matchLocal(string $entity, array $data)
    {
        return match ($entity) {
            'company' => ! empty($data['name']) ? Company::where('name', $data['name'])->orWhere('name_en', $data['name'])->first() : null,
            'employee' => (! empty($data['emp_no']) ? Employee::where('emp_no', $data['emp_no'])->first() : null)
                ?? (! empty($data['email']) ? Employee::where('email', $data['email'])->first() : null),
            default => null,
        };
    }

    private static function createLocal(string $entity, array $data)
    {
        return match ($entity) {
            'company' => Company::create(['code' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $data['name_en'] ?? $data['name'] ?? 'CO'), 0, 8)).rand(10, 99), 'name' => $data['name'] ?? 'شركة من أودو', 'name_en' => $data['name_en'] ?? null,
                'tier_id' => \App\Models\Tier::where('is_default', true)->value('id') ?? \App\Models\Tier::min('id')]),
            'employee' => Employee::create(array_filter(array_diff_key($data, ['company_name' => 1]), fn ($v) => $v !== null) + [
                'emp_no' => $data['emp_no'] ?? 'ODOO-'.rand(1000, 9999), 'name' => $data['name'] ?? 'موظف من أودو',
                'company_id' => Company::where('name', $data['company_name'] ?? '')->value('id') ?? Company::where('is_holding', true)->value('id') ?? Company::min('id'),
                'status' => 'active', 'hire_date' => now()->toDateString(), 'contract_type' => 'full',
            ]),
            default => throw new RuntimeException("لا يمكن إنشاء $entity محليًا"),
        };
    }
}
