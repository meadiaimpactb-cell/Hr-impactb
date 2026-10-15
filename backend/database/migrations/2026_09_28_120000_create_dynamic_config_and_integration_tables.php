<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything that used to be a PHP constant becomes data:
 *  - request_types        (labels, operation, approval flow, form schema for custom types)
 *  - setting_definitions  (label / group / type of every policy key)
 *  - lookups              (generic named lists: letter kinds, correction kinds, violation types…)
 * Plus the integration layer for Odoo (and any future system):
 *  - external_refs        (HC record ⇄ external id)
 *  - sync_outbox          (pending / sent / failed sync events)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 80);
            $t->string('name_en', 80)->nullable();
            $t->string('op', 10)->default('EMP');         // Access operation used for delegation limits
            $t->string('handler', 30)->nullable();         // built-in side-effect handler; null = custom (no effect)
            $t->json('flow');                              // [{role,label?,skip_if?}]
            $t->json('form')->nullable();                  // custom types: [{key,label,label_en,type,required,options}]
            $t->boolean('self_service')->default(true);    // employee can raise it for themselves
            $t->boolean('on_behalf')->default(false);      // HR / manager raises it for an employee
            $t->boolean('active')->default(true);
            $t->boolean('builtin')->default(false);
            $t->unsignedSmallInteger('sort')->default(100);
            $t->string('icon', 30)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('setting_definitions', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60)->unique();
            $t->string('label', 120);
            $t->string('label_en', 120)->nullable();
            $t->string('group', 60);
            $t->string('type', 10)->default('text');        // number|bool|list|json|text|secret
            $t->string('description', 300)->nullable();
            $t->boolean('builtin')->default(false);
            $t->unsignedSmallInteger('sort')->default(100);
            $t->timestamps();
        });

        Schema::create('lookups', function (Blueprint $t) {
            $t->id();
            $t->string('group', 40);
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('name_en', 120)->nullable();
            $t->json('meta')->nullable();
            $t->unsignedSmallInteger('sort')->default(100);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['group', 'code']);
        });

        Schema::create('lookup_groups', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 120);
            $t->string('name_en', 120)->nullable();
            $t->json('meta_fields')->nullable();          // extra per-item fields, e.g. [{key:'points',label:'النقاط',type:'number'}]
            $t->boolean('builtin')->default(false);
            $t->timestamps();
        });

        Schema::create('external_refs', function (Blueprint $t) {
            $t->id();
            $t->string('system', 20);                     // odoo
            $t->string('entity', 40);                     // employee | company | payslip | advance | expense_claim …
            $t->unsignedBigInteger('entity_id');
            $t->string('external_model', 60)->nullable(); // hr.employee, res.company, account.move …
            $t->string('external_id', 60);
            $t->string('hash', 40)->nullable();           // last pushed payload hash → skip unchanged
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['system', 'entity', 'entity_id']);
            $t->index(['system', 'external_model', 'external_id']);
        });

        Schema::create('sync_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('system', 20);
            $t->string('entity', 40);
            $t->unsignedBigInteger('entity_id');
            $t->string('action', 20);                     // upsert | delete | pull
            $t->json('payload')->nullable();
            $t->string('status', 12)->default('queued');  // queued | sent | failed | skipped
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->json('response')->nullable();
            $t->timestamp('run_after')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['system', 'status', 'run_after']);
            $t->index(['entity', 'entity_id']);
        });

        $this->seedFromConfig();
    }

    /** Move existing config/settings constants into the new tables (idempotent). */
    private function seedFromConfig(): void
    {
        $now = now();
        $settingsRows = DB::table('settings')->whereNull('company_id')->get()->keyBy('key');
        $val = fn ($k) => isset($settingsRows[$k]) ? json_decode($settingsRows[$k]->value, true) : null;

        // request types
        $labels = array_merge(config('hc.requestTypeLabels', []), (array) $val('requestTypeLabels'));
        $customFlows = (array) $val('approvalFlows');
        $off = (array) $val('reqTypesOff');
        $ops = ['leave' => 'LVE', 'advance' => 'ADV', 'correction' => 'ATT', 'allowance' => 'ALW', 'benefit' => 'ALW', 'trip' => 'ADV',
            'letter' => 'EMP', 'balance_adj' => 'LVE', 'resignation' => 'TRM', 'salary_change' => 'PAY'];
        $defaults = [
            'leave' => [['role' => 'mgr']], 'advance' => [['role' => 'mgr'], ['role' => 'fin']], 'correction' => [['role' => 'mgr']],
            'allowance' => [['role' => 'super']], 'benefit' => [['role' => 'mgr'], ['role' => 'hr_staff'], ['role' => 'hr_mgr'], ['role' => 'super']],
            'trip' => [['role' => 'mgr'], ['role' => 'ceo']], 'letter' => [['role' => 'hr']], 'balance_adj' => [['role' => 'super']],
            'resignation' => [['role' => 'mgr'], ['role' => 'hr']], 'salary_change' => [['role' => 'mgr'], ['role' => 'super']],
        ];
        $en = ['leave' => 'Leave', 'advance' => 'Advance', 'correction' => 'Attendance correction', 'allowance' => 'Allowance change',
            'benefit' => 'Benefit exception', 'trip' => 'Business trip', 'letter' => 'Official letter', 'balance_adj' => 'Leave balance adjustment',
            'resignation' => 'Resignation', 'salary_change' => 'Salary change'];
        $onBehalf = ['allowance', 'benefit', 'balance_adj', 'salary_change'];
        $i = 0;
        foreach ($labels as $code => $name) {
            DB::table('request_types')->updateOrInsert(['code' => $code], [
                'name' => $name, 'name_en' => $en[$code] ?? null, 'op' => $ops[$code] ?? 'EMP', 'handler' => $code,
                'flow' => json_encode($customFlows[$code] ?? $defaults[$code] ?? [['role' => 'mgr']]),
                'self_service' => ! in_array($code, $onBehalf, true), 'on_behalf' => in_array($code, $onBehalf, true),
                'active' => ! in_array($code, $off, true), 'builtin' => true, 'sort' => ++$i * 10, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // setting definitions
        $i = 0;
        foreach (config('hc.settingMeta', []) as $key => [$label, $group, $type]) {
            if (in_array($key, ['requestTypeLabels', 'reqTypesOff', 'letterKinds', 'correctionKinds'], true)) {
                continue; // now managed as request types / lookups
            }
            DB::table('setting_definitions')->updateOrInsert(['key' => $key], ['label' => $label, 'group' => $group, 'type' => $type,
                'builtin' => true, 'sort' => ++$i * 10, 'created_at' => $now, 'updated_at' => $now]);
        }

        // lookup groups + items migrated from the old flat lists
        $groups = [
            'letter_kinds' => ['أنواع الخطابات', 'Letter kinds', $val('letterKinds') ?? config('hc.letterKinds', [])],
            'correction_kinds' => ['أنواع تصحيح الحضور', 'Attendance correction kinds', $val('correctionKinds') ?? config('hc.correctionKinds', [])],
        ];
        foreach ($groups as $g => [$name, $nameEn, $items]) {
            DB::table('lookup_groups')->updateOrInsert(['code' => $g], ['name' => $name, 'name_en' => $nameEn, 'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach (array_values((array) $items) as $n => $item) {
                DB::table('lookups')->updateOrInsert(['group' => $g, 'code' => 'k'.($n + 1)], ['name' => $item, 'sort' => ($n + 1) * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        foreach (['sync_outbox', 'external_refs', 'lookup_groups', 'lookups', 'setting_definitions', 'request_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
