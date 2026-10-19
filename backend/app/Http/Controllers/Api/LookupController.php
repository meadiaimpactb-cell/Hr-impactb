<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Schedule;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Master data managed from the UI: leave types, job titles, work schedules, check-in locations. */
class LookupController extends Controller
{
    private const MODELS = ['leave-types' => LeaveType::class, 'job-titles' => JobTitle::class, 'schedules' => Schedule::class, 'locations' => Location::class];

    private function model(string $kind): string
    {
        abort_unless(isset(self::MODELS[$kind]), 404);

        return self::MODELS[$kind];
    }

    private function authorize(Request $r): void
    {
        abort_unless($r->user()->hasRole('super', 'hr_mgr') || Access::for($r->user())->can('ORG', 'edit'), 403);
    }

    public function index(Request $request, string $kind)
    {
        $m = $this->model($kind);
        $q = $m::query();
        if ($kind === 'job-titles' || $kind === 'schedules') {
            $q->withCount('employees');
        }
        if ($kind === 'leave-types') {
            $q->orderBy('sort');
        }
        if ($kind === 'locations') {
            $q->with('company:id,name');
            if ($f = Access::for()->focus()) {
                $q->where(fn ($w) => $w->where('company_id', $f)->orWhereNull('company_id')); // the company's sites + group-wide sites
            }
        }

        return $q->orderBy('id')->get();
    }

    public function store(Request $request, string $kind)
    {
        $this->authorize($request);
        $m = $this->model($kind);
        if (in_array($kind, ['leave-types', 'job-titles'], true) && blank($request->input('code'))) {
            // the internal code is optional for users: generate a unique one (LV7, JT12, ...)
            $prefix = $kind === 'leave-types' ? 'LV' : 'JT';
            $n = $m::count() + 1;
            while ($m::where('code', $prefix.$n)->exists()) {
                $n++;
            }
            $request->merge(['code' => $prefix.$n]);
        }
        $row = $m::create($this->validated($request, $kind));
        Audit::log($kind, $row->id, 'create', null, $row->toArray());

        return response()->json($row, 201);
    }

    public function update(Request $request, string $kind, int $id)
    {
        $this->authorize($request);
        $m = $this->model($kind);
        $row = $m::findOrFail($id);
        $before = $row->toArray();
        $data = $this->validated($request, $kind, $row);
        if ($kind === 'job-titles') {
            $data['version'] = $row->version + 1;
        }
        $row->update($data);
        Audit::log($kind, $row->id, 'update', $before, $row->toArray());
        if ($kind === 'job-titles') {
            $users = \App\Models\User::whereIn('employee_id', $row->employees()->pluck('id'))->pluck('id')->all();
            Audit::notify($users, 'تحديث وصفك الوظيفي', "صدرت النسخة {$row->version} من وظيفة «{$row->name}»", 'info', ['screen' => 'me']);
        }

        return $row;
    }

    public function destroy(Request $request, string $kind, int $id)
    {
        $this->authorize($request);
        $m = $this->model($kind);
        $row = $m::findOrFail($id);
        $inUse = match ($kind) {
            'job-titles' => Employee::where('job_title_id', $id)->count(),
            'schedules' => Employee::where('schedule_id', $id)->count(),
            'locations' => \App\Models\AttendanceRecord::where('location_id', $id)->count() + \DB::table('employee_location')->where('location_id', $id)->count(),
            'leave-types' => ApprovalRequest::where('type', 'leave')->where('data->type', $row->code)->count(),
        };
        if ($inUse) {
            // Never delete data in use: deactivate instead (leave types) or ask to reassign.
            if ($kind === 'leave-types') {
                $row->update(['active' => false]);

                return ['deactivated' => true];
            }
            throw ValidationException::withMessages(['id' => "لا يمكن الحذف: مستخدم في {$inUse} سجل. انقل الموظفين أولًا."]);
        }
        $row->delete();
        Audit::log($kind, $id, 'delete', $row->toArray());

        return ['deleted' => true];
    }

    private function validated(Request $r, string $kind, $row = null): array
    {
        return match ($kind) {
            'leave-types' => $r->validate([
                'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('leave_types')->ignore($row?->id)], 'name' => 'required|string|max:80',
                'name_en' => 'nullable|string|max:80', 'accrual' => 'required|in:monthly,yearly,case,none', 'quota_key' => 'nullable|string|max:40',
                'deducts' => 'boolean', 'attachment_required' => 'boolean', 'carry_over' => 'boolean', 'pay' => 'required|in:full,tiered,policy,none',
                'max_per_case' => 'nullable|integer|min:1', 'sort' => 'nullable|integer', 'active' => 'boolean',
                'extra_approval' => 'nullable|array', 'extra_approval.*' => Rule::in(array_keys(\App\Services\ApprovalService::ROLES)),
            ]),
            'job-titles' => $r->validate([
                'code' => ['required', 'string', 'max:30', Rule::unique('job_titles')->ignore($row?->id)], 'name' => 'required|string|max:120',
                'name_en' => 'nullable|string|max:120', 'family' => 'nullable|string|max:80', 'purpose' => 'nullable|string|max:2000',
                'responsibilities' => 'nullable|array', 'qualifications' => 'nullable|array', 'skills' => 'nullable|array', 'kpis' => 'nullable|array',
            ]),
            'schedules' => $this->scheduleData($r),
            'locations' => $this->locationData($r),
        };
    }

    /**
     * Reads coordinates (and the place name when present) out of any Google Maps link or embed code:
     * share links (maps.app.goo.gl / goo.gl/maps — followed to their final URL), "@lat,lng" URLs,
     * "?q=lat,lng" / "ll=" / "query=" links, and <iframe> embed codes with "!3dLAT!2dLNG".
     */
    public function resolveMapLink(Request $request)
    {
        $this->authorize($request);
        $raw = trim((string) $request->validate(['url' => 'required|string|max:4000'])['url']);
        if (preg_match('/src=["\']([^"\']+)["\']/u', $raw, $m)) {
            $raw = html_entity_decode($m[1]);
        }
        if (preg_match('/^(-?\d{1,2}\.\d+)\s*[, ]\s*(-?\d{1,3}\.\d+)$/', $raw, $m)) { // plain "lat, lng" (right-click a point in Google Maps → copy)
            return ['lat' => round((float) $m[1], 7), 'lng' => round((float) $m[2], 7), 'name' => null, 'url' => "https://www.google.com/maps?q={$m[1]},{$m[2]}"];
        }
        abort_unless(preg_match('#^https?://#i', $raw), 422, 'الصق رابط خرائط Google أو كود التضمين (iframe) أو الإحداثيات بصيغة 24.5865, 46.5969');
        $url = $raw;
        $short = (bool) preg_match('#^https?://(maps\.app\.goo\.gl|goo\.gl|g\.co|maps\.google\.com/\?)#i', $url);
        if ($short) {
            try {
                $res = \Illuminate\Support\Facades\Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])->withOptions(['allow_redirects' => ['max' => 8, 'track_redirects' => true]])->timeout(12)->get($url);
                $hist = $res->handlerStats()['redirect_url'] ?? null;
                $url = $hist ?: (string) $res->effectiveUri();
            } catch (\Throwable) {
                abort(422, 'تعذر فتح الرابط المختصر؛ افتحه في المتصفح وانسخ الرابط الكامل أو كود التضمين');
            }
        }
        $lat = $lng = null;
        $name = null;
        if (preg_match('/!3d(-?\d{1,2}\.\d+)!2d(-?\d{1,3}\.\d+)/', $url, $m) || preg_match('/!2d(-?\d{1,3}\.\d+)!3d(-?\d{1,2}\.\d+)/', $url, $n)) {
            if (isset($m[1])) { [$lat, $lng] = [(float) $m[1], (float) $m[2]]; } else { [$lng, $lat] = [(float) $n[1], (float) $n[2]]; }
        } elseif (preg_match('/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/', $url, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        } elseif (preg_match('/[?&](?:q|ll|query|destination|center)=(-?\d{1,2}\.\d+)[, ]+(-?\d{1,3}\.\d+)/', $url, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        }
        if (preg_match('/!2z([A-Za-z0-9_-]+)/', $url, $m)) { // embed codes carry the place name base64url-encoded
            $decoded = base64_decode(strtr($m[1], '-_', '+/'), true);
            if ($decoded && preg_match('//u', $decoded)) $name = trim(preg_replace('/\s+/u', ' ', $decoded));
        } elseif (preg_match('#/maps/place/([^/@?]+)#', $url, $m)) {
            $name = trim(str_replace('+', ' ', urldecode($m[1])));
        }
        if ($lat === null && $short) {
            // Google's short share links of a saved place resolve to a page without coordinates (they are loaded by script),
            // so there is nothing reliable to read; the embed code and the address-bar URL (…/@lat,lng…) both carry them.
            abort(422, 'رابط المشاركة المختصر'.($name ? " ({$name})" : '').' لا يحمل الإحداثيات. افتح الموقع في خرائط Google ثم: «مشاركة ← تضمين خريطة» وانسخ الكود، أو انسخ الرابط من شريط العنوان في المتصفح، أو انقر بزر الفأرة الأيمن على الموقع وانسخ الإحداثيات');
        }
        abort_if($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180, 422, 'لم أجد إحداثيات في هذا الرابط؛ استخدم «مشاركة ← تضمين خريطة» أو انسخ الرابط من شريط العنوان أو الإحداثيات مباشرة');

        return ['lat' => round($lat, 7), 'lng' => round($lng, 7), 'name' => $name, 'url' => $raw];
    }

    private function locationData(Request $r): array
    {
        $v = $r->validate([
            'name' => 'required|string|max:120', 'company_id' => 'nullable|exists:companies,id', 'scope' => 'required|in:point,city,region,open',
            'address' => 'nullable|string|max:200', 'map_url' => 'nullable|string|max:500', 'is_main' => 'boolean',
            'lat' => 'required_unless:scope,open|nullable|numeric|between:-90,90', 'lng' => 'required_unless:scope,open|nullable|numeric|between:-180,180',
            'radius' => 'required_unless:scope,open|nullable|integer|min:10|max:200000',
        ]);

        return array_merge($v, ['lat' => $v['lat'] ?? 0, 'lng' => $v['lng'] ?? 0, 'radius' => $v['radius'] ?? 0]);
    }

    private function scheduleData(Request $r): array
    {
        $d = $r->validate([
            'name' => 'required|string|max:120', 'week_days' => 'required|array|min:1', 'week_days.*' => 'integer|between:0,6',
            'hours' => 'required|array', 'hours.*' => 'array|size:2', 'hours.*.*' => 'date_format:H:i', 'flex' => 'boolean', 'cross_midnight' => 'boolean',
        ]);
        $d['week_days'] = array_values(array_unique(array_map('intval', $d['week_days'])));
        $d['hours'] = array_intersect_key($d['hours'], array_flip(array_map('strval', $d['week_days'])));
        foreach ($d['week_days'] as $day) {
            if (! isset($d['hours'][(string) $day])) {
                throw ValidationException::withMessages(['hours' => 'حدد ساعات كل يوم عمل']);
            }
        }
        $d['week_pattern'] = count($d['week_days']) >= 6 ? '6' : '5';

        return $d;
    }
}
