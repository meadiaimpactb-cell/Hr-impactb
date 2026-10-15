<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes every transactional / demo record so real use can start, keeping configuration
 * (companies, tiers, policies, leave & request types, job titles, schedules, locations, lookups, templates)
 * and the named user accounts with their employee records.
 *
 *   php artisan hc:purge-demo                      # dry run: shows what would be deleted
 *   php artisan hc:purge-demo --force              # really delete
 *   php artisan hc:purge-demo --keep=a,b --force   # keep other logins (default: AccountsSeeder::ACCOUNTS)
 */
class PurgeDemoData extends Command
{
    protected $signature = 'hc:purge-demo {--keep= : comma-separated logins to keep} {--force : actually delete}';

    protected $description = 'Delete all demo/transactional data, keeping configuration and the real leadership accounts';

    /** Tables emptied completely. */
    private const TRUNCATE = [
        'advances', 'announcement_receipts', 'announcements', 'app_notifications', 'applications', 'approval_actions', 'approval_requests',
        'attendance_records', 'audit_logs', 'career_events', 'custody_items', 'custody_secret_logs', 'delegations', 'dev_records', 'documents',
        'evaluations', 'expense_claims', 'external_refs', 'failed_jobs', 'imports', 'insurance_policies', 'job_batches', 'job_openings', 'jobs',
        'kudos', 'leave_ledger', 'letters', 'mail_messages', 'mail_threads', 'monthly_reports', 'obstacles', 'payroll_runs', 'payslips',
        'projects', 'report_favorites', 'sessions', 'signed_docs', 'sync_outbox', 'terminations', 'violations', 'weekly_reports',
    ];

    public function handle(): int
    {
        $keepLogins = array_filter(array_map('trim', explode(',', $this->option('keep') ?: implode(',', array_column(\Database\Seeders\AccountsSeeder::ACCOUNTS, 'login')))));
        $users = User::whereIn(DB::raw('LOWER(login)'), array_map('strtolower', $keepLogins))->get();
        if ($users->count() !== count($keepLogins)) {
            $this->error('Not every login to keep exists: '.implode(', ', $keepLogins).' → found '.$users->pluck('login')->implode(', '));

            return self::FAILURE;
        }
        $userIds = $users->pluck('id')->all();
        $empIds = $users->pluck('employee_id')->filter()->values()->all();
        // keep the managers of kept employees too (should already be among them)
        $empIds = array_values(array_unique(array_merge($empIds, Employee::whereIn('id', $empIds)->pluck('manager_id')->filter()->all())));

        $this->line('Keeping users: '.$users->pluck('login')->implode(', ').' | employees: '.Employee::whereIn('id', $empIds)->pluck('emp_no')->implode(', '));
        $tables = array_filter(self::TRUNCATE, fn ($t) => Schema::hasTable($t));
        $plan = [];
        foreach ($tables as $t) {
            $plan[$t] = DB::table($t)->count();
        }
        $plan['employees'] = Employee::whereNotIn('id', $empIds)->count();
        $plan['users'] = User::whereNotIn('id', $userIds)->count();
        $plan['salary_components'] = DB::table('salary_components')->whereNotIn('employee_id', $empIds)->count();
        $plan['employee_location'] = DB::table('employee_location')->whereNotIn('employee_id', $empIds)->count();
        $plan['personal_access_tokens'] = DB::table('personal_access_tokens')->count();
        $plan['device_tokens'] = DB::table('device_tokens')->whereNotIn('user_id', $userIds)->count();
        $this->table(['table', 'rows to delete'], collect($plan)->filter()->map(fn ($n, $t) => [$t, $n])->values()->all());

        if (! $this->option('force')) {
            $this->warn('Dry run. Re-run with --force to delete.');

            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($tables, $empIds, $userIds) {
                foreach ($tables as $t) {
                    DB::table($t)->delete();
                }
                DB::table('salary_components')->whereNotIn('employee_id', $empIds)->delete();
                DB::table('employee_location')->whereNotIn('employee_id', $empIds)->delete();
                DB::table('device_tokens')->whereNotIn('user_id', $userIds)->delete();
                DB::table('personal_access_tokens')->delete(); // everyone signs in again
                Employee::whereNotIn('id', $empIds)->delete();
                User::whereNotIn('id', $userIds)->delete();
                // dangling references from configuration rows
                DB::table('companies')->whereNotIn('ceo_employee_id', $empIds)->whereNotNull('ceo_employee_id')->update(['ceo_employee_id' => null]);
                DB::table('settings')->whereNotIn('changed_by', $userIds)->whereNotNull('changed_by')->update(['changed_by' => null]);
                Employee::whereIn('id', $empIds)->whereNotIn('manager_id', $empIds)->whereNotNull('manager_id')->update(['manager_id' => null]);
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        foreach (['cache:clear'] as $c) {
            $this->callSilently($c);
        }
        $this->info('Done. users='.User::count().' employees='.Employee::count().' attendance='.DB::table('attendance_records')->count().' requests='.DB::table('approval_requests')->count());

        return self::SUCCESS;
    }
}
