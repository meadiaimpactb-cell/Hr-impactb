<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>HC — تهيئة النظام</title>
<style>
  body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:#F6F7FB;color:#262E6B;margin:0;padding:24px}
  .box{max-width:720px;margin:0 auto;background:#fff;border:1px solid #E8EAF3;border-radius:16px;padding:24px}
  h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:20px 0 8px}
  .muted{color:#6B7094;font-size:13px}
  table{width:100%;border-collapse:collapse;font-size:14px}td{padding:6px 4px;border-bottom:1px solid #E8EAF3}
  .ok{color:#1F9D6B;font-weight:700}.bad{color:#D6455D;font-weight:700}.warn{color:#E0962B;font-weight:700}
  button{background:#1C84C6;color:#fff;border:0;border-radius:12px;padding:14px 22px;font-size:15px;font-weight:700;cursor:pointer;width:100%;margin-top:8px}
  button.sec{background:#fff;color:#1C84C6;border:1.5px solid #1C84C6}
  pre{background:#12163A;color:#fff;padding:14px;border-radius:12px;font-size:12px;white-space:pre-wrap;direction:ltr;text-align:left}
  .pass{font-size:26px;letter-spacing:2px;font-family:ui-monospace,Consolas,monospace;background:#E8EAF3;padding:12px;border-radius:12px;text-align:center;direction:ltr}
  .note{background:#FFF6E5;border:1px solid #F3D9A4;border-radius:12px;padding:12px;font-size:13px;margin-top:12px}
</style>
</head>
<body>
<div class="box">
  <h1>تهيئة نظام HC</h1>
  <div class="muted">{{ $state['url'] }} · البيئة {{ $state['env'] }} · PHP {{ $state['php'] }}</div>

  @if($result)
    <h2>{{ $result['ok'] ? 'اكتملت التهيئة' : 'فشلت التهيئة' }}</h2>
    <pre>{{ implode("\n", $result['log']) }}</pre>
    @if($result['password'])
      <div class="note">حسابات القيادة — كلمات المرور الابتدائية المتفق عليها، وسيُطلب تغييرها عند أول دخول:</div>
      <table>
        @foreach($result['password'] as $acc)
          <tr><td>{{ $acc['name'] }}<br><span class="muted">{{ $acc['role'] }}</span></td><td><b>{{ $acc['login'] }}</b></td><td class="pass" style="font-size:18px">{{ $acc['password'] }}</td></tr>
        @endforeach
      </table>
    @endif
    @if($result['ok'])
      <div class="note">مهم: افتح ملف <b>.env</b> واحذف قيمة <b>SETUP_TOKEN</b> الآن لتعطيل هذه الصفحة.</div>
      <a href="/app/login"><button type="button">فتح بوابة HC</button></a>
    @endif
  @endif

  <h2>فحص البيئة</h2>
  <table>
    <tr><td>إصدار PHP</td><td class="{{ $state['php_ok'] ? 'ok' : 'bad' }}">{{ $state['php'] }} {{ $state['php_ok'] ? '' : '(المطلوب 8.3 أو أحدث — غيّره من hPanel → PHP Configuration)' }}</td></tr>
    <tr><td>الاتصال بقاعدة البيانات</td><td class="{{ $state['db'] === true ? 'ok' : 'bad' }}">{{ $state['db'] === true ? 'متصل' : $state['db'] }}</td></tr>
    <tr><td>APP_KEY</td><td class="{{ $state['key'] ? 'ok' : 'bad' }}">{{ $state['key'] ? 'موجود' : 'مفقود' }}</td></tr>
    <tr><td>APP_DEBUG</td><td class="{{ $state['debug'] ? 'warn' : 'ok' }}">{{ $state['debug'] ? 'مفعّل — عطّله في الإنتاج' : 'معطّل' }}</td></tr>
    @foreach($state['writable'] as $d => $w)
      <tr><td>صلاحية الكتابة: {{ $d }}</td><td class="{{ $w ? 'ok' : 'bad' }}">{{ $w ? 'نعم' : 'لا' }}</td></tr>
    @endforeach
    @foreach($state['ext'] as $x => $on)
      <tr><td>إضافة PHP: {{ $x }}</td><td class="{{ $on ? 'ok' : ($x === 'gd' || $x === 'zip' ? 'warn' : 'bad') }}">{{ $on ? 'مثبتة' : 'غير مثبتة' }}</td></tr>
    @endforeach
    <tr><td>الحالة</td><td>{{ $state['installed'] ? 'مُثبَّت — '.$state['users'].' مستخدم' : 'لم يُثبَّت بعد' }}</td></tr>
  </table>

  @if(!$result || !$result['ok'])
    <h2>{{ $state['installed'] ? 'تحديث' : 'تثبيت' }}</h2>
    <form method="post" action="/setup/{{ $token }}">
      @csrf
      <p class="muted">{{ $state['installed'] ? 'يشغّل ترحيلات قاعدة البيانات الجديدة ويحدّث البيانات المرجعية دون المساس بالبيانات.' : 'ينشئ الجداول والبيانات المرجعية (الباقات، أنواع الإجازات، أنواع الطلبات، الإعدادات) وحساب المدير الأول.' }}</p>
      <button type="submit" {{ $state['db'] === true && $state['php_ok'] ? '' : 'disabled' }}>{{ $state['installed'] ? 'تشغيل التحديث' : 'تثبيت نسخة الإنتاج' }}</button>
      @if(!$state['installed'])
        <button type="submit" name="demo" value="1" class="sec" {{ $state['db'] === true && $state['php_ok'] ? '' : 'disabled' }}>تثبيت مع البيانات التجريبية (شركات وموظفون وهميون، كلمة المرور hc2026)</button>
      @endif
    </form>
  @endif
</div>
</body>
</html>
