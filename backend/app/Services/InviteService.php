<?php

namespace App\Services;

use App\Mail\InvitationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invitation links: the employee opens a single-use link from the email, chooses a password and is signed in —
 * no temporary password to hand over. The same mechanism serves "forgot password".
 */
class InviteService
{
    public const HOURS = 72;

    /** @return array{url:string,emailed:bool,expires_at:string} */
    public static function issue(User $user, bool $reset = false, ?User $by = null): array
    {
        // the login account keeps a copy of the employee's official email; refresh it so the invitation reaches the current address
        if ($user->employee && ($user->employee->email_enabled ? $user->employee->email : null) !== $user->email) {
            $user->forceFill(['email' => $user->employee->email_enabled ? $user->employee->email : null])->save();
        }
        // Re-sending reuses the outstanding link while it is still valid, so every email a person received keeps working.
        $plain = null;
        if ($user->invite_secret && $user->invite_expires_at && $user->invite_expires_at->gt(now()->addHours(6))) {
            try { $plain = \Illuminate\Support\Facades\Crypt::decryptString($user->invite_secret); } catch (\Throwable) { $plain = null; }
            if ($plain && hash('sha256', $plain) !== $user->invite_token) { $plain = null; }
        }
        if (! $plain) {
            $plain = Str::random(48);
            $user->forceFill(['invite_token' => hash('sha256', $plain), 'invite_secret' => \Illuminate\Support\Facades\Crypt::encryptString($plain), 'invite_expires_at' => now()->addHours(self::HOURS)])->save();
        }
        $user->forceFill(['invited_at' => now()])->save();
        $url = rtrim(config('app.url'), '/').'/app/welcome?token='.$plain.($reset ? '&reset=1' : '');
        $emailed = self::send($user, $url, $reset, $by);
        Audit::log('users', $user->id, $reset ? 'resetLink' : 'invite', null, ['emailed' => $emailed]);

        return ['url' => $url, 'emailed' => $emailed, 'expires_at' => $user->invite_expires_at->toIso8601String()];
    }

    public static function find(string $plain): ?User
    {
        $u = User::where('invite_token', hash('sha256', $plain))->where('active', true)->first();

        return $u && $u->invite_expires_at && $u->invite_expires_at->isFuture() ? $u : null;
    }

    /** Sets the chosen password, consumes the token and marks the invitation accepted. */
    public static function accept(User $user, string $password): void
    {
        $user->forceFill(['password' => $password, 'must_change_password' => false, 'invite_token' => null, 'invite_secret' => null, 'invite_expires_at' => null, 'invite_accepted_at' => now()])->save();
        $user->tokens()->delete();
        Audit::log('users', $user->id, 'inviteAccepted');
    }

    public static function mailConfigured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null], true) && (bool) config('mail.mailers.smtp.password', env('MAIL_PASSWORD'));
    }

    private static function send(User $user, string $url, bool $reset, ?User $by): bool
    {
        if (! $user->email || ! self::mailConfigured()) {
            return false;
        }
        try {
            Mail::to($user->email)->send(new InvitationMail($user, $url, $reset, $by?->name));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
