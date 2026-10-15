<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Lookup;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads employees from the JSON produced by deploy/payroll_to_json.py (monthly payroll register).
 * Existing employees (mapped by number) are updated; new ones are created with salary components, documents and a login.
 * No email is sent — invitations are issued separately.
 *
 *   php artisan hc:import-payroll storage/app/payroll-import.json            # dry run
 *   php artisan hc:import-payroll storage/app/payroll-import.json --force    # write
 */
class ImportPayroll extends Command
{
    protected $signature = 'hc:import-payroll {file} {--force}';

    protected $description = 'Create / update employees from a payroll register export';

    private const COMP_NAMES = ['basic' => 'الأجر الأساسي', 'housing' => 'بدل السكن', 'transport' => 'بدل النقل', 'comm' => 'بدل الاتصال', 'other' => 'بدلات أخرى', 'incentive' => 'حافز شهري'];

    public function handle(): int
    {
        $data = json_decode(file_get_contents($this->argument('file')), true);
        $rows = $data['employees'] ?? [];
        $created = $updated = 0;
        $log = [];

        DB::beginTransaction();
        try {
            // contract type for remote staff
            if (! Lookup::where('group', 'contract_types')->where('code', 'remote')->exists()) {
                Lookup::create(['group' => 'contract_types', 'code' => 'remote', 'name' => 'عمل عن بُعد', 'name_en' => 'Remote', 'sort' => 40, 'active' => true]);
            }
            $impact = Company::where('code', 'IMPACTB')->first();
            if ($impact && ! $impact->name_en) {
                $impact->update(['name_en' => 'Impact Makers']);
            }
            $schedule = Schedule::orderBy('id')->value('id');

            foreach ($rows as $r) {
                $company = Company::where('code', $r['company'])->firstOrFail();
                $job = $this->job($r);
                $e = $r['existing'] ? Employee::where('emp_no', $r['existing'])->first() : Employee::where('emp_no', $r['hr_id'])->first();
                $base = [
                    'emp_no' => $r['hr_id'], 'company_id' => $company->id, 'hire_date' => $r['hire_date'],
                    'contract_type' => $r['remote'] ? 'remote' : 'full', 'punch_required' => true, 'punch_anywhere' => (bool) $r['remote'],
                    'nationality' => $r['nationality'], 'national_id' => $r['national_id'], 'birth_date' => $r['birth_date'], 'gender' => $r['gender'],
                    'phone' => $r['phone'], 'bank_iban' => $r['bank_iban'], 'country' => $r['bank_iban'] && ! str_starts_with($r['bank_iban'], 'SA') ? substr($r['bank_iban'], 0, 2) : 'SA',
                    'eosb_saudi' => ! $r['bank_iban'] || str_starts_with($r['bank_iban'], 'SA'), 'service_companies' => [$company->id],
                ];

                if ($e) { // leadership & already-registered staff: keep their name, title and official email
                    $keepTitle = in_array($e->emp_no, ['IVC-001', 'IVC-002', 'IVC-003'], true);
                    $e->update($base + ($keepTitle ? [] : ['name' => $r['name'], 'name_en' => $r['name_en'], 'job_title_id' => $job?->id]));
                    $e->components()->delete();
                    $updated++;
                    $log[] = ['تحديث', $r['hr_id'], $e->name, $company->code];
                } else {
                    $e = Employee::create($base + [
                        'name' => $r['name'], 'name_en' => $r['name_en'], 'job_title_id' => $job?->id, 'schedule_id' => $schedule, 'week_pattern' => '5',
                        'email' => $r['email'], 'email_enabled' => (bool) $r['email'], 'status' => 'active', 'advances_enabled' => true,
                    ]);
                    $e->career()->create(['type' => 'تعيين', 'detail' => $company->name.' · '.($job?->name ?? '—').' — من مسير رواتب سبتمبر 2026', 'occurred_on' => $r['hire_date']]);
                    if (! User::where('employee_id', $e->id)->exists() && ! User::where('login', $e->emp_no)->exists()) {
                        User::create(['name' => $e->name, 'login' => $e->emp_no, 'email' => $e->email_enabled ? $e->email : null, 'password' => Str::password(20),
                            'employee_id' => $e->id, 'roles' => ['emp'], 'must_change_password' => true, 'active' => true]);
                    }
                    $created++;
                    $log[] = ['إضافة', $r['hr_id'], $e->name, $company->code];
                }
                foreach ($r['components'] as $key => $amount) {
                    $e->components()->create(['key' => $key, 'name' => self::COMP_NAMES[$key] ?? $key, 'amount' => $amount, 'is_fixed' => true, 'source' => 'import', 'valid_from' => $r['hire_date']]);
                }
                $this->doc($e, 'id', $r['national_id'], $r['iqama_expiry']);
                $this->doc($e, 'passport', $r['passport'], null);
                $this->doc($e, 'contract', null, $r['contract_end']);
                if (! empty($r['note'])) {
                    $e->career()->create(['type' => 'ملاحظة', 'detail' => 'مسير سبتمبر 2026: '.$r['note'], 'occurred_on' => '2026-09-30']);
                }
            }
            Cache::forget('hc.lookups');
            $this->table(['العملية', 'الرقم', 'الاسم', 'الشركة'], $log);
            $this->info("created=$created updated=$updated");
            if (! $this->option('force')) {
                DB::rollBack();
                $this->warn('Dry run — nothing saved. Re-run with --force.');

                return self::SUCCESS;
            }
            DB::commit();
        } catch (\Throwable $ex) {
            DB::rollBack();
            $this->error($ex->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function job(array $r): ?JobTitle
    {
        if (! $r['title_en']) {
            return null;
        }
        $en = trim($r['title_en']);
        $j = JobTitle::whereRaw('LOWER(TRIM(name_en)) = ?', [mb_strtolower($en)])->first();
        if ($j) {
            return $j;
        }
        $code = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]+/', '-', $en), 26, ''));
        $code = rtrim($code, '-');
        $i = 1;
        while (JobTitle::where('code', $code)->exists()) {
            $code = Str::limit($code, 24, '').'-'.(++$i);
        }

        return JobTitle::create(['code' => $code, 'name' => $r['title_ar'] ?: $en, 'name_en' => $en, 'responsibilities' => [], 'kpis' => [], 'qualifications' => [], 'skills' => []]);
    }

    private function doc(Employee $e, string $type, ?string $number, ?string $expiry): void
    {
        if (! $number && ! $expiry) {
            return;
        }
        Document::updateOrCreate(['scope' => 'employee', 'owner_id' => $e->id, 'type' => $type], ['number' => $number, 'expiry_date' => $expiry, 'renewed' => false]);
    }
}
