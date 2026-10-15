<?php

namespace App\Jobs;

use App\Models\SyncOutbox;
use App\Services\Integrations\OdooSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Drains queued sync events (runs on the queue worker or from the scheduler / artisan). */
class ProcessSyncOutbox implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?int $rowId = null, public int $batch = 50) {}

    public function handle(): void
    {
        if (! OdooSync::enabled()) {
            return;
        }
        $q = SyncOutbox::where('system', OdooSync::SYSTEM)->where('status', 'queued')->where(fn ($w) => $w->whereNull('run_after')->orWhere('run_after', '<=', now()));
        if ($this->rowId) {
            $q->whereKey($this->rowId);
        }
        $rows = $q->orderBy('id')->limit($this->batch)->get();
        try {
            $client = OdooSync::client();
        } catch (\Throwable $e) { // incomplete connection settings: park the rows with the reason instead of crashing the worker
            foreach ($rows as $row) {
                $row->update(['last_error' => $e->getMessage(), 'run_after' => now()->addMinutes(10)]);
            }

            return;
        }
        foreach ($rows as $row) {
            OdooSync::process($row, $client);
        }
    }
}
