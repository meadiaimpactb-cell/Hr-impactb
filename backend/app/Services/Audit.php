<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\AuditLog;

class Audit
{
    /** Append-only audit trail: who changed what, when, why, before/after. */
    public static function log(string $entity, mixed $entityId, string $action, mixed $before = null, mixed $after = null, ?string $reason = null): void
    {
        $req = request();
        AuditLog::create([
            'user_id' => auth()->id(),
            'entity' => $entity,
            // long ids (e.g. file paths) keep a short key here and the full value in `before`
            'entity_id' => $entityId === null ? null : (mb_strlen((string) $entityId) > 40 ? substr(sha1((string) $entityId), 0, 40) : (string) $entityId),
            'action' => $action,
            'before' => $before ?? (is_string($entityId) && mb_strlen($entityId) > 40 ? ['id' => $entityId] : null),
            'after' => $after,
            'reason' => $reason,
            'channel' => $req?->header('X-Channel', 'web') === 'mobile' ? 'mobile' : 'web',
            'ip' => $req?->ip(),
        ]);
    }

    /**
     * In-app notifications. $link = ['screen' => 'approvals'].
     *
     * @param  int|array<int|null>|null  $userIds
     */
    public static function notify(int|array|null $userIds, string $title, ?string $body = null, string $kind = 'info', ?array $link = null, ?int $requestId = null): void
    {
        $ids = array_unique(array_filter((array) $userIds));
        if (! $ids) {
            return;
        }
        $now = now();
        AppNotification::insert(array_map(fn ($id) => [
            'user_id' => $id, 'kind' => $kind, 'title' => $title, 'body' => $body,
            'link' => $link ? json_encode($link) : null, 'request_id' => $requestId,
            'created_at' => $now, 'updated_at' => $now,
        ], array_values($ids)));
        // Push delivery runs on the queue so request latency is unaffected (no-op until FCM is configured).
        if (PushService::enabled()) {
            \App\Jobs\SendPush::dispatch(array_values($ids), $title, $body, ['kind' => $kind, 'screen' => $link['screen'] ?? '', 'request_id' => (string) ($requestId ?? '')]);
        }
        // §115: approvals waiting and decisions that touch the employee also reach the official mailbox
        if (in_array($kind, ['approval', 'bad', 'warn'], true)) {
            self::email(array_values($ids), $title, $title, (string) $body, $link['screen'] ?? '', 'تنبيه من منصة HC');
        }
    }

    /** Formatted email copy in the recipient's company identity (queued; silent when the mailbox is not configured). */
    public static function email(array $userIds, string $subject, string $heading, string $body, string $screen = '', ?string $kicker = null): void
    {
        if (! $userIds || ! \App\Services\InviteService::mailConfigured()) {
            return;
        }
        $users = \App\Models\User::with('employee.company:id,name,logo_path,color,updated_at')->whereIn('id', $userIds)->where('active', true)->whereNotNull('email')->get();
        foreach ($users as $u) {
            $c = $u->employee?->company;
            try {
                \Illuminate\Support\Facades\Mail::to($u->email)->queue(new \App\Mail\BrandedNoticeMail($subject, $heading, $body, $screen, $c?->name, $c?->logo_url, $c?->color ?: '#1A2363', $kicker));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
