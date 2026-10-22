<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollRun;
use Illuminate\Support\Carbon;

/**
 * Package allowances follow the package (owner's note): the group CEO sets the benefits of each classification
 * (housing %, transport, communication and any allowance item he adds), and every employee of the companies in that
 * package gets them in his salary components automatically. A per-employee exception (custom_benefits, approved
 * through the benefit request chain) overrides the package value; an allowance fixed in the contract or by an approved
 * allowance change (source contract / custom) is never touched.
 */
class BenefitSync
{
    /** key => [name, monthly amount] the package gives this employee on this basic wage. */
    public static function allowances(Employee $e, float $basic): array
    {
        $num = fn ($k) => (float) ($e->benefit($k) ?? 0);
        $out = [
            'housing' => ['بدل السكن', round($basic * $num('housingPct') / 100, 2)],
            'transport' => ['بدل النقل', max(round($basic * $num('transportPct') / 100, 2), (float) ($e->benefit('transportMin') ?? $e->benefit('transport') ?? 0))],
            'comm' => ['بدل الاتصال', $num('comm')],
        ];
        $labels = array_merge((array) config('hc.benefitLabels'), (array) Settings::get('benefitLabels'));
        foreach ((array) Settings::get('benefitTypes', null, []) as $key => $type) {
            if ($type === 'allowance') {
                $out[$key] = [$labels[$key] ?? $key, $num($key)];
            }
        }

        return $out;
    }

    /**
     * From when a package change takes effect for a company: the current month while its payroll is still a draft
     * (or not started), otherwise the next month — an approved payroll is never changed behind finance's back.
     */
    public static function effectiveFrom(?int $companyId): string
    {
        $period = now()->format('Y-m');
        $run = PayrollRun::where('company_id', $companyId)->where('period', $period)->first();

        return $run && $run->status !== 'draft' ? now()->addMonthNoOverflow()->startOfMonth()->toDateString() : now()->startOfMonth()->toDateString();
    }

    /** Re-applies the package allowances to one employee. Returns how many allowances changed. */
    public static function sync(Employee $e, string $why, ?string $from = null): int
    {
        if ($e->status === 'terminated') {
            return 0;
        }
        $e->loadMissing('company.tier');
        $e->load('components');
        $basic = $e->componentAmount('basic');
        if ($basic <= 0) {
            return 0;
        }
        $from ??= self::effectiveFrom($e->company_id);
        $changed = [];
        foreach (self::allowances($e, $basic) as $key => [$name, $amount]) {
            $cur = $e->components()->where('key', $key)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))->orderByDesc('valid_from')->orderByDesc('id')->first();
            if ($cur && $cur->source !== 'tier') {
                continue; // contract / approved change wins over the package
            }
            if (($cur ? abs((float) $cur->amount - $amount) < 0.01 : $amount <= 0)) {
                continue;
            }
            if ($cur && $cur->valid_from && $cur->valid_from->toDateString() >= $from) {
                $amount > 0 ? $cur->update(['amount' => $amount, 'name' => $name]) : $cur->delete();
            } else {
                $cur?->update(['valid_to' => Carbon::parse($from)->subDay()->toDateString()]);
                if ($amount > 0) {
                    $e->components()->create(['key' => $key, 'name' => $name, 'amount' => $amount, 'source' => 'tier', 'valid_from' => $from]);
                }
            }
            $changed[] = $name.': '.number_format((float) ($cur->amount ?? 0), 2).' → '.number_format($amount, 2);
        }
        if ($changed) {
            $e->addCareer('تحديث بدلات التصنيف', $why.' — '.implode('، ', $changed).' (من '.Carbon::parse($from)->format('d/m/Y').')');
        }

        return count($changed);
    }

    /** Every active employee of the given companies. Returns [employees changed, allowances changed]. */
    public static function syncCompanies(array $companyIds, string $why): array
    {
        $emps = $changed = 0;
        Employee::whereIn('company_id', $companyIds)->where('status', '!=', 'terminated')->with('company.tier')->get()
            ->each(function (Employee $e) use ($why, &$emps, &$changed) {
                $n = self::sync($e, $why);
                $emps += $n ? 1 : 0;
                $changed += $n;
            });

        return [$emps, $changed];
    }
}
