<?php

use App\Jobs\ProcessSyncOutbox;
use App\Services\Settings;
use Illuminate\Support\Facades\Schedule;

// Odoo outbox: pushed every 5 minutes when auto-sync is on (run `php artisan schedule:work` locally, cron in production).
Schedule::command('hc:doc-alerts')->dailyAt('07:00');
// §28: presence checks nobody answered within the deadline are closed as missed every five minutes
Schedule::call(fn () => \App\Http\Controllers\Api\PresenceController::expire())->everyFiveMinutes();
Schedule::job(new ProcessSyncOutbox)->everyFiveMinutes()->when(fn () => (bool) Settings::get('odooAutoSync', null, true));
// Policy audit (chapters 18–20): §100–101 absence watch, §108 resignation auto-acceptance, §112 settlement due alerts
Schedule::command('hc:absence-watch')->dailyAt('06:30');
Schedule::command('hc:end-of-service')->dailyAt('06:45');
// Policy audit (requests / leave): §33 daily-note alert, §57 statutory leave scheduling, §68 trip report reminder
Schedule::command('hc:policy-reminders')->dailyAt('07:15');
// Policy audit (payroll / custody): §44 overdue cash-custody settlements, §46 monthly reconciliation
Schedule::command('hc:custody-overdue')->dailyAt('07:20');
Schedule::command('hc:finance-reconciliation')->monthlyOn(1, '08:00');
// §116: periodic access-rights review (every accessReviewDays days)
Schedule::command('hc:access-review')->dailyAt('08:10');
// Weekly reports (§34 + owner's notes): missing weeks noted in the file and the evaluation, plan / deadline reminders
Schedule::command('hc:weekly-watch')->dailyAt('07:30');
