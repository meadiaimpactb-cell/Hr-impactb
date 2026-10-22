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
    /**
     * Owner's note (22/10): a daily achievement reaches the employee's direct manager as soon as it is written (in the
     * platform and by email) — HR when there is no manager. HR and the group CEO get the day's summary next morning.
     */
    public static function noteWritten(AttendanceRecord $rec, bool $edited = false): void
    {
        $e = $rec->employee;
        if (! $e || trim((string) $rec->note) === '') {
            return;
        }
        $mgr = $e->manager_id ? \App\Models\User::where('employee_id', $e->manager_id)->where('active', true)->value('id') : null;
        \App\Services\Audit::notify($mgr ?: \App\Services\Access::hrManagersFor($e->company_id), 'المنجز اليومي'.($edited ? ' (معدّل)' : '').' — '.$e->name.' · '.$rec->work_date->format('d/m/Y'),
            mb_substr((string) $rec->note, 0, 600), 'info', ['screen' => 'attendance', 'tab' => 'achievements', 'date' => $rec->work_date->toDateString()]);
    }

    public static function fenceFor(Employee $e)
    {
        $own = $e->locations;
        $company = \App\Models\Location::where('company_id', $e->company_id)->where('is_main', true)->get();
        if ($own->isNotEmpty()) {
            // owner's note (22/10): the company's official site always counts too — an employee at his own company's
            // site is inside, whatever other site he is also assigned to
            return $own->merge($company)->unique('id')->values();
        }

        return $company->isNotEmpty() ? $company : \App\Models\Location::whereNull('company_id')->where('is_main', true)->get();
    }

    public static function isWorkDay(Employee $e, Carbon $d): bool
    {
        // official holidays (and the tier's extra Eid / bridge days) are never working days, so never absence
        if (class_exists(\App\Models\Holiday::class) && method_exists(\App\Models\Holiday::class, 'isHoliday') && \App\Models\Holiday::isHoliday($e, $d)) {
            return false;
        }

        return $e->isWorkDay($d->dayOfWeek);
    }

    /** §24: Ramadan period (settings ramadanFrom / ramadanTo) applies to Muslim employees. */
    public static function inRamadan(Employee $e, Carbon $d): bool
    {
        if (isset($e->muslim) && ! $e->muslim) {
            return false;
        }
        $from = (string) Settings::get('ramadanFrom', $e->company_id, '');
        $to = (string) Settings::get('ramadanTo', $e->company_id, '');
        if ($from === '' || $to === '') {
            return false;
        }
        $ds = $d->toDateString();

        return $ds >= $from && $ds <= $to;
    }

    /**
     * Expected shift of the day: [start, end] as datetimes. A shift ending before it starts crosses midnight.
     * During Ramadan the expected end is capped at start + ramadanDailyHours (6 h, i.e. 36 h a week) — §24.
     *
     * @return array{0:Carbon,1:Carbon}|null
     */
    public static function shift(Employee $e, Carbon $day): ?array
    {
        $h = $e->hoursFor($day->dayOfWeek);
        if (! $h) {
            return null;
        }
        $start = $day->copy()->startOfDay()->setTimeFromTimeString($h[0]);
        $end = $day->copy()->startOfDay()->setTimeFromTimeString($h[1]);
        if ($end->lte($start)) {
            $end->addDay();
        }
        if (self::inRamadan($e, $day)) {
            $cap = $start->copy()->addMinutes((int) round((float) Settings::get('ramadanDailyHours', $e->company_id, 6) * 60));
            if ($end->gt($cap)) {
                $end = $cap;
            }
        }

        return [$start, $end];
    }

    /** First day (Saturday) of the policy week that contains $d. */
    /** First day of the employee's work week: Sunday, or Saturday for a six-day week (owner's note). */
    public static function weekStart(Carbon $d, ?Employee $e = null): Carbon
    {
        return $d->copy()->startOfDay()->startOfWeek($e ? $e->weekStartsOn() : Carbon::SUNDAY);
    }

    /**
     * §29/30: the flex allowance is a weekly budget of minutes, not a daily one. It comes with the company's
     * classification (tier benefit flexMinutes) automatically; «الساعة المرنة» on the employee grants the default
     * allowance to someone whose classification gives none (an approved flexible arrangement).
     */
    public static function flexBudget(Employee $e): int
    {
        $tier = (int) ($e->benefit('flexMinutes') ?? 0);

        return $tier > 0 ? $tier : ($e->flex_enabled ? (int) Settings::get('flexMinutes', $e->company_id, 0) : 0);
    }

    /** §60: a nursing mother's daily hour (nursingMinutes) until employees.nursing_until — taken at the start or the end of the day. */
    public static function nursingMinutes(Employee $e, Carbon $d): int
    {
        return $e->nursing_until && $e->nursing_until->gte($d->copy()->startOfDay()) ? (int) Settings::get('nursingMinutes', $e->company_id, 60) : 0;
    }

    /** Flex minutes already absorbed this week, the given day excluded. */
    public static function flexUsed(Employee $e, Carbon $d): int
    {
        $ws = self::weekStart($d, $e);

        return (int) AttendanceRecord::where('employee_id', $e->id)->whereBetween('work_date', [$ws->toDateString(), $ws->copy()->addDays(6)->toDateString()])
            ->whereDate('work_date', '!=', $d->toDateString())->sum('flex_minutes');
    }

    /**
     * Late minutes left after the weekly flex budget. $absorbed receives the minutes the budget covered; they must be
     * made up within the same week (see weekly()).
     */
    public static function lateMinutes(Employee $e, Carbon $in, ?int &$absorbed = null): int
    {
        $absorbed = 0;
        $s = self::shift($e, $in);
        if (! $s) {
            return 0;
        }
        $late = max(0, (int) floor($s[0]->diffInMinutes($in, false)) - self::nursingMinutes($e, $in)); // §60: the nursing hour is not lateness
        if ($late > 0 && ($budget = self::flexBudget($e)) > 0) {
            $absorbed = min($late, max(0, $budget - self::flexUsed($e, $in)));
        }

        return $late - $absorbed;
    }

    public static function earlyMinutes(Employee $e, Carbon $in, Carbon $out): int
    {
        $s = self::shift($e, $in);
        if (! $s) {
            return 0;
        }

        // §60: what is left of the nursing hour after a late start covers an early leave
        $nursing = max(0, self::nursingMinutes($e, $in) - max(0, (int) floor($s[0]->diffInMinutes($in, false))));

        return max(0, (int) floor($out->diffInMinutes($s[1], false)) - $nursing);
    }

    /** §30: employees whose classification grants it (athar) get one remote working day a week. */
    public static function remoteEligible(Employee $e): bool
    {
        return (int) ($e->benefit('remote') ?? 0) >= 1;
    }

    public static function remoteUsed(Employee $e, Carbon $d): bool
    {
        $ws = self::weekStart($d, $e);

        return AttendanceRecord::where('employee_id', $e->id)->where('remote', true)->whereBetween('work_date', [$ws->toDateString(), $ws->copy()->addDays(6)->toDateString()])
            ->whereDate('work_date', '!=', $d->toDateString())->exists();
    }

    /**
     * Weekly balance (§29/30): required minutes of the week's working days so far vs. minutes actually worked. Flex
     * minutes absorbed must be made up within the same week, so any shortfall is reported.
     */
    public static function weekly(Employee $e, ?Carbon $any = null): array
    {
        $any ??= now();
        $ws = self::weekStart($any, $e);
        $we = $ws->copy()->addDays(6);
        $today = now()->toDateString();
        $recs = AttendanceRecord::where('employee_id', $e->id)->whereBetween('work_date', [$ws->toDateString(), $we->toDateString()])->get()->keyBy(fn ($r) => $r->work_date->toDateString());
        $required = $worked = $flexUsed = 0;
        $days = [];
        for ($d = $ws->copy(); $d->lte($we); $d->addDay()) {
            $ds = $d->toDateString();
            $r = $recs[$ds] ?? null;
            $flexUsed += (int) ($r->flex_minutes ?? 0);
            if ($ds > $today || ($e->hire_date && $d->lt($e->hire_date)) || ! self::isWorkDay($e, $d) || $r?->status === 'leave' || self::onLeave($e->id, $ds)) {
                continue;
            }
            $s = self::shift($e, $d);
            if (! $s || ($ds === $today && ! $r?->check_out)) {
                continue; // today counts once the day is closed
            }
            $req = (int) $s[0]->diffInMinutes($s[1]);
            $w = $r && $r->check_in && $r->check_out && $r->status !== 'absent' ? (int) $r->check_in->diffInMinutes($r->check_out) : 0;
            $required += $req;
            $worked += $w;
            $days[] = ['date' => $ds, 'required' => $req, 'worked' => $w, 'flex' => (int) ($r->flex_minutes ?? 0), 'remote' => (bool) ($r->remote ?? false)];
        }
        $budget = self::flexBudget($e);

        return ['from' => $ws->toDateString(), 'to' => $we->toDateString(), 'required_minutes' => $required, 'worked_minutes' => $worked,
            'shortfall_minutes' => max(0, $required - $worked), 'flex_budget' => $budget, 'flex_used' => $flexUsed, 'flex_left' => max(0, $budget - $flexUsed),
            'remote_eligible' => self::remoteEligible($e), 'remote_used' => $recs->contains(fn ($r) => (bool) $r->remote), 'ramadan' => self::inRamadan($e, $any), 'days' => $days];
    }

    /** Working days strictly after $from up to $to (the employee's own working days when given, else Sunday–Thursday). */
    public static function workingDaysBetween(Carbon $from, Carbon $to, ?Employee $e = null): int
    {
        $n = 0;
        for ($d = $from->copy()->startOfDay()->addDay(); $d->lte($to); $d->addDay()) {
            if ($e ? self::isWorkDay($e, $d) : ! in_array($d->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true)) {
                $n++;
            }
        }

        return $n;
    }

    /** §27: a correction filed more than correctionDays working days after the day concerned is late (flag only, never blocked). */
    public static function correctionLate(string $date, Carbon|string|null $filedAt = null, ?Employee $e = null): bool
    {
        $limit = (int) Settings::get('correctionDays', $e?->company_id, 3);

        return self::workingDaysBetween(Carbon::parse($date), Carbon::parse($filedAt ?? now()), $e) > $limit;
    }

    /** §27: entry from the alternative paper register (platform unavailable), logged with source = paper. */
    public static function paperEntry(Employee $e, string $date, ?string $in, ?string $out, ?string $note): AttendanceRecord
    {
        $rec = AttendanceRecord::firstOrNew(['employee_id' => $e->id, 'work_date' => $date]);
        $before = $rec->exists ? $rec->toArray() : null;
        $checkIn = $in ? Carbon::parse("$date $in") : $rec->check_in;
        $checkOut = $out ? Carbon::parse("$date $out") : $rec->check_out;
        if ($checkIn && $checkOut && $checkOut->lte($checkIn)) {
            $checkOut->addDay(); // night shift
        }
        $absorbed = 0;
        $late = $checkIn ? self::lateMinutes($e, $checkIn, $absorbed) : 0;
        $rec->fill(['status' => 'present', 'check_in' => $checkIn, 'check_out' => $checkOut, 'source' => 'paper', 'pending' => false, 'geo_fail' => false, 'out_geo_fail' => false,
            'late_minutes' => $late, 'flex_minutes' => $absorbed, 'early_minutes' => $checkIn && $checkOut ? self::earlyMinutes($e, $checkIn, $checkOut) : 0,
            'reviewed_by' => auth()->id(), 'note' => trim(($rec->note ? $rec->note."\n" : '').'[سجل ورقي بديل'.($note ? ': '.$note : '').']')]);
        $rec->save();
        Audit::log('attendance', $rec->id, 'paper', $before, $rec->only('work_date', 'check_in', 'check_out', 'source'), $note);

        return $rec;
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
        if (! $rec->check_in) {
            // §24: a shift that runs past midnight is closed on the day it started — a check-out after midnight belongs to
            // yesterday's open attendance (night shift, or checked in less than 16 hours ago) instead of opening a new day
            $open = AttendanceRecord::where('employee_id', $e->id)->whereDate('work_date', $now->copy()->subDay()->toDateString())
                ->whereNotNull('check_in')->whereNull('check_out')->first();
            if ($open && ($e->schedule?->cross_midnight || ($now->hour < 6 && $open->check_in->gt($now->copy()->subHours(16))))) {
                $rec = $open;
            }
        }
        $geo = self::geoCheck($e, $in['lat'] ?? null, $in['lng'] ?? null, $in['accuracy'] ?? null);
        $lowAcc = ($in['accuracy'] ?? 0) > 50;
        $offline = (bool) ($in['offline'] ?? false);
        $noteRequired = $e->daily_note_required || Settings::get('dailyNoteEnabled', $e->company_id, false);

        if (! $rec->check_in) {
            // §30: an eligible employee (athar tier + flex) may work one day a week remotely — a punch from outside the
            // site is then recorded as that week's remote day instead of going to review
            $remote = false;
            if (! $geo['ok'] && ($in['remote'] ?? true) && self::remoteEligible($e)) {
                if (! self::remoteUsed($e, $now)) {
                    $remote = true;
                    $geo = ['ok' => true, 'location_id' => null, 'msg' => 'يوم عمل عن بُعد (يوم واحد أسبوعيًا)', 'dist' => $geo['dist']];
                } else {
                    $geo['msg'] .= ' — استُخدم يوم العمل عن بُعد لهذا الأسبوع';
                }
            }
            $absorbed = 0;
            $late = self::lateMinutes($e, $now, $absorbed);
            $rec->fill([
                'status' => 'present', 'check_in' => $now, 'late_minutes' => $late, 'flex_minutes' => $absorbed, 'remote' => $remote, 'source' => 'app',
                'location_id' => $geo['location_id'], 'lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null,
                'accuracy' => $in['accuracy'] ?? null, 'distance_m' => $geo['dist'], 'device' => substr((string) ($in['device'] ?? request()->userAgent()), 0, 190),
                'offline' => $offline, 'geo_fail' => ! $geo['ok'], 'low_accuracy' => $lowAcc,
                // shown at once; only a punch outside the allowed range is flagged for review (low accuracy / offline sync are just recorded)
                'pending' => ! $geo['ok'] || (bool) $rec->voided_check_in, 'note_required' => $noteRequired, 'note_pending' => $noteRequired,
                'client_time' => isset($in['client_time']) ? Carbon::parse($in['client_time']) : null,
            ]);
            \Illuminate\Support\Facades\DB::table('presence_checks')->where('employee_id', $e->id)->where('kind', 'checkin')->where('status', 'pending')
                ->update(['status' => 'confirmed', 'responded_at' => $now, 'note' => 'سجّل حضوره '.$now->format('H:i'), 'updated_at' => $now]);
        } elseif (! $rec->check_out) {
            $note = trim((string) ($in['note'] ?? ''));
            $noteBefore = (string) $rec->note;
            $min = (int) Settings::get('dailyNoteMin', $e->company_id, 20);
            // owner's note: the check-out is recorded, but when the daily achievement is required and not written the
            // day stays pending ("التحضير معلق") until the employee writes it
            $note = $note !== '' ? $note : (string) $rec->note;
            $notePending = $noteRequired && mb_strlen(trim($note)) < $min;
            if ($rec->remote && ! $geo['ok']) {
                $geo = ['ok' => true, 'location_id' => null, 'msg' => 'يوم عمل عن بُعد', 'dist' => $geo['dist']];
            }
            // check-out is measured too: leaving from outside the site is flagged for review like an outside check-in
            $rec->fill([
                'check_out' => $now, 'early_minutes' => self::earlyMinutes($e, $rec->check_in, $now),
                'out_lat' => $in['lat'] ?? null, 'out_lng' => $in['lng'] ?? null, 'note' => $note ?: $rec->note, 'note_required' => $noteRequired || $rec->note_required, 'note_pending' => $notePending,
                'out_distance_m' => $geo['dist'], 'out_geo_fail' => ! $geo['ok'], 'pending' => $rec->pending || ! $geo['ok'],
            ]);
            if (! $geo['ok']) {
                Audit::notify(Access::hrUsersFor($e->company_id), 'انصراف خارج النطاق للمراجعة', $e->name.' — '.$geo['msg'], 'warn', ['screen' => 'attendance']);
            }
        } else {
            throw ValidationException::withMessages(['punch' => 'سجّلت الحضور والانصراف لهذا اليوم']);
        }
        $rec->save();
        if (isset($noteBefore) && $note !== '' && $note !== $noteBefore) { // a check-out with the day's achievement → the manager is told
            self::noteWritten($rec->loadMissing('employee'), $noteBefore !== '');
        }
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
        $s = ['required' => 0, 'present' => 0, 'late' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'absent' => 0, 'leave' => 0, 'pending' => 0, 'no_checkout' => 0, 'note_pending' => 0];
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
            $s['note_pending'] += $r->note_pending ? 1 : 0;
        }

        return $s;
    }
}
