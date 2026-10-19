<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §115: a formatted email copy of an announcement or an important notification, in the employee's company identity
 * (logo + colour) with the group's name, and a button back into the platform.
 */
class BrandedNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $subjectLine, public string $heading, public string $body, public string $screen = '',
        public ?string $companyName = null, public ?string $logoUrl = null, public string $color = '#1A2363', public ?string $kicker = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $url = rtrim(config('app.url'), '/').'/app/'.ltrim($this->screen, '/');
        $logo = $this->logoUrl ? '<img src="'.e($this->logoUrl).'" alt="" style="height:44px;max-width:170px;background:#fff;border-radius:8px;padding:4px 8px">' : '<b style="font-size:18px">HC</b>';

        return new Content(htmlString: '<!doctype html><html lang="ar" dir="rtl"><body style="margin:0;background:#F6F7FB;font-family:Segoe UI,Tahoma,Arial,sans-serif;color:#262E6B;direction:rtl">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:28px 12px"><tr><td align="center">'
            .'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #E8EAF3;border-radius:16px">'
            .'<tr><td style="background:'.e($this->color).';color:#fff;padding:16px 24px;border-radius:16px 16px 0 0"><table width="100%" role="presentation"><tr><td style="color:#fff;font-weight:700">'.e($this->companyName ?: 'مجموعة وادي الأثر المالية').'<div style="font-size:11px;opacity:.85;font-weight:400">HC — إدارة رأس المال البشري · مجموعة وادي الأثر المالية</div></td><td align="left">'.$logo.'</td></tr></table></td></tr>'
            .'<tr><td style="padding:22px 24px">'.($this->kicker ? '<p style="margin:0 0 6px;color:#6B7094;font-size:13px">'.e($this->kicker).'</p>' : '')
            .'<p style="margin:0 0 14px;font-size:17px;font-weight:700">'.e($this->heading).'</p>'
            .'<div style="white-space:pre-wrap;line-height:1.9;font-size:14.5px;background:#F6F7FB;border-radius:12px;padding:14px">'.e($this->body).'</div>'
            .'<p style="text-align:center;margin:20px 0 0"><a href="'.e($url).'" style="display:inline-block;background:'.e($this->color).';color:#fff;text-decoration:none;padding:11px 22px;border-radius:12px;font-weight:700">فتح في المنصة</a></p></td></tr>'
            .'<tr><td style="padding:12px 24px;border-top:1px solid #E8EAF3;font-size:12px;color:#6B7094">رسالة تلقائية من منصة HC — لا يُرد عليها بالبريد؛ التفاعل من داخل المنصة.</td></tr></table></td></tr></table></body></html>');
    }
}
