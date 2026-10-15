<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{{ $reset ? 'إعادة تعيين كلمة المرور' : 'دعوة للانضمام' }} — HC</title></head>
<body style="margin:0;background:#F6F7FB;font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#262E6B;direction:rtl">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F7FB;padding:32px 12px">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #E8EAF3;border-radius:16px;overflow:hidden">
        <tr><td style="background:linear-gradient(135deg,#262E6B,#1C84C6);background-color:#262E6B;padding:26px 28px;color:#fff">
          <div style="font-size:22px;font-weight:700">HC</div>
          <div style="font-size:13px;opacity:.85;margin-top:2px">إدارة رأس المال البشري — مجموعة وادي الأثر المالية</div>
        </td></tr>
        <tr><td style="padding:28px 28px 8px">
          <p style="font-size:17px;font-weight:700;margin:0 0 10px">{{ $reset ? 'إعادة تعيين كلمة المرور' : 'مرحبًا بك في منصة HC' }}</p>
          <p style="font-size:15px;line-height:1.9;margin:0 0 6px">عزيزي/عزيزتي <b>{{ $user->name }}</b>،</p>
          @if($reset)
            <p style="font-size:15px;line-height:1.9;margin:0 0 18px">وصلنا طلب لإعادة تعيين كلمة مرور حسابك. اضغط الزر أدناه لاختيار كلمة مرور جديدة. إن لم تطلب ذلك فتجاهل هذه الرسالة وسيبقى حسابك كما هو.</p>
          @else
            <p style="font-size:15px;line-height:1.9;margin:0 0 18px">
              أُنشئ لك حساب على منصة HC{{ $company ? ' في '.$company : '' }}{{ $job ? ' بصفتك '.$job : '' }}{{ $invitedBy ? ' بواسطة '.$invitedBy : '' }}.
              من خلال المنصة تسجّل حضورك، وتقدّم طلباتك، وتتابع إجازاتك وقسائم راتبك ووثائقك.
              اضغط الزر أدناه لتفعيل حسابك واختيار كلمة المرور.
            </p>
          @endif
          <p style="text-align:center;margin:8px 0 18px">
            <a href="{{ $url }}" style="display:inline-block;background:#1C84C6;color:#fff;text-decoration:none;padding:14px 30px;border-radius:12px;font-weight:700;font-size:16px">{{ $reset ? 'اختيار كلمة مرور جديدة' : 'تفعيل حسابي' }}</a>
          </p>
          <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#F6F7FB;border-radius:12px;padding:12px 16px;font-size:14px">
            <tr><td style="padding:4px 0;color:#6B7094;width:140px">اسم المستخدم</td><td style="padding:4px 0;font-weight:700;direction:ltr;text-align:left">{{ $user->login }}</td></tr>
            @if($user->email)<tr><td style="padding:4px 0;color:#6B7094">البريد</td><td style="padding:4px 0;direction:ltr;text-align:left">{{ $user->email }}</td></tr>@endif
            <tr><td style="padding:4px 0;color:#6B7094">صلاحية الرابط</td><td style="padding:4px 0">{{ $hours }} ساعة، ويُستخدم مرة واحدة</td></tr>
          </table>
          <p style="font-size:12px;color:#6B7094;line-height:1.8;margin:14px 0 0">إن لم يعمل الزر، انسخ هذا الرابط إلى المتصفح:<br><span style="direction:ltr;display:inline-block;word-break:break-all;color:#1C84C6">{{ $url }}</span></p>
        </td></tr>
        <tr><td style="padding:16px 28px 24px">
          <p style="font-size:13px;color:#6B7094;line-height:1.8;margin:0">بعد التفعيل يمكنك الدخول من <a href="{{ $site }}/app/login" style="color:#1C84C6">{{ $site }}/app/login</a> أو عبر تطبيق الجوال (Android): <a href="{{ $apk }}" style="color:#1C84C6">تحميل التطبيق</a>.</p>
        </td></tr>
        <tr><td style="padding:14px 28px;border-top:1px solid #E8EAF3;font-size:12px;color:#6B7094">رسالة تلقائية من منصة HC. للاستفسار تواصل مع إدارة الموارد البشرية.</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
