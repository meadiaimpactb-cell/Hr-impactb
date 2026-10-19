<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Android app releases: the APK lives in public/downloads (same link the landing page uses) and version.json beside it. */
class AppVersionController extends Controller
{
    public const APK = 'downloads/HC-mobile.apk';

    public const META = 'downloads/version.json';

    /** Public: what the phones compare against. */
    public function version()
    {
        $meta = is_file(public_path(self::META)) ? json_decode((string) file_get_contents(public_path(self::META)), true) : [];
        $apk = public_path(self::APK);

        return ['version' => $meta['version'] ?? '1.0.0', 'build' => (int) ($meta['build'] ?? 1), 'notes' => $meta['notes'] ?? '', 'published_at' => $meta['published_at'] ?? null,
            'url' => url('/'.self::APK), 'size' => is_file($apk) ? filesize($apk) : 0, 'available' => is_file($apk)];
    }

    /** Group CEO publishes a new APK (+ version/build/notes); the app's "update" button then offers it. */
    public function publish(Request $request)
    {
        abort_unless($request->user()->hasRole('super'), 403, 'نشر إصدارات التطبيق صلاحية الرئيس التنفيذي للمجموعة');
        $d = $request->validate(['version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'], 'build' => 'required|integer|min:1', 'notes' => 'nullable|string|max:2000', 'apk' => 'nullable|file|max:204800']);
        if (! is_dir(public_path('downloads'))) {
            mkdir(public_path('downloads'), 0775, true);
        }
        if ($request->hasFile('apk')) {
            $f = $request->file('apk');
            abort_unless(strtolower($f->getClientOriginalExtension()) === 'apk', 422, 'الملف يجب أن يكون APK');
            $f->move(public_path('downloads'), 'HC-mobile.apk');
        }
        file_put_contents(public_path(self::META), json_encode(['version' => $d['version'], 'build' => (int) $d['build'], 'notes' => $d['notes'] ?? '', 'published_at' => now()->toDateTimeString()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        Audit::log('app_versions', $d['build'], 'publish', null, ['version' => $d['version'], 'apk' => $request->hasFile('apk')]);

        return $this->version();
    }
}
