<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public static function haversine(float $a, float $b, float $c, float $d): float
    {
        $r = 6371000;
        $dLat = deg2rad($c - $a);
        $dLng = deg2rad($d - $b);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(sqrt($h));
    }

    /** @return array{ok:bool,location_id:?int,msg:string,dist:?int} */
    public static function geoCheck(Employee $e, ?float $lat, ?float $lng, ?int $acc): array
    {
        if ($e->punch_anywhere) { // HR exception: field staff, or a temporary allowance
            return ['ok' => true, 'location_id' => null, 'msg' => 'مسموح التحضير من أي مكان', 'dist' => null];
        }
        $extra = (int) $e->geo_tolerance + (int) Settings::get('geoToleranceMeters', $e->company_id, 0); // per-employee + company-wide margin
        $locs = self::fenceFor($e);
        if ($locs->isEmpty()) {
            return ['ok' => true, 'location_id' => null, 'msg' => 'لا موقع محدد للشركة — قُبل مع تسجيل الإحداثيات', 'dist' => null];
        }
        foreach ($locs as $l) {
            if ($l->scope === 'open') {
                return ['ok' => true, 'location_id' => $l->id, 'msg' => 'نطاق مفتوح', 'dist' => null];
            }
            if ($lat === null) {
                continue;
            }
            $dist = self::haversine($lat, $lng, $l->lat, $l->lng);
            if ($dist <= $l->radius + $extra + min($acc ?? 0, 50)) {
                return ['ok' => true, 'location_id' => $l->id, 'msg' => 'داخل نطاق '.$l->name, 'dist' => (int) round($dist)];
            }
        }

        $nearest = $lat === null ? null : (int) round($locs->filter(fn ($l) => $l->scope !== 'open')->map(fn ($l) => self::haversine($lat, $lng, $l->lat, $l->lng))->min() ?? 0);

        return ['ok' => false, 'location_id' => null, 'msg' => $lat === null ? 'تعذر تحديد الموقع' : 'خارج النطاق المسموح'.($nearest ? " (تبعد {$nearest} م)" : ''), 'dist' => $nearest];
    }

    /**
     * The sites an employee is measured against: the sites assigned to them, else their company's official sites
     * (is_main), else the group's official sites (company = null). So every employee is compared with the company's
     * real location without HR assigning a site to each person. Only sites marked official count as a fallback:
     * the generic group entries (open field, whole city) apply solely to employees they are assigned to.
     */
    public static function fenceFor(Employee $e)
    {
        $own = $e->locations;
        if ($own->isNotEmpty()) {
            return $own;
        }
        $company = \App\Models\Location::where('company_id', $e->company_id)->where('is_main', true)->get();

        return $company->isNotEmpty() ? $company : \App\Models\Location::whereNull('company_id')->where('is_main', true)->get();
    }

    public static function isWorkDay(Employee $e, Carbon $d): bool
    {
        return $e->isWorkDay($d->dayOfWeek);
    }

    public static function lateMinutes(Employee $e, Carbon $in): int
    {
        $h = $e->hoursFor($in->dayOfWeek);
        if (! $h) {
            return 0;
        }
        $start = $in->copy()->setTimeFromTimeString($h[0]);
        $flex = $e->flex_enabled ? (int) ($e->benefit('flexMinutes') ?? Settings::get('flexMinutes', $e->company_id, 0)) : 0;
        $late = (int) floor($start->diffInMinutes($in, false));

        return max(0, $late - $flex);
    }

    public static function earlyMinutes(Employee $e, Carbon $in, Carbon $out): int
    {
        $h = $e->hoursFor($in->dayOfWeek);
        if (! $h) {
            return 0;
        }
        $end = $in->copy()->setTimeFromTimeString($h[1]);
        if ($e->crossesMidnight($in->dayOfWeek) && $end->lte($in->copy()->startOfDay()->setTimeFromTimeString($h[0]))) {
            $end->addDay();
        }

        return max(0, (int) floor($out->diffInMinutes($end, false)));
    }

    /** Check-in or check-out (toggles). Server time is authoritative. */
    public static function punch(Employee $e, array $in): AttendanceRecord
    {
        if (! $e->punch_required) {
            throw ValidationException::withMessages(['punch' => 'أنت معفى من التحضير']);
        }
        $now = now();
        $day = $now->toDateString();
        $rec = AttendanceRecord::firstOrNew(['employee_id' => $e->id, 'work_date' => $day]);
        $geo = self::geoCheck($e, $in['lat'] ?? null, $in['lng'] ?? null, $in['accuracy'] ?? null);
        $lowAcc = ($in['accuracy'] ?? 0) > 50;
        $offline = (bool) ($in['offline'] ?? false);
        $noteRequired = $e->daily_note_required || Settings::get('dailyNoteEnabled', $e->company_id, false);

        if (! $rec->check_in) {
            $rec->fill([
                'status' => 'present', 'check_in' => $now, 'late_minutes' => self::lateMinutes($e, $now),
                'location_id' => $geo['location_id'], 'lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null,
                'accuracy' => $in['accuracy'] ?? null, 'distance_m' => $geo['dist'], 'device' => substr((string) ($in['device'] ?? request()->userAgent()), 0, 190),
                'offline' => $offline, 'geo_fail' => ! $geo['ok'], 'low_accuracy' => $lowAcc,
                // shown at once; only a punch outside the allowed range is flagged for review (low accuracy / offline sync are just recorded)
                'pending' => ! $geo['ok'], 'note_required' => $noteRequired,
                'client_time' => isset($in['client_time']) ? Carbon::parse($in['client_time']) : null,
            ]);
        } elseif (! $rec->check_out) {
            $note = trim((string) ($in['note'] ?? ''));
            $min = (int) Settings::get('dailyNoteMin', $e->company_id, 20);
            if ($noteRequired && mb_strlen($note) < $min) {
                throw ValidationException::withMessages(['note' => "المنجز اليومي إلزامي ({$min} حرفًا على الأقل)"]);
            }
            // check-out is measured too: leaving from outside the site is flagged for review like an outside check-in
            $rec->fill([
                'check_out' => $now, 'early_minutes' => self::earlyMinutes($e, $rec->check_in, $now),
                'out_lat' => $in['lat'] ?? null, 'out_lng' => $in['lng'] ?? null, 'note' => $note ?: $rec->note,
                'out_distance_m' => $geo['dist'], 'out_geo_fail' => ! $geo['ok'], 'pending' => $rec->pending || ! $geo['ok'],
            ]);
            if (! $geo['ok']) {
                Audit::notify(Access::hrUsersFor($e->company_id), 'انصراف خارج النطاق للمراجعة', $e->name.' — '.$geo['msg'], 'warn', ['screen' => 'attendance']);
            }
        } else {
            throw ValidationException::withMessages(['punch' => 'سجّلت الحضور والانصراف لهذا اليوم']);
        }
        $rec->save();
        if ($rec->pending && $rec->wasRecentlyCreated) {
            Audit::notify(Access::hrUsersFor($e->company_id), 'بصمة خارج النطاق للمراجعة', $e->name.' — '.$geo['msg'], 'warn', ['screen' => 'attendance']);
        }
        $rec->setAttribute('geo', $geo);

        return $rec;
    }

    public static function onLeave(int $empId, string $date): bool
    {
        return ApprovalRequest::where('employee_id', $empId)->whereIn('type', ['leave', 'trip'])->where('status', 'approved')
            ->where('data->from', '<=', $date)->where('data->to', '>=', $date)->exists();
    }

    /** Monthly summary for one employee. */
    public static function summary(Employee $e, string $period): array
    {
        $grace = (int) Settings::get('graceMinutes', $e->company_id, 0);
        $start = Carbon::parse($period.'-01');
        $end = min($start->copy()->endOfMonth(), now()->copy()->startOfDay());
        $recs = $e->attendance()->whereBetween('work_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->get()->keyBy(fn ($r) => $r->work_date->toDateString());
        $leaves = ApprovalRequest::where('employee_id', $e->id)->whereIn('type', ['leave', 'trip'])->where('status', 'approved')->get(['data']);
        $isLeave = fn ($d) => $leaves->contains(fn ($l) => ($l->data['from'] ?? '') <= $d && ($l->data['to'] ?? '') >= $d);
        $s = ['required' => 0, 'present' => 0, 'late' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'absent' => 0, 'leave' => 0, 'pending' => 0, 'no_checkout' => 0];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if ($d->lt($e->hire_date) || ! self::isWorkDay($e, $d)) {
                continue;
            }
            $ds = $d->toDateString();
            $r = $recs[$ds] ?? null;
            if ($isLeave($ds) || $r?->status === 'leave') {
                $s['leave']++;
                continue;
            }
            if (! $e->punch_required) {
                continue;
            }
            $s['required']++;
            if (! $r || $r->status === 'absent') {
                if ($ds !== now()->toDateString()) {
                    $s['absent']++;
                }
                continue;
            }
            $s['present']++;
            // §29: no check-out and no approved correction → only 25% of the day counts, the rest is absence
            if ($r->check_in && ! $r->check_out && ! $r->corrected && $ds !== now()->toDateString()) {
                $s['no_checkout']++;
                $s['absent'] += 0.75;
            }
            $lm = max(0, $r->late_minutes - $grace);
            if ($lm > 0) {
                $s['late']++;
                $s['late_minutes'] += $lm;
            }
            $s['early_minutes'] += $r->early_minutes;
            $s['pending'] += $r->pending ? 1 : 0;
        }

        return $s;
    }
}
