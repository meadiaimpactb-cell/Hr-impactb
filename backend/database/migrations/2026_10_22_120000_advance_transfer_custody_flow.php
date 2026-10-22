<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's notes (22/10):
 *  · after an advance's final approval finance attaches the transfer document; the employee sees how it is repaid,
 *    the instalments paid and what remains
 *  · a custody request chooses its type from a list (asset / cash custody) and goes through the same approvals as an
 *    advance: direct manager → company CEO → HR → finance → group CEO (final)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('advances', 'transfer_path')) {
            Schema::table('advances', function (Blueprint $t) {
                $t->string('repay_method', 20)->default('salary')->after('schedule'); // salary = monthly deduction from the payslip
                $t->string('transfer_path')->nullable()->after('repay_method');
                $t->string('transfer_ref', 100)->nullable()->after('transfer_path');
                $t->date('transferred_on')->nullable()->after('transfer_ref');
                $t->unsignedBigInteger('transferred_by')->nullable()->after('transferred_on');
            });
        }

        $advance = DB::table('request_types')->where('code', 'advance')->value('flow');
        $custody = DB::table('request_types')->where('code', 'custody')->first();
        if ($custody && $advance) {
            $form = json_decode((string) $custody->form, true) ?: [];
            foreach ($form as &$f) {
                if (($f['key'] ?? '') === 'kind') {
                    $f = ['key' => 'kind', 'type' => 'select', 'label' => 'نوع العهدة', 'label_en' => 'Custody type', 'required' => true, 'options' => ['عهد أصول', 'عهد نقدية']];
                }
                if (($f['key'] ?? '') === 'amount') {
                    $f['label'] = 'المبلغ (ريال) — إلزامي للعهدة النقدية';
                }
            }
            unset($f);
            DB::table('request_types')->where('code', 'custody')->update(['flow' => $advance, 'form' => json_encode($form, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
        Cache::forget('hc.request_types');
    }

    public function down(): void
    {
        if (Schema::hasColumn('advances', 'transfer_path')) {
            Schema::table('advances', fn (Blueprint $t) => $t->dropColumn(['repay_method', 'transfer_path', 'transfer_ref', 'transferred_on', 'transferred_by']));
        }
    }
};
