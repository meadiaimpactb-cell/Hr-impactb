<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Policy engine: every rule is data (settings table) not code.
 * Company-specific values override group values. Cached per request + in cache store.
 */
class Settings
{
    private static ?array $memo = null;

    private const PREFIX = 'enc:v1:';

    private static function reveal(mixed $v): mixed
    {
        if (is_string($v) && str_starts_with($v, self::PREFIX)) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString(substr($v, strlen(self::PREFIX)));
            } catch (\Throwable) {
                return null;
            }
        }

        return $v;
    }

    private static function all(): array
    {
        return self::$memo ??= Cache::rememberForever('hc.settings', function () {
            $out = [];
            foreach (Setting::all(['key', 'value', 'company_id']) as $s) {
                $out[$s->key][$s->company_id ?? 0] = $s->value;
            }

            return $out;
        });
    }

    public static function get(string $key, ?int $companyId = null, mixed $default = null): mixed
    {
        $row = self::all()[$key] ?? null;
        if ($row === null) {
            return $default;
        }
        if ($companyId && array_key_exists($companyId, $row)) {
            return self::reveal($row[$companyId]);
        }

        return array_key_exists(0, $row) ? self::reveal($row[0]) : $default;
    }

    /** Setting value, falling back to the default in config/hc.php. */
    public static function conf(string $key, ?int $companyId = null): mixed
    {
        return self::get($key, $companyId) ?? config("hc.$key");
    }

    public static function set(string $key, mixed $value, ?int $companyId = null, ?string $reason = null): Setting
    {
        $old = Setting::where('key', $key)->where('company_id', $companyId)->first();
        // Secret-type settings (API keys, service accounts) are encrypted at rest with the app key
        if (is_string($value) && $value !== '' && \App\Models\SettingDefinition::where('key', $key)->value('type') === 'secret') {
            $value = self::PREFIX.\Illuminate\Support\Facades\Crypt::encryptString($value);
        }
        $setting = Setting::updateOrCreate(
            ['key' => $key, 'company_id' => $companyId],
            ['value' => $value, 'version' => ($old->version ?? 0) + 1, 'effective_from' => now()->toDateString(), 'changed_by' => auth()->id(), 'reason' => $reason],
        );
        Audit::log('settings', $key, 'update', is_string($old?->value) && str_starts_with($old->value, self::PREFIX) ? '••••' : $old?->value, is_string($value) && str_starts_with($value, self::PREFIX) ? '••••' : $value, $reason);
        self::flush();

        return $setting;
    }

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget('hc.settings');
    }
}
