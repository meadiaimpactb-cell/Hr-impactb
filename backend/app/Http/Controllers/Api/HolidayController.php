<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Official holidays (§58) — managed by HR; the tier's extra Eid days and the bridge day follow automatically. */
class HolidayController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->integer('year') ?: (int) now()->year;
        $f = Access::for()->focus();

        return Holiday::where(fn ($q) => $q->whereYear('from', $year)->orWhereYear('to', $year))
            ->when($f, fn ($q) => $q->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $f)))
            ->orderBy('from')->get()->map(fn ($h) => $h->only('id', 'name', 'kind', 'company_id') + ['from' => $h->from->toDateString(), 'to' => $h->to->toDateString(), 'days' => $h->from->diffInDays($h->to) + 1]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403, 'إدارة العطلات الرسمية للموارد البشرية');
        $d = $request->validate(['name' => 'required|string|max:120', 'kind' => 'required|in:official,eid,company,bridge', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from',
            'company_id' => 'nullable|exists:companies,id', 'id' => 'nullable|exists:holidays,id']);
        $h = ! empty($d['id']) ? Holiday::findOrFail($d['id']) : new Holiday();
        $before = $h->exists ? $h->toArray() : null;
        $h->fill($d)->save();
        Holiday::flush();
        Audit::log('holidays', $h->id, $before ? 'update' : 'create', $before, $h->toArray());

        return response()->json($h, 201);
    }

    /**
     * §58: the year's official holidays from the Umm al-Qura calendar — Eid al-Fitr (1–4 Shawwal), Eid al-Adha
     * (9–12 Dhu al-Hijjah, from the day of Arafah), Founding Day (22 Feb) and National Day (23 Sep). A one-day
     * holiday on the weekend is compensated: Friday → the Thursday before, Saturday → the Sunday after.
     * Holidays already entered for the year are kept (HR adjusts any date the moon sighting moves).
     */
    public function generate(Request $request)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403, 'إدارة العطلات الرسمية للموارد البشرية');
        $year = $request->validate(['year' => 'required|integer|min:2020|max:2100'])['year'];
        abort_unless(class_exists(\IntlCalendar::class), 422, 'تقويم أم القرى غير متاح على الخادم — أدخل العطلات يدويًا');
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'en_US@calendar=islamic-umalqura');
        $g = function (int $hy, int $hm, int $hd) use ($cal) {
            $cal->clear();
            $cal->set($hy, $hm - 1, $hd, 12, 0, 0);

            return \Illuminate\Support\Carbon::createFromTimestamp((int) ($cal->getTime() / 1000), 'Asia/Riyadh')->startOfDay();
        };
        $cal->setTime(\Illuminate\Support\Carbon::create($year, 1, 1, 12, 0, 0, 'Asia/Riyadh')->getTimestampMs());
        $h0 = $cal->get(\IntlCalendar::FIELD_YEAR);
        $list = [];
        foreach ([$h0, $h0 + 1] as $hy) {
            $fitr = $g($hy, 10, 1);
            $adha = $g($hy, 12, 9);
            $list[] = ['عيد الفطر '.$hy.'هـ', 'eid', $fitr, $fitr->copy()->addDays(3)];
            $list[] = ['عيد الأضحى '.$hy.'هـ', 'eid', $adha, $adha->copy()->addDays(3)];
        }
        foreach ([['يوم التأسيس', 2, 22], ['اليوم الوطني', 9, 23]] as [$name, $m, $d]) {
            $day = \Illuminate\Support\Carbon::create($year, $m, $d, 0, 0, 0, 'Asia/Riyadh');
            $list[] = [$name, 'official', $day, $day->copy()];
            if ($day->isFriday() || $day->isSaturday()) {
                $comp = $day->isFriday() ? $day->copy()->subDay() : $day->copy()->addDay();
                $list[] = [$name.' (تعويض عن العطلة الأسبوعية)', 'official', $comp, $comp->copy()];
            }
        }
        $added = [];
        foreach ($list as [$name, $kind, $from, $to]) {
            if ($from->year !== $year && $to->year !== $year) {
                continue;
            }
            $exists = Holiday::whereNull('company_id')->where('kind', $kind)->where('from', '<=', $to->toDateString())->where('to', '>=', $from->toDateString())->exists();
            if (! $exists) {
                $h = Holiday::create(['company_id' => null, 'name' => $name, 'kind' => $kind, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
                Audit::log('holidays', $h->id, 'generate', null, $h->toArray(), 'تقويم أم القرى '.$year);
                $added[] = $name.' '.$from->format('d/m/Y').($to->ne($from) ? ' – '.$to->format('d/m/Y') : '');
            }
        }
        Holiday::flush();

        return ['added' => $added, 'year' => $year];
    }

    public function destroy(Request $request, Holiday $holiday)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);
        Audit::log('holidays', $holiday->id, 'delete', $holiday->toArray());
        $holiday->delete();

        return ['ok' => true];
    }
}
