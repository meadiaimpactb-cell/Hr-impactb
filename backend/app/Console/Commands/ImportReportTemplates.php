<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates one monthly-report template per employee from deploy/report-templates.json
 * (produced by deploy/report_templates_to_json.py from the merged monthly reports workbook).
 * Each template holds the employee's own obligations only (axis, task, KPI); weights are split equally.
 *
 *   php artisan hc:import-report-templates storage/app/report-templates.json           # dry run
 *   php artisan hc:import-report-templates storage/app/report-templates.json --force   # write
 *   --map="ابو بكر=33"  links a sheet to an employee when the names are written differently
 */
class ImportReportTemplates extends Command
{
    protected $signature = 'hc:import-report-templates {file} {--force} {--map=* : sheet=employee_id for names written differently}';

    protected $description = 'Per-employee monthly report templates from the merged reports workbook';

    public static function norm(string $s): string
    {
        $s = preg_replace('/^\s*(د|م|أ|ا)\s*\.\s*/u', '', trim($s));
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ـ' => '']);

        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{Arabic}\p{L}\s]/u', ' ', $s)));
    }

    /** Employee whose name contains every word of the sheet name (words compared as prefixes, e.g. "منيره سلطان" ~ "منيرة السلطان"). */
    public static function match(string $label, $emps): ?Employee
    {
        $words = array_filter(explode(' ', self::norm($label)));
        if (! $words) {
            return null;
        }
        $hits = $emps->filter(function ($e) use ($words) {
            $name = explode(' ', self::norm($e->name));
            foreach ($words as $w) {
                $ok = false;
                foreach ($name as $n) {
                    $n2 = preg_replace('/^ال/u', '', $n);
                    $w2 = preg_replace('/^ال/u', '', $w);
                    if ($n === $w || $n2 === $w2 || (mb_strlen($w2) >= 3 && str_starts_with($n2, $w2))) {
                        $ok = true;
                        break;
                    }
                }
                if (! $ok) {
                    return false;
                }
            }

            return true;
        });

        return $hits->count() === 1 ? $hits->first() : null;
    }

    public static function weights(int $n): array
    {
        $base = intdiv(100, max($n, 1));
        $w = array_fill(0, $n, $base);
        for ($i = 0; $i < 100 - $base * $n; $i++) {
            $w[$i]++;
        }

        return $w;
    }

    public function handle(): int
    {
        $rows = json_decode(file_get_contents($this->argument('file')), true);
        $emps = Employee::where('status', '!=', 'terminated')->get(['id', 'name', 'company_id', 'job_title_id']);
        $map = [];
        foreach ($this->option('map') as $m) {
            [$sheet, $id] = array_map('trim', explode('=', $m, 2)) + [1 => null];
            $map[self::norm($sheet)] = (int) $id;
        }
        $plan = [];
        foreach ($rows as $r) {
            $e = isset($map[self::norm($r['sheet'])]) ? $emps->firstWhere('id', $map[self::norm($r['sheet'])])
                : (self::match($r['sheet'], $emps) ?? ($r['name'] ? self::match($r['name'], $emps) : null));
            $items = array_values(array_filter($r['items'], fn ($i) => trim($i['title'] ?? '') !== ''));
            $mark = $e ? '✓' : '✗';
            $this->line(sprintf('%s %-22s → %-32s %d بند', $mark, $r['sheet'], $e?->name ?? 'لا يوجد موظف مطابق', count($items)));
            if ($e && $items) {
                $plan[] = [$e, $r, $items];
            }
        }
        $this->info(count($plan).' of '.count($rows).' sheets matched');
        if (! $this->option('force')) {
            $this->warn('dry run — add --force to write');

            return 0;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as [$e, $r, $items]) {
                $w = self::weights(count($items));
                $tplItems = array_map(fn ($i, $k) => ['axis' => mb_substr($i['axis'] ?? '', 0, 160), 'title' => mb_substr($i['title'], 0, 300), 'target' => '',
                    'indicator' => mb_substr($i['indicator'] ?? '', 0, 300), 'weight' => $w[$k], 'source' => 'org'], $items, array_keys($items));
                $tpl = ReportTemplate::firstOrNew(['scope' => 'employee', 'scope_id' => $e->id]);
                $before = $tpl->exists ? $tpl->toArray() : null;
                $tpl->fill(['name' => 'نموذج '.$e->name, 'items' => $tplItems, 'active' => true, 'allow_employee_items' => true,
                    'max_employee_items' => $tpl->max_employee_items ?? 3, 'employee_item_weight_max' => $tpl->employee_item_weight_max ?? 15, 'due_day' => $tpl->due_day ?? 5,
                    'version' => $tpl->exists ? $tpl->version + 1 : 1])->save();
                Audit::log('report_templates', $tpl->id, $before ? 'update' : 'create', $before, ['employee' => $e->name, 'items' => count($tplItems)], 'نموذج تقرير شهري خاص بالموظف (من تقارير يناير 2026)');
                // untouched drafts pick up the new template right away
                MonthlyReport::where('employee_id', $e->id)->where('status', 'draft')->get()
                    ->filter(fn ($rep) => ! collect($rep->items)->contains(fn ($i) => trim((string) ($i['achieved'] ?? '')) !== ''))
                    ->each(fn ($rep) => $rep->update(['template_id' => $tpl->id, 'items' => \App\Http\Controllers\Api\ReportController::itemsFrom($tpl)]));
            }
        });
        $this->info('written');

        return 0;
    }
}
