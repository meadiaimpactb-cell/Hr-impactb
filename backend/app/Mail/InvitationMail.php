<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Invitation to join the platform (or a password-reset link): one button, valid for a limited time. */
class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $url, public bool $reset = false, public ?string $invitedBy = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reset ? 'إعادة تعيين كلمة المرور — منصة HC' : 'دعوتك للانضمام إلى منصة HC — وادي الأثر المالية');
    }

    public function content(): Content
    {
        $e = $this->user->employee;

        return new Content(view: 'mail.invite', with: [
            'hours' => \App\Services\InviteService::HOURS,
            'company' => $e?->company?->name,
            'job' => $e?->jobTitle?->name,
            'apk' => rtrim(config('app.url'), '/').'/downloads/HC-mobile.apk',
            'site' => rtrim(config('app.url'), '/'),
        ]);
    }
}
