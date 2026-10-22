<template>
  <div class="page-h">
    <div><h1>{{ t('لوحة المعلومات') }}</h1><p>{{ DAYS_AR[new Date().getDay()] }} {{ today() }} · {{ hijri }}</p></div>
    <div class="acts"><button class="btn pri sm" @click="newReq = true"><Icon name="plus" :size="16" /> {{ t('طلب جديد') }}</button></div>
  </div>
  <div v-if="!d"><div class="skel" v-for="i in 6" :key="i" /></div>
  <template v-else>
    <div v-if="auth.isApprover" class="grid g5 mb">
      <Kpi icon="users" :label="t('الموظفون النشطون')" :hint="`على رأس العمل الآن من ${d.kpis.employees} موظفًا`" color="n" to="/attendance">{{ d.kpis.active_now ?? 0 }}<span class="muted" style="font-size:14px">/{{ d.kpis.employees }}</span></Kpi>
      <Kpi icon="approvals" :label="t('بانتظار اعتمادي')" :value="d.kpis.inbox" :hint="d.kpis.inbox_late ? `${d.kpis.inbox_late} تجاوز ${auth.meta?.policy?.sla_days} أيام` : 'كلها في الوقت'" color="y" to="/approvals" />
      <Kpi icon="user-check" :label="t('الحاضرون اليوم')" :hint="`${d.kpis.on_leave_today} في إجازة`" color="g" to="/attendance">{{ d.kpis.present }}<span class="muted" style="font-size:14px">/{{ d.kpis.punchers }}</span></Kpi>
      <Kpi icon="fingerprint" :label="t('بصمات خارج النطاق')" :value="d.kpis.pending_attendance" hint="ظاهرة في الحضور، للمراجعة فقط" :color="d.kpis.pending_attendance ? 'r' : 'g'" to="/attendance" />
      <Kpi icon="bell" :label="t('مركز الإشعارات')" :value="d.unread" hint="غير مقروءة" color="p" to="/notifications" />
    </div>

    <div v-if="d.ops" class="grid g4 mb">
      <Kpi icon="report" label="تقارير شهرية بانتظار المراجعة" :value="d.ops.reports_waiting" :hint="`${d.ops.reports_missing} لم تُرفع`" :color="d.ops.reports_waiting ? 'y' : 'g'" to="/reports" />
      <Kpi icon="folder" label="وثائق تنتهي خلال 30 يومًا" :value="d.ops.docs_alert" :color="d.ops.docs_alert ? 'r' : 'g'" to="/documents" />
      <Kpi icon="gavel" label="مخالفات قيد الإجراء" :value="d.ops.violations_open" :hint="d.ops.terminations_open ? `${d.ops.terminations_open} حالات إنهاء خدمة قائمة` : ''" :color="d.ops.violations_open ? 'y' : 'g'" to="/violations" />
      <Kpi icon="user-check" label="متقدمون جدد" :value="d.ops.applications_new" :hint="d.ops.evals_unpublished ? `${d.ops.evals_unpublished} تقييمات غير منشورة` : ''" color="b" to="/recruitment" />
    </div>
    <div v-if="d.me?.todo?.length" class="card">
      <h3>مطلوب منك</h3>
      <div class="row" style="gap:8px">
        <RouterLink v-for="(x, i) in d.me.todo" :key="i" :to="'/' + x.screen" class="pill" :class="{ warn: 'warn', bad: 'bad', info: 'info' }[x.kind]" style="text-decoration:none"><Icon name="alert" :size="13" /> {{ x.label }}</RouterLink>
      </div>
    </div>

    <!-- quick actions for every employee (owner's note: the employee needs clear action icons on the site, like the app) -->
    <div v-if="d.me" class="card">
      <h3>{{ t('إجراءات سريعة') }}</h3>
      <div class="qa-grid">
        <button v-for="q in quick" :key="q.label" type="button" class="qa" :style="{ '--c': q.color }" @click="q.run()">
          <span class="qa-ic"><Icon :name="q.icon" :size="22" /></span><span class="qa-l">{{ q.label }}</span><span v-if="q.badge" class="qa-b">{{ q.badge }}</span>
        </button>
      </div>
    </div>

    <div class="grid g2">
      <div v-if="d.me" class="card">
        <h3>{{ t('يومي') }} <span class="sub">{{ auth.emp?.name }} · {{ auth.emp?.job_title?.name }}</span></h3>
        <div class="grid g3">
          <div class="kpi center" :style="{ '--c': todayRec?.check_in ? 'var(--ok)' : 'var(--bad)' }">
            <div class="lab">هل سجّلت حضوري؟</div>
            <template v-if="d.me.punch_required">
              <div class="val row" style="font-size:17px;justify-content:center;gap:6px" :class="todayRec?.check_in ? 'ok' : 'bad'"><Icon :name="todayRec?.check_in ? 'check-circle' : 'alert'" :size="18" />{{ todayRec?.check_in ? fmtT(todayRec.check_in) : 'لم يُسجَّل' }}</div>
              <button class="btn sm" :class="todayRec?.check_in && !todayRec?.check_out ? 'dan' : 'pri'" :disabled="!!todayRec?.check_out" @click="punchOpen = true">
                {{ todayRec?.check_out ? t('يوم مكتمل') : todayRec?.check_in ? t('تسجيل انصراف') : t('تسجيل حضور') }}
              </button>
            </template>
            <div v-else class="small">معفى من التحضير</div>
          </div>
          <Kpi icon="leave" :label="t('رصيد الإجازة السنوية')" color="b" to="/leaves">{{ d.me.annual.available }} <span class="muted small">يوم</span></Kpi>
          <Kpi icon="requests" :label="t('طلباتي المعلّقة')" :value="d.me.pending_mine" :hint="`حضور ${d.me.month.present} · تأخير ${d.me.month.late} · غياب ${d.me.month.absent}`" color="y" to="/requests" />
        </div>
        <div v-if="d.me.last_eval" class="row mt small"><Icon name="chart" :size="15" /> آخر تقييم {{ fmtYM(d.me.last_eval.period) }}: <b class="num">{{ d.me.last_eval.score }}</b> — {{ d.me.last_eval.grade }} · {{ d.me.last_eval.grade_label }} <RouterLink class="btn ghost xs" to="/evaluation">التفاصيل</RouterLink></div>
      </div>

      <div v-if="auth.isApprover" class="card">
        <h3>{{ t('صندوق الموافقات') }} <span class="sub">{{ d.kpis.inbox }}</span><span class="acts"><RouterLink class="btn ghost sm" to="/approvals">{{ t('عرض الكل') }}</RouterLink></span></h3>
        <div v-if="!d.inbox.length" class="empty"><div class="ic"><Icon name="inbox" :size="44" :stroke="1.4" /></div>لا يوجد ما ينتظر اعتمادك</div>
        <div v-dt v-else class="tw"><table>
          <thead><tr><th>{{ t('الطلب') }}</th><th>{{ t('مقدّم الطلب') }}</th><th>{{ t('الانتظار') }}</th><th /></tr></thead>
          <tbody><tr v-for="r in d.inbox" :key="r.id">
            <td><b>{{ r.type_label }}</b><span class="tiny muted">{{ r.summary }}</span></td><td>{{ r.employee }}</td>
            <td><span :class="r.age_days >= (auth.meta?.policy?.sla_days || 3) ? 'pill bad' : ''">{{ r.age_days }} يوم</span></td>
            <td><button class="btn ghost xs" @click="openId = r.id">{{ t('تفاصيل') }}</button></td>
          </tr></tbody>
        </table></div>
      </div>

      <div class="card">
        <h3>{{ t('من في إجازة') }} <span class="sub">الأيام السبعة القادمة</span></h3>
        <div v-if="!d.on_leave.length" class="empty">لا إجازات</div>
        <div v-for="l in d.on_leave" :key="l.employee + l.from" class="row between" style="padding:6px 0;border-bottom:1px solid var(--line2)">
          <b>{{ l.employee }}</b><span class="small muted">{{ l.type }} · {{ l.from }} → {{ l.to }}</span>
        </div>
      </div>

      <div v-if="auth.isApprover && d.by_company.length > 1" class="card">
        <h3>الموظفون حسب الشركة</h3>
        <div v-for="c in d.by_company" :key="c.id" class="mb">
          <div class="row between small"><span>{{ c.name }}</span><b>{{ c.employees_count }}</b></div>
          <div class="bar"><i :style="{ width: (c.employees_count / maxCo) * 100 + '%' }" /></div>
        </div>
      </div>
    </div>
  </template>
  <PunchModal v-if="punchOpen" :today="todayRec" @close="punchOpen = false" @done="load" />
  <NewRequest v-if="newReq" :initial="reqType" @close="newReq = false; reqType = null" @done="load" />
  <RequestDetail v-if="openId" :id="openId" @close="openId = null" @changed="load" />
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import NewRequest from '../components/NewRequest.vue'
import PunchModal from '../components/PunchModal.vue'
import RequestDetail from '../components/RequestDetail.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { DAYS_AR, fmtT, fmtYM, today } from '../utils'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const d = ref(null)
const punchOpen = ref(false)
const newReq = ref(false)
const reqType = ref(null)
const openId = ref(null)
const router = useRouter()
const ask = (code) => { reqType.value = code; newReq.value = true }
const go = (to) => router.push(to)
const quick = computed(() => {
  const me = d.value?.me
  if (!me) return []
  const punch = todayRec.value?.check_in && !todayRec.value?.check_out ? 'تسجيل انصراف' : 'تسجيل حضور'
  return [
    me.punch_required && !todayRec.value?.check_out && { icon: 'fingerprint', label: punch, color: 'var(--ok)', run: () => { punchOpen.value = true } },
    { icon: 'leave', label: 'طلب إجازة', color: 'var(--deep)', run: () => ask('leave') },
    { icon: 'signature', label: 'طلب خطاب', color: 'var(--purple)', run: () => ask('letter') },
    { icon: 'money', label: 'طلب سلفة', color: 'var(--warn)', run: () => ask('advance') },
    me.punch_required && { icon: 'clock', label: 'تصحيح حضور', color: 'var(--blue)', run: () => ask('correction') },
    { icon: 'requests', label: 'طلب آخر', color: 'var(--navy)', run: () => ask(null) },
    { icon: 'report', label: 'تقريري الشهري', color: 'var(--deep)', run: () => go('/reports') },
    { icon: 'lists', label: 'التقرير الأسبوعي', color: 'var(--cyan)', run: () => go('/reports?tab=weekly') },
    { icon: 'training', label: 'نشاط تطوير', color: 'var(--purple)', run: () => go('/development') },
    { icon: 'sheet', label: 'قسيمة الراتب', color: 'var(--ok)', run: () => go('/payroll') },
    { icon: 'mail', label: 'البريد الداخلي', color: 'var(--blue)', run: () => go('/mail') },
    { icon: 'megaphone', label: 'الإعلانات', color: 'var(--warn)', run: () => go('/announcements') },
    { icon: 'user', label: 'ملفي', color: 'var(--navy)', run: () => go('/me') },
    // the group CEO sets each package's benefits (leave balances, allowances, extra items) — HR proposes per-employee changes
    auth.has('super', 'hr_mgr') && { icon: 'building', label: 'التصنيفات ومزاياها', color: 'var(--purple)', run: () => go('/companies?tab=packages') },
    auth.isApprover && { icon: 'megaphone', label: 'تنبيه لفريقي', color: 'var(--bad)', run: () => go('/announcements') },
  ].filter(Boolean)
})
const todayRec = computed(() => d.value?.me?.today)
const maxCo = computed(() => Math.max(1, ...(d.value?.by_company || []).map((c) => c.employees_count)))
const hijri = (() => { try { return new Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura-nu-latn', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date()) } catch { return '' } })()
async function load() { d.value = await api.get('/dashboard', { params: { company_id: ui.company || undefined } }) }
// live: refresh every 30 seconds while the page is visible, so a new check-in shows without reloading
let timer = null
const tick = () => { if (document.visibilityState === 'visible') load().catch(() => {}) }
onMounted(() => { load(); timer = setInterval(tick, 30000); document.addEventListener('visibilitychange', tick) })
onUnmounted(() => { clearInterval(timer); document.removeEventListener('visibilitychange', tick) })
watch(() => ui.company, load)
</script>

<style scoped>
.qa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px}
.qa{position:relative;display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 6px;border:1px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink);transition:transform .12s,box-shadow .12s,border-color .12s}
.qa:hover{transform:translateY(-1px);box-shadow:var(--shadow);border-color:color-mix(in srgb,var(--c) 45%,var(--line))}
.qa-ic{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;color:var(--c);background:color-mix(in srgb,var(--c) 13%,transparent)}
.qa-l{font-size:12.5px;font-weight:700;text-align:center;line-height:1.35}
.qa-b{position:absolute;top:6px;inset-inline-end:6px;background:var(--bad);color:#fff;border-radius:9px;font-size:10.5px;padding:0 6px}
@media (max-width:480px){.qa-grid{grid-template-columns:repeat(3,1fr)}.qa{padding:10px 4px}}
</style>
