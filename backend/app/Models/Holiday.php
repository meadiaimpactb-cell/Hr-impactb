<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Official holidays (§58): Eid al-Fitr 4 days, Eid al-Adha 4 days, National Day, Founding Day — or as amended.
 * The company's extra Eid days (tier benefit eidExtraDays) follow each Eid automatically, and the bridge day
 * (tier benefit bridgeDay) is a company holiday entered with kind = bridge.
 */
class Holiday extends Model
{
    protected $fillable = ['company_id', 'name', 'kind', 'from', 'to'];

    protected function casts(): array
    {
        return ['from' => 'date', 'to' => 'date'];
    }

    private static array $memo = [];

    /** Every holiday date (Y-m-d → name) that applies to an employee in a range, incl. the tier's extra Eid days. */
    public static function datesFor(Employee $e, string $from, string $to): array
    {
        $key = $e->company_id.'|'.$from.'|'.$to;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $extra = (int) ($e->benefit('eidExtraDays') ?? 0);
        $bridge = (bool) ($e->benefit('bridgeDay') ?? false);
        $out = [];
        $rows = self::where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $e->company_id))
            ->where('from', '<=', Carbon::parse($to)->addDays(5)->toDateString())->where('to', '>=', Carbon::parse($from)->subDays(5)->toDateString())->get();
        foreach ($rows as $h) {
            if ($h->kind === 'bridge' && ! $bridge) {
                continue;
            }
            $end = $h->to->copy();
            if ($h->kind === 'eid' && $extra > 0) {
                $end->addDays($extra);
            }
            for ($d = $h->from->copy(); $d->lte($end); $d->addDay()) {
                $out[$d->toDateString()] = $h->name.($d->gt($h->to) ? ' (يوم إضافي للشركة)' : '');
            }
        }

        return self::$memo[$key] = array_filter($out, fn ($k) => $k >= $from && $k <= $to, ARRAY_FILTER_USE_KEY);
    }

    public static function isHoliday(Employee $e, CarbonInterface|string $date): bool
    {
        $d = $date instanceof CarbonInterface ? $date->toDateString() : substr((string) $date, 0, 10);

        return isset(self::datesFor($e, substr($d, 0, 7).'-01', Carbon::parse($d)->endOfMonth()->toDateString())[$d]);
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
