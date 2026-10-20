<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Services\Access;
use App\Services\AttendanceService;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Work-location helpers: read a Google Maps link into coordinates, and re-check pending punches after a location is corrected. */
class GeoController extends Controller
{
    /** Coordinates from any pasted Google Maps text: short links are followed server-side, then the final URL is parsed. */
    public function resolve(Request $request)
    {
        $text = trim((string) $request->validate(['text' => 'required|string|max:4000'])['text']);
        if ($p = self::parse($text)) {
            return $p;
        }
        if (preg_match('#https?://(maps\.app\.goo\.gl|goo\.gl/maps|maps\.google\.[a-z.]+|www\.google\.[a-z.]+/maps)[^\s"\']*#i', $text, $m)) {
            try {
                $res = Http::withOptions(['allow_redirects' => ['max' => 6, 'track_redirects' => true]])->timeout(10)->get($m[0]);
                $hops = $res->header('X-Guzzle-Redirect-History');
                foreach (array_reverse(array_filter(array_merge([$hops], explode(', ', (string) $hops)))) as $u) {
                    if ($p = self::parse(urldecode($u))) {
                        return $p;
                    }
                }
                if ($p = self::parse(urldecode($res->body()))) {
                    return $p;
                }
            } catch (\Throwable) {
            }
        }
        abort(422, 'تعذر قراءة الإحداثيات من النص — الصق رابط خرائط Google أو الإحداثيات مثل 24.586889, 46.596778');
    }

    /** lat,lng from coordinates, DMS, or Google Maps URL / embed patterns. */
    public static function parse(string $t): ?array
    {
        $ok = fn ($a, $b) => abs($a) <= 90 && abs($b) <= 180 && ($a != 0 || $b != 0) ? ['lat' => round($a, 7), 'lng' => round($b, 7)] : null;
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $t, $m)) return $ok((float) $m[1], (float) $m[2]);
        if (preg_match('/!2z([A-Za-z0-9+\/=_-]+)/', $t, $m) && ($dms = base64_decode(strtr($m[1], '-_', '+/'), true)) && ($p = self::parse($dms))) {
            return $p; // embed links carry the exact pin as base64 text (24°35'12.8"N 46°35'48.4"E); !2d/!3d is only the map centre
        }
        if (preg_match('/!2d(-?\d+\.\d+)!3d(-?\d+\.\d+)/', $t, $m)) return $ok((float) $m[2], (float) $m[1]); // embed: !2d = lng, !3d = lat
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $t, $m)) return $ok((float) $m[1], (float) $m[2]);
        if (preg_match('/[?&](?:q|ll|query|center|destination)=(-?\d+\.\d+),\s*(-?\d+\.\d+)/', $t, $m)) return $ok((float) $m[1], (float) $m[2]);
        if (preg_match('/(\d{1,3})°\s*(\d{1,2})[\'′]\s*([\d.]+)["″]?\s*([NS])[\s,]*(\d{1,3})°\s*(\d{1,2})[\'′]\s*([\d.]+)["″]?\s*([EW])/u', $t, $m)) {
            $a = $m[1] + $m[2] / 60 + $m[3] / 3600;
            $b = $m[5] + $m[6] / 60 + $m[7] / 3600;

            return $ok($m[4] === 'S' ? -$a : $a, $m[8] === 'W' ? -$b : $b);
        }
        if (preg_match('/^\s*(-?\d{1,2}\.\d{3,})\s*[, ]\s*(-?\d{1,3}\.\d{3,})\s*$/', $t, $m)) return $ok((float) $m[1], (float) $m[2]);

        return null;
    }

    /**
     * After a work location is corrected, the punches still waiting for review are checked again against the employees'
     * current locations; those now inside the fence are cleared (each change is logged), the rest stay for HR.
     */
    public function recheck(Request $request)
    {
        abort_unless($request->user()->isHr(), 403);
        $ids = Access::for()->employees()->pluck('id');
        $fixed = 0;
        $still = 0;
        foreach (AttendanceRecord::whereIn('employee_id', $ids)->where('pending', true)->whereNull('reviewed_by')
            ->where(fn ($q) => $q->where('geo_fail', true)->orWhere('out_geo_fail', true))->with('employee')->get() as $r) {
            $e = $r->employee;
            if (! $e) {
                continue;
            }
            $before = $r->only('geo_fail', 'out_geo_fail', 'pending', 'distance_m');
            if ($r->lat !== null) {
                $g = AttendanceService::geoCheck($e, (float) $r->lat, (float) $r->lng, $r->accuracy);
                $r->geo_fail = ! $g['ok'];
                $r->distance_m = $g['dist'];
                $r->location_id = $g['ok'] ? $g['location_id'] : $r->location_id;
            }
            if ($r->out_lat !== null) {
                $g = AttendanceService::geoCheck($e, (float) $r->out_lat, (float) $r->out_lng, null);
                $r->out_geo_fail = ! $g['ok'];
                $r->out_distance_m = $g['dist'];
            }
            $r->pending = (bool) ($r->geo_fail || $r->out_geo_fail);
            if ($r->isDirty()) {
                $r->save();
                Audit::log('attendance', $r->id, 'regeo', $before, $r->only('geo_fail', 'out_geo_fail', 'pending', 'distance_m'), 'إعادة فحص النطاق بعد تحديث موقع العمل');
            }
            $r->pending ? $still++ : $fixed++;
        }

        return ['cleared' => $fixed, 'still_pending' => $still];
    }
}
