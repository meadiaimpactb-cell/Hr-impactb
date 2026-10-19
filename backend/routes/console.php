<?php

use App\Jobs\ProcessSyncOutbox;
use App\Services\Settings;
use Illuminate\Support\Facades\Schedule;

// Odoo outbox: pushed every 5 minutes when auto-sync is on (run `php artisan schedule:work` locally, cron in production).
Schedule::command('hc:doc-alerts')->dailyAt('07:00');
// §28: presence checks nobody answered within the deadline are closed as missed every five minutes
Schedule::call(fn () => \App\Http\Controllers\Api\PresenceController::expire())->everyFiveMinutes();
Schedule::job(new ProcessSyncOutbox)->everyFiveMinutes()->when(fn () => (bool) Settings::get('odooAutoSync', null, true));
