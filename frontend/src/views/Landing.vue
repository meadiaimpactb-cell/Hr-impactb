<template>
  <div class="lp" :class="{ scrolled }">
    <!-- ============================================================ nav -->
    <header class="nav">
      <div class="wrap nav-in">
        <a href="/" class="brand"><img src="/logo-full.png" alt="وادي الأثر المالية — Impact Valley Capital" /></a>
        <nav class="links">
          <a href="#features">المزايا</a>
          <a href="#app">التطبيق</a>
          <a href="#security">الأمان والتكامل</a>
          <a href="#download">التحميل</a>
        </nav>
        <div class="nav-cta">
          <a href="/app/login" class="btn ghost sm">دخول البوابة</a>
          <a href="#download" class="btn pri sm">تحميل التطبيق</a>
        </div>
      </div>
    </header>

    <!-- ============================================================ hero -->
    <section class="hero">
      <div class="glow g1"></div><div class="glow g2"></div>
      <svg class="shapes" viewBox="0 0 600 420" aria-hidden="true">
        <g transform="rotate(-40 300 210)" opacity=".16">
          <rect x="120" y="90" width="90" height="300" rx="26" fill="#8A7AB6" />
          <rect x="255" y="60" width="90" height="300" rx="26" fill="#728CC6" />
          <rect x="390" y="30" width="90" height="300" rx="26" fill="#4FC9F0" />
        </g>
      </svg>
      <div class="wrap hero-in">
        <div class="hero-copy">
          <span class="eyebrow"><i class="dot"></i> منصة الموارد البشرية الرسمية للمجموعة</span>
          <h1>رأس المال البشري هو من يصنع مستقبل الأثر<br /><span class="grad">إدارتهم في تطبيق واحد</span></h1>
          <p class="lead">HC يجمع الحضور بالبصمة والموقع، الطلبات والاعتمادات، الرواتب والتقييم والوثائق لكل شركات مجموعة وادي الأثر المالية، بواجهة عربية أنيقة على الجوال والويب.</p>
          <p class="lead lead-2">كل ما يتعلق برفع كفاءة العمل وإدارة رأس المال البشري، نحن نُعينك على إنجاز أكبر بأثر أعظم.</p>
          <div class="cta-row">
            <a href="/downloads/HC-mobile.apk" class="btn pri lg" download>
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12" /><path d="m7 10 5 5 5-5" /><path d="M5 21h14" /></svg>
              تحميل نسخة Android
            </a>
            <a href="/app/login" class="btn ghost lg">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="14" rx="3" /><path d="M8 21h8" /></svg>
              بوابة الويب
            </a>
          </div>
          <div class="hero-meta">
            <span><b>iOS</b> قريبًا عبر App Store</span>
            <span class="sep"></span>
            <span>Android 8 وأحدث</span>
            <span class="sep"></span>
            <span>عربي / English</span>
          </div>
        </div>

        <!-- phone mockup: today's home screen -->
        <div class="hero-phone">
          <div class="phone">
            <div class="notch"></div>
            <div class="screen">
              <div class="p-head">
                <div class="p-av">أ</div>
                <div class="p-head-txt"><div class="p-hi">صباح الخير، أحمد</div><div class="p-sub">الاثنين 28 سبتمبر · أخصائي تسويق</div></div>
                <img class="p-logo" src="/logo-mark.png" alt="" />
              </div>
              <div class="p-card ring-card">
                <div class="p-label">حضور اليوم</div>
                <div class="ring">
                  <svg viewBox="0 0 120 120"><defs><linearGradient id="ringg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#728CC6" /><stop offset=".55" stop-color="#1C84C6" /><stop offset="1" stop-color="#4FC9F0" /></linearGradient></defs><circle cx="60" cy="60" r="52" class="track" /><circle cx="60" cy="60" r="52" class="prog" /></svg>
                  <div class="ring-in">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round"><path d="M12 11c0-2 1.5-3.5 3.5-3.5S19 9 19 11v3c0 3.5-2 6-5 7.5" /><path d="M8 13c0-4 2-7 6-7" /><path d="M5 16c0-6 3-10 8-10" /><path d="M12 14v2c0 2.5-1.5 4.5-3.5 5.5" /></svg>
                    <span>تسجيل انصراف</span>
                  </div>
                </div>
                <div class="p-strong">سجّلت حضورك 08:34 ص</div>
                <div class="p-sub c">اضغط الدائرة ثم أكّد ببصمتك</div>
              </div>
              <div class="p-row">
                <div class="p-card sm"><div class="p-label">رصيد الإجازة</div><div class="p-num">18</div><div class="p-sub">يوم متاح</div></div>
                <div class="p-card sm"><div class="p-label">طلبات معلّقة</div><div class="p-num w">2</div><div class="p-sub">بانتظار الاعتماد</div></div>
              </div>
              <div class="p-card bar-card">
                <div class="p-between"><span class="p-strong">هذا الشهر</span><span class="p-sub">19/20 يوم</span></div>
                <div class="p-bar"><i style="width:68%" class="ok"></i><i style="width:22%" class="warn"></i><i style="width:5%" class="bad"></i></div>
              </div>
              <div class="p-nav">
                <span class="on">الرئيسية</span><span>حضوري</span><span>طلباتي</span><span>الإشعارات</span><span>المزيد</span>
              </div>
            </div>
          </div>
          <div class="float f1"><i class="ok"></i>تم اعتماد طلب الإجازة</div>
          <div class="float f2"><i class="info"></i>تذكير: التقرير الشهري خلال 3 أيام</div>
        </div>
      </div>
    </section>

    <!-- ============================================================ trust strip -->
    <section class="strip">
      <div class="wrap strip-in">
        <div><b>7</b><span>شركات تابعة على منصة واحدة</span></div>
        <div><b>20+</b><span>وحدة عمل من الحضور إلى التصفية</span></div>
        <div><b>8</b><span>أدوار وصلاحيات مفصّلة</span></div>
        <div><b>2</b><span>لغتان: العربية والإنجليزية</span></div>
      </div>
    </section>

    <!-- ============================================================ features -->
    <section id="features" class="sec">
      <div class="wrap">
        <div class="sec-head">
          <span class="kicker">المزايا</span>
          <h2>كل ما يحتاجه الموظف والمدير والموارد البشرية</h2>
          <p>صُمِّم HC حول يوم العمل الفعلي: من بصمة الصباح حتى اعتماد الطلب وصرف الراتب.</p>
        </div>
        <div class="grid3">
          <article v-for="f in features" :key="f.t" class="feat">
            <div class="ico" :style="{ '--c': f.c }" v-html="f.i"></div>
            <h3>{{ f.t }}</h3>
            <p>{{ f.d }}</p>
          </article>
        </div>
      </div>
    </section>

    <!-- ============================================================ app screens -->
    <section id="app" class="sec alt">
      <div class="wrap">
        <div class="sec-head">
          <span class="kicker">تطبيق الموظف</span>
          <h2>تجربة جوال مصمّمة للاستخدام اليومي</h2>
          <p>خمس شاشات فقط تغطي 90% من احتياج الموظف، والباقي على بُعد نقرة في «المزيد».</p>
        </div>
        <div class="screens">
          <div class="mini">
            <div class="mini-screen">
              <div class="m-title">سبتمبر 2026 <small>سجل الحضور</small></div>
              <div class="m-metrics"><div><b class="ok">19</b><span>حاضر</span></div><div><b class="warn">6</b><span>متأخر</span></div><div><b class="bad">1</b><span>غياب</span></div></div>
              <div class="m-cal"><i v-for="n in 28" :key="n" :class="calClass(n)">{{ n }}</i></div>
              <div class="m-rec"><b>28</b><div><span>08:34 ص → 05:02 م</span><small>المقر الرئيسي · الرياض</small></div><em class="ok">حاضر</em></div>
              <div class="m-rec"><b>27</b><div><span>08:52 ص → 05:10 م</span><small>المقر الرئيسي · الرياض</small></div><em class="warn">متأخر 12 د</em></div>
            </div>
            <h4>حضوري</h4><p>تقويم الشهر بحالة كل يوم، وقت الدخول والخروج، والموقع الذي سُجّلت منه البصمة.</p>
          </div>
          <div class="mini">
            <div class="mini-screen">
              <div class="m-title">طلباتي <em class="pill">2 معلّق</em></div>
              <div class="m-tabs"><span class="on">الكل</span><span>معلّق</span><span>معتمد</span><span>مغلق</span></div>
              <div class="m-req"><i class="ic"></i><div><span>إجازة سنوية</span><small>سنوية · 4 أيام · 5 → 8 أكتوبر</small></div><em class="warn">بانتظار الاعتماد</em></div>
              <div class="m-req"><i class="ic"></i><div><span>تصحيح حضور</span><small>نسيان تسجيل الانصراف · 25 سبتمبر</small></div><em class="ok">معتمد</em></div>
              <div class="m-req"><i class="ic"></i><div><span>سلفة</span><small>3,000 ر.س على 3 أقساط</small></div><em class="ok">معتمد</em></div>
              <div class="m-fab">+</div>
            </div>
            <h4>طلباتي</h4><p>إجازة، تصحيح حضور، سلفة، خطاب، رحلة عمل. تتبّع مرحلة الاعتماد لحظة بلحظة.</p>
          </div>
          <div class="mini">
            <div class="mini-screen">
              <div class="m-title">الإشعارات <small>تعليم الكل كمقروء</small></div>
              <div class="m-day">اليوم</div>
              <div class="m-ntf unread"><i class="ic ok"></i><div><span>اعتُمد طلب الإجازة</span><small>وافق ماجد المطيري على إجازتك السنوية</small></div><b></b></div>
              <div class="m-ntf unread"><i class="ic info"></i><div><span>إعلان جديد</span><small>تحديث سياسة العمل عن بُعد</small></div><b></b></div>
              <div class="m-day">أمس</div>
              <div class="m-ntf"><i class="ic warn"></i><div><span>تذكير التقرير الشهري</span><small>الموعد النهائي 5 أكتوبر</small></div></div>
              <div class="m-ntf"><i class="ic ok"></i><div><span>نُشرت قسيمة راتب سبتمبر</span><small>اطّلع على التفاصيل</small></div></div>
            </div>
            <h4>الإشعارات</h4><p>إشعارات فورية على الجوال لكل اعتماد أو تذكير أو إعلان يخصّك.</p>
          </div>
        </div>
        <ul class="checks">
          <li v-for="c in checks" :key="c"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7" /></svg>{{ c }}</li>
        </ul>
      </div>
    </section>

    <!-- ============================================================ security -->
    <section id="security" class="sec">
      <div class="wrap two">
        <div>
          <span class="kicker">الأمان والتكامل</span>
          <h2>بُني ليخدم مجموعة مالية</h2>
          <p class="lead2">كل شيء في HC بيانات قابلة للضبط: السياسات، مسارات الاعتماد، أنواع الطلبات، الباقات. لا شيء مثبّت في الكود.</p>
          <div class="sec-list">
            <div v-for="s in security" :key="s.t"><div class="ico sm" :style="{ '--c': s.c }" v-html="s.i"></div><div><h3>{{ s.t }}</h3><p>{{ s.d }}</p></div></div>
          </div>
        </div>
        <div class="stack">
          <div class="stack-card">
            <div class="sc-head"><span>مسار اعتماد: إجازة سنوية</span><em>ديناميكي</em></div>
            <div class="flow"><i class="done">الموظف</i><b></b><i class="done">المدير المباشر</i><b></b><i class="cur">الموارد البشرية</i><b></b><i>رئيس الشركة</i></div>
          </div>
          <div class="stack-card">
            <div class="sc-head"><span>سياسة الحضور</span><em>قابلة للتعديل</em></div>
            <div class="kv"><span>فترة السماح</span><b>10 دقائق</b></div>
            <div class="kv"><span>نطاق الموقع</span><b>150 – 300 م + هامش لكل موظف</b></div>
            <div class="kv"><span>تأكيد الهوية</span><b>بصمة الجهاز + صورة اختيارية</b></div>
          </div>
          <div class="stack-card odoo">
            <div class="sc-head"><span>التكامل</span><em>Odoo-ready</em></div>
            <p>الشركات، الموظفون، الرواتب والسلف تُصدَّر تلقائيًا إلى Odoo عبر صندوق صادر مُجدوَل، مع تعيين حقول قابل للتعديل من لوحة الإعدادات.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================================ download -->
    <section id="download" class="dl">
      <div class="wrap dl-in">
        <div class="dl-copy">
          <span class="kicker light">ابدأ الآن</span>
          <h2>حمّل تطبيق HC على جوالك</h2>
          <p>نسخة Android متاحة الآن للتنزيل المباشر. يتطلب التطبيق حسابًا صادرًا من إدارة الموارد البشرية في المجموعة.</p>
          <div class="cta-row">
            <a href="/downloads/HC-mobile.apk" class="btn white lg" download>
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12" /><path d="m7 10 5 5 5-5" /><path d="M5 21h14" /></svg>
              تحميل APK للأندرويد
            </a>
            <span class="btn soon lg">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="3" /><path d="M11 18h2" /></svg>
              iOS — قريبًا
            </span>
          </div>
          <ol class="steps">
            <li><b>1</b> حمّل الملف وافتحه، ثم اسمح بالتثبيت من هذا المصدر إذا طُلب منك.</li>
            <li><b>2</b> سجّل الدخول باسم المستخدم أو رقمك الوظيفي.</li>
            <li><b>3</b> فعّل الموقع والبصمة والإشعارات عند أول تشغيل.</li>
          </ol>
        </div>
        <div class="dl-art">
          <div class="qr-card">
            <div class="qr-title">امسح للتحميل</div>
            <svg v-if="qr.size" class="qr" :viewBox="`0 0 ${qr.size} ${qr.size}`" shape-rendering="crispEdges" aria-label="QR رابط التحميل">
              <rect width="100%" height="100%" fill="#fff" />
              <path :d="qr.path" fill="#262E6B" />
            </svg>
            <div class="qr-url">{{ host }}/downloads/HC-mobile.apk</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================================ footer -->
    <footer class="foot">
      <div class="wrap foot-in">
        <a href="/" class="brand foot-brand"><img src="/logo-full.png" alt="وادي الأثر المالية — Impact Valley Capital" /></a>
        <nav>
          <a href="/app/login">بوابة الويب</a>
          <a href="#features">المزايا</a>
          <a href="#download">التحميل</a>
          <a href="https://impactv.sa" target="_blank" rel="noopener">impactv.sa</a>
        </nav>
        <div class="copy">© {{ year }} مجموعة وادي الأثر المالية — Impact Valley Capital. جميع الحقوق محفوظة.</div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { qrPath } from '../qr'

const scrolled = ref(false)
const onScroll = () => { scrolled.value = window.scrollY > 12 }
onMounted(() => { onScroll(); window.addEventListener('scroll', onScroll, { passive: true }) })
onUnmounted(() => window.removeEventListener('scroll', onScroll))

const year = new Date().getFullYear()
const host = typeof location !== 'undefined' && location.host ? location.host : 'hc.impactv.sa'
const qr = computed(() => qrPath(`https://${host}/downloads/HC-mobile.apk`))

const calClass = (n) => (n > 25 ? 'today' : [3, 4, 10, 11, 17, 18, 24, 25].includes(n) ? 'off' : [8, 15, 22].includes(n) ? 'warn' : n === 12 ? 'bad' : 'ok')

const svg = (d) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`
const features = [
  { c: '#1C84C6', t: 'حضور بالبصمة والموقع', d: 'تسجيل الحضور من الجوال بتأكيد بصمة الجهاز وتحقّق من نطاق الموقع، مع هامش يحدده قسم الموارد البشرية لكل موظف.', i: svg('<path d="M12 11c0-2 1.5-3.5 3.5-3.5S19 9 19 11v3c0 3.5-2 6-5 7.5"/><path d="M8 13c0-4 2-7 6-7"/><path d="M5 16c0-6 3-10 8-10"/><path d="M12 14v2c0 2.5-1.5 4.5-3.5 5.5"/>') },
  { c: '#728CC6', t: 'طلبات واعتمادات ديناميكية', d: 'إجازات، سلف، تصحيح حضور، خطابات، رحلات، استقالة، وأي نوع تضيفه بنموذج ومسار اعتماد خاص به.', i: svg('<path d="M9 5h6a2 2 0 0 1 2 2v13H7V7a2 2 0 0 1 2-2Z"/><path d="M9 3h6v4H9z"/><path d="m9.5 13 2 2 3.5-4"/>') },
  { c: '#1F9D6B', t: 'الرواتب والقسائم', d: 'مسيّرات شهرية بالبدلات والاستقطاعات وأقساط السلف، قسائم منشورة للموظف، وتصفية نهاية الخدمة.', i: svg('<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M7 12h.01M17 12h.01"/>') },
  { c: '#8A7AB6', t: 'التقييم والتقارير', d: 'تقارير شهرية وأسبوعية، تقييم بأوزان قابلة للضبط، اعتراضات ضمن مهلة، ومعدل سنوي تلقائي.', i: svg('<path d="M4 19V5"/><path d="M4 19h16"/><path d="m7 14 4-4 3 3 5-6"/>') },
  { c: '#E0962B', t: 'الوثائق والعهد والتأمين', d: 'تنبيهات انتهاء الوثائق، إقرارات استلام العهد، بطاقات التأمين الطبي، وأرشيف الخطابات الموقّعة.', i: svg('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>') },
  { c: '#4FC9F0', t: 'إشعارات فورية وبريد داخلي', d: 'تنبيه فوري على الجوال لكل اعتماد أو تذكير، إعلانات تتطلب إقرارًا، وبريد داخلي بين الفرق.', i: svg('<path d="M6 8a6 6 0 0 1 12 0v5l2 3H4l2-3z"/><path d="M10 20a2 2 0 0 0 4 0"/>') },
]
const checks = ['بصمة الجهاز وقفل التطبيق', 'وضع داكن كامل', 'العمل بالعربية والإنجليزية', 'صورة ذاتية اختيارية عند البصمة', 'إشعارات Firebase الفورية', 'يعمل على Android 8 وأحدث']
const security = [
  { c: '#262E6B', t: 'أدوار وصلاحيات دقيقة', d: 'الرئيس التنفيذي للمجموعة، الموارد البشرية، رؤساء الشركات، المدراء، المالية، مسؤولو العهد، والموظفون، مع صلاحيات على مستوى العملية والشركة.', i: svg('<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>') },
  { c: '#1C84C6', t: 'تشفير وسجل تدقيق', d: 'المفاتيح والأسرار مشفّرة في قاعدة البيانات، وكل تغيير في السياسات أو البيانات الحساسة يُسجَّل بمن قام به ومتى.', i: svg('<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>') },
  { c: '#8A7AB6', t: 'جاهز للربط مع Odoo', d: 'صندوق صادر مُجدوَل وتعيين حقول قابل للتعديل من الواجهة، بدون تعديل في الكود.', i: svg('<path d="M4 12h6M14 12h6"/><circle cx="12" cy="12" r="3"/><path d="M12 3v6M12 15v6"/>') },
]
</script>

<style scoped>
/* -------------------------------------------------------------- tokens */
.lp {
  --navy: #262E6B; --purple: #8A7AB6; --peri: #728CC6; --blue: #1C84C6; --cyan: #4FC9F0;
  --ok: #1F9D6B; --warn: #E0962B; --bad: #D6455D;
  --bg: #F6F7FB; --card: #fff; --line: #E8EAF3; --muted: #6B7094; --text: #262E6B;
  font-family: 'IBM Plex Sans Arabic', system-ui, -apple-system, 'Segoe UI', sans-serif;
  color: var(--text); background: var(--bg); line-height: 1.6;
}
:global(html), :global(body) { margin: 0; scroll-behavior: smooth; }
.sec, .dl, .strip { scroll-margin-top: 96px; }
.wrap { max-width: 1160px; margin: 0 auto; padding: 0 24px; }
h1, h2, h3, h4 { margin: 0; font-weight: 700; letter-spacing: -.01em; }
p { margin: 0; }
a { color: inherit; text-decoration: none; }
.kicker { display: inline-block; font-size: 13px; font-weight: 700; color: var(--blue); letter-spacing: .06em; margin-bottom: 10px; }
.kicker.light { color: var(--cyan); }
.ok { color: var(--ok); } .warn { color: var(--warn); } .bad { color: var(--bad); }

/* -------------------------------------------------------------- buttons */
.btn { display: inline-flex; align-items: center; gap: 10px; border-radius: 14px; font-weight: 700; transition: transform .15s, box-shadow .15s, background .15s; white-space: nowrap; }
.btn:hover { transform: translateY(-1px); }
.btn.sm { padding: 10px 16px; font-size: 14px; border-radius: 12px; }
.btn.lg { padding: 15px 24px; font-size: 16px; }
.btn.pri { background: var(--blue); color: #fff; box-shadow: 0 8px 24px rgba(28, 132, 198, .35); }
.btn.pri:hover { background: #1873AE; }
.btn.ghost { border: 1.5px solid rgba(38, 46, 107, .2); color: var(--navy); background: rgba(255, 255, 255, .6); }
.btn.white { background: #fff; color: var(--navy); box-shadow: 0 10px 30px rgba(0, 0, 0, .25); }
.btn.soon { border: 1.5px solid rgba(255, 255, 255, .35); color: rgba(255, 255, 255, .85); cursor: default; }
.btn.soon:hover { transform: none; }

/* -------------------------------------------------------------- nav */
.nav { position: fixed; top: 0; left: 0; right: 0; z-index: 50; backdrop-filter: blur(14px); background: rgba(246, 247, 251, .75); border-bottom: 1px solid transparent; transition: border-color .2s, background .2s; }
.scrolled .nav { border-color: var(--line); background: rgba(255, 255, 255, .85); }
.nav-in { display: flex; align-items: center; justify-content: space-between; height: 88px; gap: 24px; }
.brand { display: flex; align-items: center; }
.brand img { height: 68px; width: auto; object-fit: contain; display: block; }
.foot-brand img { height: 104px; }
.links { display: flex; gap: 26px; font-size: 14px; font-weight: 600; color: var(--muted); }
.links a:hover { color: var(--navy); }
.nav-cta { display: flex; gap: 10px; }

/* -------------------------------------------------------------- hero */
.hero { position: relative; padding: 148px 0 40px; overflow: hidden; }
.glow { position: absolute; border-radius: 50%; filter: blur(90px); opacity: .5; pointer-events: none; }
.g1 { width: 520px; height: 520px; background: #cfe6fb; top: -160px; right: -120px; }
.g2 { width: 420px; height: 420px; background: #e3ddf5; bottom: -140px; left: -100px; }
.shapes { position: absolute; width: 600px; left: -120px; top: 20px; pointer-events: none; }
.hero-in { position: relative; display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 40px; align-items: center; }
.eyebrow { display: inline-flex; align-items: center; gap: 8px; background: #fff; border: 1px solid var(--line); border-radius: 999px; padding: 6px 14px; font-size: 13px; font-weight: 600; color: var(--muted); }
.eyebrow .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 4px rgba(31, 157, 107, .18); }
h1 { font-size: clamp(26px, 3.2vw, 40px); line-height: 1.45; margin: 22px 0 16px; font-weight: 700; }
.grad { background: linear-gradient(90deg, var(--blue), var(--cyan)); -webkit-background-clip: text; background-clip: text; color: transparent; }
.lead { font-size: 16px; line-height: 1.8; color: var(--muted); max-width: 560px; }
.lead-2 { margin-top: 12px; color: var(--navy); font-weight: 500; }
.cta-row { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
.hero-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 22px; font-size: 13px; color: var(--muted); }
.hero-meta .sep { width: 4px; height: 4px; border-radius: 50%; background: var(--line); }

/* phone */
.hero-phone { position: relative; display: flex; justify-content: center; }
.phone { width: 300px; height: 620px; border-radius: 44px; background: linear-gradient(160deg, #2E3780, var(--navy) 60%, #1B2150); padding: 12px; box-shadow: 0 40px 80px rgba(38, 46, 107, .38), inset 0 0 0 2px rgba(114, 140, 198, .55); position: relative; }
.notch { position: absolute; top: 22px; left: 50%; transform: translateX(-50%); width: 110px; height: 26px; background: var(--navy); border-radius: 999px; z-index: 2; }
.screen { height: 100%; border-radius: 34px; background: linear-gradient(180deg, #EEF2FB, var(--bg) 40%); overflow: hidden; padding: 52px 14px 0; display: flex; flex-direction: column; gap: 10px; position: relative; }
.p-head { display: flex; align-items: center; gap: 10px; }
.p-head-txt { flex: 1; min-width: 0; }
.p-logo { height: 22px; width: auto; opacity: .95; }
.p-av { width: 40px; height: 40px; border-radius: 50%; background: var(--purple); color: #fff; display: grid; place-items: center; font-weight: 700; }
.p-hi { font-weight: 700; font-size: 15px; }
.p-sub { font-size: 11px; color: var(--muted); }
.p-sub.c { text-align: center; }
.p-card { background: #fff; border: 1px solid rgba(114, 140, 198, .28); border-radius: 16px; padding: 12px; }
.p-card.sm { flex: 1; }
.p-row { display: flex; gap: 10px; }
.p-label { font-size: 10px; font-weight: 700; letter-spacing: .05em; color: var(--muted); }
.p-num { font-size: 24px; font-weight: 700; line-height: 1.2; margin-top: 4px; }
.p-num.w { color: var(--purple); }
.p-strong { font-weight: 700; font-size: 13px; }
.p-between { display: flex; justify-content: space-between; align-items: center; }
.ring-card { text-align: center; }
.ring { position: relative; width: 150px; height: 150px; margin: 8px auto 6px; }
.ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.ring .track { fill: none; stroke: rgba(114, 140, 198, .22); stroke-width: 9; }
.ring .prog { fill: none; stroke: url(#ringg); stroke-width: 9; stroke-linecap: round; stroke-dasharray: 327; stroke-dashoffset: 327; animation: fill 1.6s .4s cubic-bezier(.2, .8, .2, 1) forwards; }
@keyframes fill { to { stroke-dashoffset: 118; } }
.ring-in svg { width: 26px; height: 26px; flex: none; }
.ring-in { position: absolute; inset: 18px; border-radius: 50%; background: linear-gradient(135deg, var(--navy) 10%, var(--blue) 90%); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; font-size: 12px; font-weight: 700; box-shadow: 0 10px 24px rgba(38, 46, 107, .35); }
.p-bar { display: flex; height: 8px; border-radius: 6px; overflow: hidden; background: rgba(114, 140, 198, .18); margin-top: 8px; }
.p-bar i { display: block; height: 100%; }
.p-bar .ok { background: var(--blue); } .p-bar .warn { background: var(--peri); } .p-bar .bad { background: var(--purple); }
.p-nav { margin-top: auto; display: flex; justify-content: space-around; padding: 12px 0 18px; border-top: 1px solid rgba(114, 140, 198, .28); background: #fff; margin-left: -14px; margin-right: -14px; font-size: 10px; color: var(--muted); font-weight: 600; }
.p-nav .on { color: var(--blue); }
.float { position: absolute; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 10px 14px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; box-shadow: 0 16px 40px rgba(38, 46, 107, .18); animation: floaty 5s ease-in-out infinite; }
.float i { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.float .ok { background: var(--blue); } .float .info { background: var(--purple); }
.f1 { top: 110px; right: -10px; }
.f2 { bottom: 120px; left: -30px; animation-delay: -2.5s; }
@keyframes floaty { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }

/* -------------------------------------------------------------- strip */
.strip { border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); background: #fff; }
.strip-in { display: grid; grid-template-columns: repeat(4, 1fr); padding: 26px 24px; }
.strip-in > div { display: flex; align-items: baseline; gap: 10px; padding: 6px 18px; border-inline-start: 1px solid var(--line); }
.strip-in > div:first-child { border: 0; }
.strip b { font-size: 24px; font-weight: 700; color: var(--blue); }
.strip span { font-size: 13px; color: var(--muted); }

/* -------------------------------------------------------------- sections */
.sec { padding: 88px 0; }
.sec.alt { background: #fff; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.sec-head { text-align: center; max-width: 640px; margin: 0 auto 48px; }
.sec-head h2 { font-size: clamp(22px, 2.6vw, 30px); line-height: 1.45; margin-bottom: 12px; }
.sec-head p, .lead2 { color: var(--muted); font-size: 16px; }
h2 { font-size: clamp(22px, 2.6vw, 30px); line-height: 1.45; }
.grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
.feat { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 26px; transition: transform .2s, box-shadow .2s; }
.feat:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(38, 46, 107, .10); }
.feat h3 { font-size: 17px; margin: 16px 0 8px; }
.feat p { color: var(--muted); font-size: 14px; }
.ico { width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center; background: color-mix(in srgb, var(--c) 12%, #fff); color: var(--c); }
.ico :deep(svg) { width: 24px; height: 24px; }
.ico.sm { width: 40px; height: 40px; border-radius: 12px; flex: none; }
.ico.sm :deep(svg) { width: 20px; height: 20px; }

/* app screens */
.screens { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 22px; }
.mini h4 { font-size: 17px; margin: 18px 0 6px; }
.mini p { color: var(--muted); font-size: 14px; }
.mini-screen { background: var(--bg); border: 1px solid var(--line); border-radius: 24px; padding: 18px 14px; height: 400px; overflow: hidden; position: relative; display: flex; flex-direction: column; gap: 8px; font-size: 12px; }
.m-title { font-weight: 700; font-size: 15px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.m-title small { font-size: 11px; color: var(--blue); font-weight: 600; }
.m-metrics { display: flex; gap: 8px; }
.m-metrics > div { flex: 1; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 8px; text-align: center; }
.m-metrics b { display: block; font-size: 18px; }
.m-metrics span { font-size: 10px; color: var(--muted); }
.m-cal { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 8px; }
.m-cal i { font-style: normal; text-align: center; font-size: 10px; height: 20px; line-height: 20px; border-radius: 50%; color: var(--muted); }
.m-cal .ok { background: rgba(31, 157, 107, .14); color: var(--ok); font-weight: 700; }
.m-cal .warn { background: rgba(224, 150, 43, .14); color: var(--warn); font-weight: 700; }
.m-cal .bad { background: rgba(214, 69, 93, .14); color: var(--bad); font-weight: 700; }
.m-cal .today { outline: 1.5px solid var(--blue); }
.m-rec, .m-req, .m-ntf { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 10px; }
.m-rec b { width: 34px; height: 36px; border-radius: 10px; background: rgba(28, 132, 198, .12); color: var(--blue); display: grid; place-items: center; font-size: 14px; }
.m-rec div, .m-req div, .m-ntf div { flex: 1; display: flex; flex-direction: column; }
.m-rec span, .m-req span, .m-ntf span { font-weight: 700; }
.m-rec small, .m-req small, .m-ntf small { color: var(--muted); font-size: 10px; }
.m-rec em, .m-req em { font-style: normal; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 999px; background: rgba(31, 157, 107, .12); }
.m-rec em.warn, .m-req em.warn { background: rgba(224, 150, 43, .12); }
.m-tabs { display: flex; background: #e8eaf3; border-radius: 10px; padding: 3px; }
.m-tabs span { flex: 1; text-align: center; padding: 5px; border-radius: 8px; font-size: 11px; color: var(--muted); font-weight: 600; }
.m-tabs .on { background: #fff; color: var(--navy); box-shadow: 0 2px 6px rgba(38, 46, 107, .12); }
.ic { width: 32px; height: 32px; border-radius: 9px; background: rgba(28, 132, 198, .12); flex: none; }
.ic.ok { background: rgba(31, 157, 107, .14); } .ic.warn { background: rgba(224, 150, 43, .14); } .ic.info { background: rgba(79, 201, 240, .18); }
.pill { font-style: normal; font-size: 10px; font-weight: 700; color: var(--warn); background: rgba(224, 150, 43, .12); padding: 3px 8px; border-radius: 999px; }
.m-fab { position: absolute; bottom: 16px; left: 16px; width: 46px; height: 46px; border-radius: 50%; background: var(--blue); color: #fff; display: grid; place-items: center; font-size: 26px; box-shadow: 0 10px 20px rgba(28, 132, 198, .35); }
.m-day { font-size: 10px; font-weight: 700; color: var(--muted); letter-spacing: .05em; padding: 4px 4px 0; }
.m-ntf b { width: 8px; height: 8px; border-radius: 50%; background: var(--cyan); }
.m-ntf.unread span { color: var(--navy); }
.checks { list-style: none; padding: 0; margin: 40px auto 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 24px; max-width: 900px; font-size: 14px; font-weight: 600; }
.checks li { display: flex; align-items: center; gap: 10px; }
.checks svg { color: var(--ok); flex: none; }

/* security */
.two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 56px; align-items: center; }
.sec-list { display: flex; flex-direction: column; gap: 22px; margin-top: 30px; }
.sec-list > div { display: flex; gap: 14px; }
.sec-list h3 { font-size: 16px; margin-bottom: 4px; }
.sec-list p { color: var(--muted); font-size: 14px; }
.stack { display: flex; flex-direction: column; gap: 14px; }
.stack-card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px 20px; box-shadow: 0 14px 34px rgba(38, 46, 107, .08); }
.stack-card.odoo { background: linear-gradient(135deg, var(--navy), #1c2a72); color: #fff; border: 0; }
.stack-card.odoo p { color: rgba(255, 255, 255, .8); font-size: 14px; margin-top: 8px; }
.sc-head { display: flex; justify-content: space-between; align-items: center; font-weight: 700; font-size: 14px; }
.sc-head em { font-style: normal; font-size: 11px; font-weight: 700; color: var(--blue); background: rgba(28, 132, 198, .12); padding: 3px 10px; border-radius: 999px; }
.odoo .sc-head em { color: var(--cyan); background: rgba(79, 201, 240, .18); }
.flow { display: flex; align-items: center; gap: 6px; margin-top: 14px; font-size: 12px; font-weight: 600; }
.flow i { font-style: normal; padding: 6px 10px; border-radius: 999px; background: var(--bg); border: 1px solid var(--line); color: var(--muted); white-space: nowrap; }
.flow i.done { background: rgba(31, 157, 107, .12); color: var(--ok); border-color: transparent; }
.flow i.cur { background: var(--blue); color: #fff; border-color: transparent; box-shadow: 0 6px 14px rgba(28, 132, 198, .35); }
.flow b { flex: 1; height: 2px; background: var(--line); min-width: 8px; }
.kv { display: flex; justify-content: space-between; padding: 9px 0; border-top: 1px solid var(--line); font-size: 13px; }
.kv:first-of-type { margin-top: 10px; }
.kv span { color: var(--muted); }

/* download */
.dl { background: radial-gradient(1200px 500px at 80% -10%, rgba(79, 201, 240, .25), transparent), linear-gradient(135deg, #262E6B, #1C84C6); color: #fff; padding: 88px 0; }
.dl-in { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, .8fr); gap: 48px; align-items: center; }
.dl h2 { color: #fff; margin-bottom: 12px; }
.dl-copy > p { color: rgba(255, 255, 255, .82); font-size: 16px; max-width: 520px; }
.steps { list-style: none; padding: 0; margin: 30px 0 0; display: flex; flex-direction: column; gap: 10px; font-size: 14px; color: rgba(255, 255, 255, .9); }
.steps li { display: flex; align-items: center; gap: 12px; }
.steps b { width: 26px; height: 26px; border-radius: 50%; background: rgba(255, 255, 255, .16); display: grid; place-items: center; font-size: 12px; flex: none; }
.dl-art { display: flex; justify-content: center; }
.qr-card { background: #fff; color: var(--navy); border-radius: 22px; padding: 22px; text-align: center; box-shadow: 0 30px 60px rgba(0, 0, 0, .3); width: 260px; }
.qr-title { font-weight: 700; margin-bottom: 12px; }
.qr { width: 200px; height: 200px; display: block; margin: 0 auto; border-radius: 8px; }
.qr-url { font-size: 11px; color: var(--muted); margin-top: 12px; direction: ltr; word-break: break-all; }

/* footer */
.foot { background: #fff; border-top: 1px solid var(--line); padding: 34px 0; }
.foot-in { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.foot nav { display: flex; gap: 22px; font-size: 14px; font-weight: 600; color: var(--muted); }
.foot nav a:hover { color: var(--navy); }
.copy { font-size: 12px; color: var(--muted); width: 100%; text-align: center; margin-top: 16px; }

/* -------------------------------------------------------------- responsive */
@media (max-width: 980px) {
  .links { display: none; }
  .hero-in, .two, .dl-in { grid-template-columns: minmax(0, 1fr); }
  .flow { flex-wrap: wrap; }
  .flow b { display: none; }
  .hero-phone { margin-top: 30px; }
  .f2 { left: 0; }
  .grid3, .screens { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .strip-in { grid-template-columns: 1fr 1fr; }
  .checks { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
  .nav-cta .ghost { display: none; }
  .hero { padding-top: 116px; }
  .grid3, .screens, .strip-in, .checks { grid-template-columns: minmax(0, 1fr); }
  .grid3, .screens { grid-template-columns: minmax(0, 1fr); }
  .strip-in > div { border: 0; padding: 6px 0; }
  .phone { width: 270px; height: 560px; }
  .f1 { right: -4px; }
  .sec { padding: 64px 0; }
  .btn.lg { width: 100%; justify-content: center; }
}
</style>
