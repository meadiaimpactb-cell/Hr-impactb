<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (HTTP v1) using a service-account JSON stored in settings (fcmServiceAccount).
 * The OAuth2 access token is minted with a self-signed RS256 JWT and cached for ~55 minutes.
 */
class PushService
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('pushEnabled', null, false) && Settings::get('fcmProjectId') && Settings::get('fcmServiceAccount');
    }

    /** Send to all devices of the given users; invalid tokens are pruned. Returns number of messages sent. */
    public static function send(array $userIds, string $title, ?string $body, array $data = []): int
    {
        if (! self::enabled() || ! $userIds) {
            return 0;
        }
        $tokens = DB::table('device_tokens')->whereIn('user_id', $userIds)->get(['id', 'token']);
        if ($tokens->isEmpty()) {
            return 0;
        }
        $access = self::accessToken();
        $project = Settings::get('fcmProjectId');
        $sent = 0;
        foreach ($tokens as $t) {
            $res = Http::withToken($access)->timeout(10)->post("https://fcm.googleapis.com/v1/projects/$project/messages:send", [
                'message' => ['token' => $t->token, 'notification' => ['title' => $title, 'body' => mb_substr((string) $body, 0, 200)],
                    'data' => array_map('strval', $data), 'android' => ['priority' => 'high'], 'apns' => ['payload' => ['aps' => ['sound' => 'default']]]],
            ]);
            if ($res->ok()) {
                $sent++;
            } elseif (in_array($res->json('error.details.0.errorCode') ?? $res->json('error.status'), ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true)) {
                DB::table('device_tokens')->where('id', $t->id)->delete();
            } else {
                Log::warning('FCM send failed', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
            }
        }

        return $sent;
    }

    private static function accessToken(): string
    {
        return Cache::remember('hc.fcm.token', 3300, function () {
            $sa = json_decode((string) Settings::get('fcmServiceAccount'), true) ?: throw new \RuntimeException('حساب خدمة Firebase غير صالح');
            $now = time();
            $b64 = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
            $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
            openssl_sign("$header.$claims", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = "$header.$claims.".$b64($sig);
            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]);
            if (! $res->ok()) {
                throw new \RuntimeException('تعذر الحصول على رمز FCM: '.$res->status());
            }

            return $res->json('access_token');
        });
    }
}
