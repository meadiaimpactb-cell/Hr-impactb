<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Company;
use App\Models\CustodyItem;
use App\Models\CustodyType;
use App\Models\Document;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\JobTitle;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\Lookup;
use App\Models\Schedule;
use App\Models\Tier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CSV imports: templates are data (config hc.importTemplates, overridable via setting "importTemplates").
 * Header row must match exactly; every row is validated; only valid rows are written; a batch can be undone.
 */
class ImportService
{
    public static function templates(): array
    {
        return array_replace_recursive(config('hc.importTemplates', []), (array) Settings::get('importTemplates', null, []));
    }

    /** Parse CSV text (UTF-8, optional BOM, comma or semicolon) → [headers, rows]. */
    public static function parse(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $delim = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $headers = array_map('trim', str_getcsv(array_shift($lines), $delim));
        $rows = [];
        foreach ($lines as $i => $l) {
            if (trim($l) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($l, $delim));
            $rows[] = ['line' => $i + 2, 'cells' => array_slice(array_pad($cells, count($headers), ''), 0, count($headers))];
        }

        return [$headers, $rows];
    }

    /** Validate all rows; returns ['ok'=>bool,'error'=>?, 'rows'=>[{line, data, errors[]}], 'valid'=>n, 'invalid'=>n]. */
    public static function validate(string $template, string $text): array
    {
        $tpl = self::templates()[$template] ?? null;
        if (! $tpl) {
            return ['ok' => false, 'error' => 'قالب غير معروف'];
        }
        [$headers, $rows] = self::parse($text);
        if ($headers !== array_values($tpl['headers'])) {
            return ['ok' => false, 'error' => 'رؤوس الأعمدة لا تطابق القالب تمامًا', 'expected' => array_values($tpl['headers']), 'got' => $headers];
        }
        $keys = array_keys($tpl['headers']);
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $data = array_combine($keys, $r['cells']);
            $errors = self::rowErrors($template, $data, $seen);
            $out[] = ['line' => $r['line'], 'data' => $data, 'errors' => $errors];
        }

        return ['ok' => true, 'rows' => $out, 'valid' => count(array_filter($out, fn ($x) => ! $x['errors'])), 'invalid' => count(array_filter($out, fn ($x) => $x['errors']))];
    }

    private static function rowErrors(string $t, array $d, array &$seen): array
    {
        $e = [];
        // closures capture $e by reference so every check appends to the same error list
        $req = function ($k, $label) use (&$e, $d) { if (($d[$k] ?? '') === '') { $e[] = "$label إلزامي"; } };
        $date = function ($k, $label) use (&$e, $d) { if (($d[$k] ?? '') !== '' && ! strtotime($d[$k])) { $e[] = "$label ليس تاريخًا صالحًا"; } };
        $company = function ($k) use (&$e, $d) { if (($d[$k] ?? '') !== '' && ! Company::where('code', strtoupper($d[$k]))->exists()) { $e[] = "رمز الشركة {$d[$k]} غير موجود"; } };
        $emp = function ($k, $label) use (&$e, $d) { if (($d[$k] ?? '') !== '' && ! Employee::where('emp_no', $d[$k])->exists()) { $e[] = "$label {$d[$k]} غير موجود"; } };
        switch ($t) {
            case 'companies':
                $req('code', 'رمز الشركة'); $req('name', 'الاسم');
                if (($d['code'] ?? '') !== '' && (Company::where('code', strtoupper($d['code']))->exists() || in_array(strtoupper($d['code']), $seen, true))) { $e[] = 'رمز الشركة مكرر'; }
                $seen[] = strtoupper($d['code'] ?? '');
                break;
            case 'jobs':
                $req('code', 'رمز الوظيفة'); $req('name', 'المسمى');
                if (($d['code'] ?? '') !== '' && (JobTitle::where('code', $d['code'])->exists() || in_array($d['code'], $seen, true))) { $e[] = 'رمز الوظيفة مكرر'; }
                $seen[] = $d['code'] ?? '';
                break;
            case 'employees':
                $req('emp_no', 'رقم الموظف'); $req('name', 'الاسم'); $req('company', 'رمز الشركة'); $req('hire_date', 'تاريخ الالتحاق'); $req('basic', 'الأجر الأساسي');
                $date('hire_date', 'تاريخ الالتحاق'); $date('birth_date', 'تاريخ الميلاد'); $company('company');
                if (($d['emp_no'] ?? '') !== '' && (Employee::where('emp_no', $d['emp_no'])->exists() || in_array($d['emp_no'], $seen, true))) { $e[] = 'رقم الموظف مكرر'; }
                if (($d['job'] ?? '') !== '' && ! JobTitle::where('code', $d['job'])->exists()) { $e[] = "رمز الوظيفة {$d['job']} غير موجود"; }
                if (($d['basic'] ?? '') !== '' && ! is_numeric($d['basic'])) { $e[] = 'الأجر الأساسي ليس رقمًا'; }
                if (($d['contract'] ?? '') !== '' && Lookup::name('contract_types', $d['contract']) === null) { $e[] = 'نوع التعاقد غير معرّف: '.$d['contract']; }
                $seen[] = $d['emp_no'] ?? '';
                break;
            case 'org':
                $req('emp_no', 'رقم الموظف'); $req('manager_no', 'رقم المدير'); $emp('emp_no', 'رقم الموظف'); $emp('manager_no', 'رقم المدير'); $date('effective', 'تاريخ السريان');
                if (($d['emp_no'] ?? '') !== '' && $d['emp_no'] === ($d['manager_no'] ?? '')) { $e[] = 'الموظف لا يكون مديرًا لنفسه'; }
                break;
            case 'components':
                $req('emp_no', 'رقم الموظف'); $req('key', 'نوع المكون'); $req('amount', 'القيمة'); $emp('emp_no', 'رقم الموظف'); $date('effective', 'تاريخ السريان');
                if (($d['amount'] ?? '') !== '' && ! is_numeric($d['amount'])) { $e[] = 'القيمة ليست رقمًا'; }
                break;
            case 'balances':
                $req('emp_no', 'رقم الموظف'); $req('type', 'نوع الإجازة'); $req('days', 'الرصيد'); $emp('emp_no', 'رقم الموظف'); $date('cutoff', 'تاريخ القطع');
                if (($d['type'] ?? '') !== '' && ! LeaveType::where('code', $d['type'])->exists()) { $e[] = "نوع الإجازة {$d['type']} غير موجود"; }
                if (($d['days'] ?? '') !== '' && ! is_numeric($d['days'])) { $e[] = 'الرصيد ليس رقمًا'; }
                break;
            case 'custody':
                $req('emp_no', 'رقم الموظف'); $req('type', 'نوع العهدة'); $req('ident', 'الوصف'); $emp('emp_no', 'رقم الموظف'); $date('handed', 'تاريخ التسليم');
                if (($d['type'] ?? '') !== '' && ! CustodyType::where('name', $d['type'])->orWhere('name_en', $d['type'])->exists()) { $e[] = "نوع العهدة {$d['type']} غير موجود"; }
                break;
            case 'advances':
                $req('emp_no', 'رقم الموظف'); $req('amount', 'المبلغ'); $req('installments', 'الأقساط'); $emp('emp_no', 'رقم الموظف'); $date('start', 'تاريخ البداية');
                foreach (['amount', 'installments', 'paid', 'remaining'] as $k) { if (($d[$k] ?? '') !== '' && ! is_numeric($d[$k])) { $e[] = "$k ليس رقمًا"; } }
                break;
            case 'empDocs':
                $req('emp_no', 'رقم الموظف'); $req('type', 'نوع الوثيقة'); $emp('emp_no', 'رقم الموظف'); $date('issue', 'تاريخ الإصدار'); $date('expiry', 'تاريخ الانتهاء');
                if (($d['type'] ?? '') !== '' && ! self::docType($d['type'], 'employee')) { $e[] = "نوع الوثيقة {$d['type']} غير موجود"; }
                break;
            case 'compDocs':
                $req('company', 'رمز الشركة'); $req('type', 'نوع الوثيقة'); $company('company'); $date('issue', 'تاريخ الإصدار'); $date('expiry', 'تاريخ الانتهاء');
                if (($d['type'] ?? '') !== '' && ! self::docType($d['type'], 'company')) { $e[] = "نوع الوثيقة {$d['type']} غير موجود"; }
                break;
        }

        return $e;
    }

    private static function docType(string $nameOrCode, string $scope): ?string
    {
        foreach (Lookup::grouped()['document_types'] ?? [] as $t) {
            if (($t['code'] === $nameOrCode || $t['name'] === $nameOrCode) && ($t['meta']['scope'] ?? '') === $scope) {
                return $t['code'];
            }
        }

        return null;
    }

    /** Write valid rows in one transaction; returns the batch. */
    public static function commit(string $template, string $text, ?string $fileName, int $by): ImportBatch
    {
        $v = self::validate($template, $text);
        abort_unless($v['ok'], 422, $v['error'] ?? 'ملف غير صالح');
        $created = [];
        $errors = [];
        DB::transaction(function () use ($template, $v, &$created, &$errors) {
            foreach ($v['rows'] as $r) {
                if ($r['errors']) {
                    $errors[] = ['line' => $r['line'], 'errors' => $r['errors']];
                    continue;
                }
                foreach (self::write($template, $r['data']) as $c) {
                    $created[] = $c;
                }
            }
        });
        $yes = fn ($x) => in_array(mb_strtolower(trim((string) $x)), ['نعم', 'yes', '1', 'true'], true);
        $batch = ImportBatch::create(['template' => $template, 'file_name' => $fileName, 'by' => $by, 'total' => count($v['rows']), 'valid' => $v['valid'], 'invalid' => $v['invalid'], 'errors' => $errors, 'created' => $created, 'status' => 'done']);
        Audit::log('imports', $batch->id, 'commit', null, ['template' => $template, 'valid' => $v['valid'], 'invalid' => $v['invalid']]);

        return $batch;
    }

    private static function yes(?string $x): bool
    {
        return in_array(mb_strtolower(trim((string) $x)), ['نعم', 'yes', '1', 'true'], true);
    }

    /** Insert one validated row; returns [[table, id], …] for undo. */
    private static function write(string $t, array $d): array
    {
        $emp = fn () => Employee::where('emp_no', $d['emp_no'])->firstOrFail();
        switch ($t) {
            case 'companies':
                $tier = Tier::where('name', $d['tier'] ?? '')->first() ?? Tier::where('is_default', true)->first() ?? Tier::orderBy('rank')->first();
                $c = Company::create(['code' => strtoupper($d['code']), 'name' => $d['name'], 'name_en' => $d['name_en'] ?: null, 'legal_no' => $d['legal_no'] ?: null, 'tax_no' => $d['tax_no'] ?: null, 'website' => ($d['website'] ?? '') ?: null, 'country' => $d['country'] ?: 'SA',
                    'currency' => $d['currency'] ?: 'SAR', 'timezone' => $d['timezone'] ?: 'Asia/Riyadh', 'tier_id' => $tier?->id, 'odoo_id' => $d['odoo_id'] ?: null]);

                return [['companies', $c->id]];
            case 'jobs':
                $split = fn ($k) => array_values(array_filter(array_map('trim', preg_split('/[|؛;]/', (string) ($d[$k] ?? '')))));
                $j = JobTitle::create(['code' => $d['code'], 'name' => $d['name'], 'name_en' => $d['name_en'] ?: null, 'family' => $d['family'] ?: null, 'purpose' => $d['purpose'] ?: null, 'responsibilities' => $split('responsibilities'), 'qualifications' => $split('qualifications'), 'skills' => $split('skills')]);

                return [['job_titles', $j->id]];
            case 'employees':
                $company = Company::with('tier')->where('code', strtoupper($d['company']))->firstOrFail();
                $e = Employee::create(['emp_no' => $d['emp_no'], 'name' => $d['name'], 'name_en' => $d['name_en'] ?: null, 'nationality' => $d['nationality'] ?: null, 'national_id' => $d['national_id'] ?: null,
                    'birth_date' => $d['birth_date'] ? Carbon::parse($d['birth_date'])->toDateString() : null, 'phone' => $d['phone'] ?: null, 'email' => $d['email'] ?: null, 'email_enabled' => self::yes($d['email_enabled'] ?? 'نعم'),
                    'company_id' => $company->id, 'job_title_id' => $d['job'] ? JobTitle::where('code', $d['job'])->value('id') : null, 'dept' => $d['dept'] ?: null, 'hire_date' => Carbon::parse($d['hire_date'])->toDateString(),
                    'contract_type' => $d['contract'] ?: 'full', 'country' => $d['country'] ?: 'SA', 'schedule_id' => $d['schedule'] ? Schedule::where('name', $d['schedule'])->value('id') : Schedule::min('id'),
                    'punch_required' => self::yes($d['punch'] ?? 'نعم'), 'eosb_saudi' => self::yes($d['eosb_saudi'] ?? 'نعم'), 'status' => 'active', 'advances_enabled' => true]);
                $b = $company->tier?->benefits ?? [];
                $basic = (float) $d['basic'];
                $comps = [['key' => 'basic', 'name' => 'الأجر الأساسي', 'amount' => $basic, 'source' => 'import'], ['key' => 'housing', 'name' => 'بدل السكن', 'amount' => round($basic * ($b['housingPct'] ?? 0) / 100, 2), 'source' => 'tier'], ['key' => 'transport', 'name' => 'بدل النقل', 'amount' => max(round($basic * (float) ($b['transportPct'] ?? 0) / 100, 2), (float) ($b['transportMin'] ?? $b['transport'] ?? 0)), 'source' => 'tier']];
                if (! empty($b['comm'])) {
                    $comps[] = ['key' => 'comm', 'name' => 'بدل الاتصال', 'amount' => $b['comm'], 'source' => 'tier'];
                }
                foreach ($comps as $c) {
                    $e->components()->create($c + ['valid_from' => $e->hire_date]);
                }
                if (($d['location'] ?? '') !== '' && ($loc = \App\Models\Location::where('name', $d['location'])->value('id'))) {
                    $e->locations()->attach($loc);
                }

                return [['employees', $e->id]];
            case 'org':
                $e = $emp();
                $e->update(['manager_id' => Employee::where('emp_no', $d['manager_no'])->value('id')]);

                return [];
            case 'components':
                $e = $emp();
                $c = $e->components()->create(['key' => $d['key'], 'name' => Lookup::name('component_names', $d['key']) ?? $d['key'], 'amount' => (float) $d['amount'], 'is_fixed' => ! str_contains(mb_strtolower($d['fixed'] ?? 'ثابت'), 'متغير'),
                    'source' => 'import', 'valid_from' => $d['effective'] ? Carbon::parse($d['effective'])->toDateString() : $e->hire_date]);

                return [['salary_components', $c->id]];
            case 'balances':
                $e = $emp();
                $cut = $d['cutoff'] ? Carbon::parse($d['cutoff']) : now();
                $l = LeaveLedger::create(['employee_id' => $e->id, 'year' => $cut->year, 'leave_type' => $d['type'], 'entry' => 'opening', 'days' => (float) $d['days'], 'note' => $d['note'] ?: 'رصيد افتتاحي مستورد', 'occurred_on' => $cut->toDateString()]);

                return [['leave_ledger', $l->id]];
            case 'custody':
                $e = $emp();
                $type = CustodyType::where('name', $d['type'])->orWhere('name_en', $d['type'])->firstOrFail();
                $status = in_array($d['status'] ?? '', ['available', 'متاحة'], true) ? 'available' : 'in_use';
                $item = new CustodyItem(['custody_type_id' => $type->id, 'company_id' => $e->company_id, 'ident' => $d['ident'], 'fields' => array_filter(['serial' => $d['serial'] ?? null, 'package' => $d['package'] ?? null]), 'value' => (float) ($d['value'] ?: 0),
                    'employee_id' => $status === 'in_use' ? $e->id : null, 'status' => $status, 'history' => []]);
                $item->log('استيراد', 'سجل مستورد'.($d['handed'] ? ' — تسليم '.$d['handed'] : ''), $e->id);
                $item->save();

                return [['custody_items', $item->id]];
            case 'advances':
                $e = $emp();
                $n = max(1, (int) $d['installments']);
                $remaining = (float) ($d['remaining'] !== '' ? $d['remaining'] : $d['amount'] - (float) ($d['paid'] ?: 0));
                $per = round($remaining / $n, 2);
                $start = $d['start'] ? Carbon::parse($d['start'])->startOfMonth() : now()->addMonthNoOverflow()->startOfMonth();
                $schedule = [];
                for ($i = 0; $i < $n; $i++) {
                    $schedule[] = ['period' => $start->copy()->addMonthsNoOverflow($i)->format('Y-m'), 'amount' => $i === $n - 1 ? round($remaining - $per * ($n - 1), 2) : $per, 'status' => 'due'];
                }
                $a = Advance::create(['employee_id' => $e->id, 'amount' => (float) $d['amount'], 'purpose' => 'سلفة مستوردة', 'installments' => $n, 'monthly_amount' => $per, 'paid' => (float) ($d['paid'] ?: 0), 'remaining' => $remaining,
                    'status' => $remaining > 0 ? 'repaying' : 'settled', 'start_period' => $schedule[0]['period'], 'schedule' => $schedule]);

                return [['advances', $a->id]];
            case 'empDocs':
                $e = $emp();
                $doc = Document::create(['scope' => 'employee', 'owner_id' => $e->id, 'type' => self::docType($d['type'], 'employee'), 'number' => $d['number'] ?: null, 'issue_date' => $d['issue'] ? Carbon::parse($d['issue'])->toDateString() : null,
                    'expiry_date' => $d['expiry'] ? Carbon::parse($d['expiry'])->toDateString() : null, 'issuer' => $d['issuer'] ?: null, 'alerted' => []]);

                return [['documents', $doc->id]];
            case 'compDocs':
                $c = Company::where('code', strtoupper($d['company']))->firstOrFail();
                $doc = Document::create(['scope' => 'company', 'owner_id' => $c->id, 'type' => self::docType($d['type'], 'company'), 'number' => $d['number'] ?: null, 'issue_date' => $d['issue'] ? Carbon::parse($d['issue'])->toDateString() : null,
                    'expiry_date' => $d['expiry'] ? Carbon::parse($d['expiry'])->toDateString() : null, 'follow_user_id' => $d['follow'] ? \App\Models\User::where('login', $d['follow'])->orWhere('email', $d['follow'])->value('id') : null, 'alerted' => []]);

                return [['documents', $doc->id]];
        }

        return [];
    }

    public static function undo(ImportBatch $b): int
    {
        abort_unless($b->status === 'done', 422, 'الدفعة مُلغاة مسبقًا');
        $n = 0;
        DB::transaction(function () use ($b, &$n) {
            foreach (array_reverse($b->created ?? []) as [$table, $id]) {
                if ($table === 'employees') {
                    Employee::whereKey($id)->forceDelete();
                } else {
                    DB::table($table)->where('id', $id)->delete();
                }
                $n++;
            }
            $b->update(['status' => 'undone']);
        });
        Audit::log('imports', $b->id, 'undo', null, ['rows' => $n]);

        return $n;
    }
}
