<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Working-day arithmetic for policy deadlines (approval SLA, claims, reports): the employee's week, minus official holidays. */
class WorkCalendar
{
    public static function isWorkDay(?Employee $e, CarbonInterface $d): bool
    {
        $work = $e ? $e->isWorkDay($d->dayOfWeek) : ! in_array($d->dayOfWeek, [5, 6], true); // default week: Sunday–Thursday
        if (! $work) {
            return false;
        }

        return ! ($e && Holiday::isHoliday($e, $d));
    }

    /** Working days elapsed after $from up to $to (the start day itself is not counted). */
    public static function between(?Employee $e, CarbonInterface|string $from, CarbonInterface|string|null $to = null): int
    {
        $a = Carbon::parse($from)->startOfDay();
        $b = Carbon::parse($to ?? now())->startOfDay();
        $n = 0;
        for ($d = $a->copy()->addDay(); $d->lte($b) && $n < 400; $d->addDay()) {
            $n += self::isWorkDay($e, $d) ? 1 : 0;
        }

        return $n;
    }

    /** The date that is $days working days after $from. */
    public static function addDays(?Employee $e, CarbonInterface|string $from, int $days): Carbon
    {
        $d = Carbon::parse($from)->startOfDay();
        for ($i = 0; $i < $days && $i < 400;) {
            $d->addDay();
            if (self::isWorkDay($e, $d)) {
                $i++;
            }
        }

        return $d;
    }
}
