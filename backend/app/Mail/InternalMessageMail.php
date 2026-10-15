<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Email copy of an internal message, so recipients see it even when they are not signed in. */
class InternalMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $fromName, public string $subjectLine, public string $body, public int $threadId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'رسالة داخلية: '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<!doctype html><html lang="ar" dir="rtl"><body style="margin:0;background:#F6F7FB;font-family:Segoe UI,Tahoma,Arial,sans-serif;color:#262E6B;direction:rtl">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:28px 12px"><tr><td align="center">'
            .'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #E8EAF3;border-radius:16px">'
            .'<tr><td style="background:#262E6B;color:#fff;padding:18px 24px;border-radius:16px 16px 0 0;font-weight:700">HC — البريد الداخلي</td></tr>'
            .'<tr><td style="padding:22px 24px"><p style="margin:0 0 6px;color:#6B7094;font-size:13px">من: <b style="color:#262E6B">'.e($this->fromName).'</b></p>'
            .'<p style="margin:0 0 14px;font-size:16px;font-weight:700">'.e($this->subjectLine).'</p>'
            .'<div style="white-space:pre-wrap;line-height:1.9;font-size:14.5px;background:#F6F7FB;border-radius:12px;padding:14px">'.e($this->body).'</div>'
            .'<p style="text-align:center;margin:20px 0 0"><a href="'.e(rtrim(config('app.url'), '/').'/app/mail').'" style="display:inline-block;background:#1C84C6;color:#fff;text-decoration:none;padding:11px 22px;border-radius:12px;font-weight:700">فتح المحادثة والرد</a></p></td></tr>'
            .'<tr><td style="padding:12px 24px;border-top:1px solid #E8EAF3;font-size:12px;color:#6B7094">الرد يتم من داخل المنصة. رسالة تلقائية من منصة HC.</td></tr></table></td></tr></table></body></html>');
    }
}
