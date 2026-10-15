<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\DocumentController;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Console\Command;

/** Daily: notify follow-up user / HR / employee when a document crosses one of the docAlertDays thresholds (each threshold once). */
class DocumentAlerts extends Command
{
    protected $signature = 'hc:doc-alerts';

    protected $description = 'Send document expiry notifications';

    public function handle(): int
    {
        $sent = 0;
        foreach (Document::whereNotNull('expiry_date')->where('renewed', false)->get() as $d) {
            $cid = $d->scope === 'employee' ? Employee::whereKey($d->owner_id)->value('company_id') : $d->owner_id;
            $days = (int) now()->startOfDay()->diffInDays($d->expiry_date, false);
            $thresholds = collect((array) Settings::get('docAlertDays', $cid, [90, 60, 30, 7]))->map(fn ($x) => (int) $x)->push(0)->sortDesc();
            $hit = $thresholds->first(fn ($t) => $days <= $t && ! in_array($t, $d->alerted ?? [], true));
            if ($hit === null) {
                continue;
            }
            $state = DocumentController::state($d, $cid);
            $ownerName = $d->scope === 'employee' ? Employee::whereKey($d->owner_id)->value('name') : \App\Models\Company::whereKey($d->owner_id)->value('name');
            $title = $days < 0 ? 'وثيقة منتهية' : "وثيقة تنتهي خلال $days يوم";
            $ids = array_filter([$d->follow_user_id, $d->scope === 'employee' ? User::where('employee_id', $d->owner_id)->value('id') : null, ...Access::hrUsersFor($cid, 'DOC')]);
            Audit::notify(array_unique($ids), $title, Lookup::name('document_types', $d->type).' — '.$ownerName.' · '.$d->expiry_date->toDateString().' ('.$state['label'].')', $days <= 7 ? 'bad' : 'warn', ['screen' => 'documents']);
            $d->update(['alerted' => [...($d->alerted ?? []), $hit]]);
            $sent++;
        }
        $this->info("sent $sent");

        return self::SUCCESS;
    }
}
