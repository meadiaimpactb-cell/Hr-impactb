<template>
  <div class="page-h">
    <div><h1>{{ t('الإعلانات') }}</h1><p>إعلانات وتنبيهات موجّهة (المجموعة، شركات، إدارات، وظائف، نوع تعاقد، أشخاص، فريق المدير) تظهر نافذةً منبثقة في الموقع والتطبيق، مع حالة مقروء / غير مقروء وتأكيد القراءة وتذكير. ينشر الرئيس التنفيذي للمجموعة والموارد البشرية على مستوى المجموعة، والرئيس التنفيذي للشركة لشركته أو من يحددهم من موظفيها (§115)، والمدير المباشر لفريقه. يُعد الموظف مبلَّغًا بما يُنشر من تاريخ إتاحته له، ولا تعني القراءة الموافقة على تعديل جوهري في العقد.</p></div>
    <div class="acts"><button v-if="pub.manage_publishers?.length" class="btn ghost sm" @click="openPublishers"><Icon name="users" :size="16" /> الناشرون المخوّلون</button>
      <button v-if="pub.team && !pub.company" class="btn pri sm" @click="openNew('team')"><Icon name="megaphone" :size="16" /> تنبيه لفريقي</button>
      <button v-else-if="pub.can" class="btn pri sm" @click="openNew()"><Icon name="megaphone" :size="16" /> إعلان أو تنبيه جديد</button></div>
  </div>
  <div class="tabs">
    <button v-for="(l, k) in FILTERS" :key="k" :class="{ on: filter === k }" @click="filter = k">{{ l }}<span v-if="k === 'unread' && unreadCount" class="cnt">{{ unreadCount }}</span></button>
  </div>
  <div v-if="!shown.length" class="card"><div class="empty"><div class="ic"><Icon name="megaphone" :size="40" :stroke="1.4" /></div>{{ rows.length ? 'لا شيء هنا' : 'لا إعلانات' }}</div></div>
  <div v-for="a in shown" :key="a.id" class="card ann" :class="{ dim: a.expired, unread: isUnread(a) }" @click="open(a)">
    <h3><span v-if="isUnread(a)" class="udot" title="غير مقروء" />{{ a.title }} <span class="sub">{{ a.sender }} · {{ fmtDT(a.published_at) }}<template v-if="a.expires_at"> · ينتهي {{ a.expires_at }}</template></span>
      <span v-if="a.audience?.scope === 'team'" class="pill info">تنبيه للفريق</span>
      <span v-if="!a.is_sender" class="pill" :class="isUnread(a) ? 'navy' : 'gray'">{{ isUnread(a) ? 'غير مقروء' : 'مقروء' }}</span>
      <span v-if="a.require_ack" class="pill" :class="a.ack_at ? 'ok' : 'warn'">{{ a.ack_at ? 'أكّدت القراءة ' + fmtDT(a.ack_at) : 'يتطلب تأكيد القراءة' }}</span>
      <span v-if="a.is_sender || auth.isAdmin" class="acts" @click.stop><button class="btn ghost xs" @click="openTracking(a)">التتبع ({{ a.reads }}/{{ a.recipients }} قرأوا<template v-if="a.require_ack"> · {{ a.acks }} أكّدوا</template>)</button></span>
    </h3>
    <p style="white-space:pre-wrap" :class="{ clamp: !expanded.has(a.id) }">{{ a.body }}</p>
    <div class="row" @click.stop>
      <a v-if="a.has_attachment" class="btn ghost xs" href="#" @click.prevent="fileUrl(a.file)"><Icon name="download" :size="14" /> مرفق</a>
      <button v-if="!a.is_sender && !a.read_at && !a.require_ack" class="btn sec xs" @click="markRead(a)"><Icon name="check" :size="14" /> تمت القراءة</button>
      <button v-if="a.require_ack && !a.ack_at && !a.is_sender" class="btn okb xs" @click="ack(a)"><Icon name="check-circle" :size="14" /> أؤكد قراءتي واطلاعي</button>
      <template v-if="!a.is_sender && reactionsOn"><button v-for="(l, k) in REACT" :key="k" class="btn xs" :class="a.my_reaction === k ? 'sec' : 'ghost'" style="padding:4px 8px" :title="l" :aria-label="l" @click="react(a, k)"><Icon :name="k" :size="16" /></button></template>
    </div>
  </div>

  <Modal v-if="nf" :title="nf.audience.scope === 'team' ? 'تنبيه لفريقي' : 'إعلان أو تنبيه جديد'" @close="nf = null">
    <div class="fg"><label>العنوان *</label><input v-model="nf.title" class="in" /></div>
    <div class="fg"><label>النص *</label><textarea v-model="nf.body" class="in" rows="6" /></div>
    <div class="fr">
      <div class="fg"><label>يُرسل إلى</label><select v-model="nf.audience.scope" class="in"><option v-for="(l, k) in scopes" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="fg"><label>ينتهي في</label><DateInput v-model="nf.expires_at" /></div>
    </div>
    <label class="chk small mb" title="يصل الإعلان أيضًا إلى المدير المباشر لكل مستلم، والموارد البشرية، والمالية، والرئيس التنفيذي للشركة والرئيس التنفيذي للمجموعة"><input v-model="nf.audience.leaders" type="checkbox" /> يشمل القادة: المدير المباشر، الموارد البشرية، المالية، الرئيس التنفيذي للشركة وللمجموعة</label>
    <div v-if="nf.audience.scope === 'team'" class="fg"><label>من الفريق <span class="tiny muted">(لا تحديد = كل الفريق، {{ pub.team }} موظف)</span></label>
      <div class="row mb" style="gap:6px"><button type="button" class="btn ghost xs" @click="nf.audience.employee_ids = pub.team_members.map((m) => m.id)">تحديد الكل</button><button type="button" class="btn ghost xs" @click="nf.audience.employee_ids = pub.team_members.filter((m) => m.direct).map((m) => m.id)">المباشرون فقط</button><button type="button" class="btn ghost xs" @click="nf.audience.employee_ids = []">مسح</button></div>
      <div class="row" style="max-height:180px;overflow:auto"><label v-for="m in pub.team_members" :key="m.id" class="chk small"><input v-model="nf.audience.employee_ids" type="checkbox" :value="m.id" /> {{ m.name }}<span v-if="!m.direct" class="tiny muted"> (غير مباشر)</span></label></div></div>
    <div v-else-if="nf.audience.scope === 'dept'" class="fg"><label>الإدارات</label><div class="row"><label v-for="d in (auth.meta.departments || [])" :key="d" class="chk small"><input v-model="nf.audience.depts" type="checkbox" :value="d" /> {{ d }}</label></div></div>
    <div v-else-if="nf.audience.scope === 'company'" class="fg"><label>الشركات</label><div class="row"><label v-for="c in pubCompanies" :key="c.id" class="chk"><input v-model="nf.audience.companies" type="checkbox" :value="c.id" /> {{ c.name }}</label></div></div>
    <div v-else-if="nf.audience.scope === 'job'" class="fg"><label>الوظائف</label><div class="row"><label v-for="j in auth.meta.job_titles" :key="j.id" class="chk small"><input v-model="nf.audience.jobs" type="checkbox" :value="j.id" /> {{ j.name }}</label></div></div>
    <div v-else-if="nf.audience.scope === 'contract'" class="fg"><label>نوع التعاقد</label><select v-model="nf.audience.contract" class="in"><option v-for="c in (auth.meta?.lookups?.contract_types || [])" :key="c.code" :value="c.code">{{ c.name }}</option></select></div>
    <div v-else-if="nf.audience.scope === 'people'" class="fg"><label>الأشخاص</label><input v-model="peopleQ" class="in mb" placeholder="ابحث بالاسم…" /><div class="row" style="max-height:160px;overflow:auto"><label v-for="e in emps.filter((e) => !peopleQ || e.name.includes(peopleQ) || nf.audience.employee_ids.includes(e.id))" :key="e.id" class="chk small"><input v-model="nf.audience.employee_ids" type="checkbox" :value="e.id" /> {{ e.name }}</label></div></div>
    <label class="chk"><input v-model="nf.popup" type="checkbox" /> يظهر نافذةً منبثقة في الموقع والتطبيق حتى يقرأه المستلم</label>
    <label class="chk"><input v-model="nf.require_ack" type="checkbox" /> تأكيد القراءة إلزامي: نافذة لا تُغلق حتى يضغط المستلم «أؤكد قراءتي»، ويُسجَّل وقت التأكيد إثباتًا</label>
    <div class="fg"><label>مرفق</label><input type="file" class="in" @change="file = $event.target.files[0]" /></div>
    <template #foot><button class="btn sec" @click="nf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="publish">نشر وإشعار الجمهور</button></template>
  </Modal>

  <Modal v-if="pm" title="الناشرون المخوّلون (§115)" @close="pm = null">
    <p class="tiny muted">يحدد الرئيس التنفيذي للشركة من موظفيها من يحق له نشر الإعلانات على مستوى الشركة.</p>
    <div v-if="!pm.rows.length" class="empty">لا ناشرين محددين</div>
    <div v-for="r in pm.rows" :key="r.id" class="row mb" style="justify-content:space-between"><span><b>{{ r.employee }}</b> <span class="tiny muted">{{ r.emp_no }} · {{ r.company }}</span></span><button class="btn ghost bad xs" @click="removePublisher(r)">إلغاء التخويل</button></div>
    <div class="fr mt"><div class="fg"><label>الشركة</label><select v-model="pm.company_id" class="in"><option v-for="c in (auth.meta.companies || []).filter((c) => pub.manage_publishers.includes(c.id))" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <div class="fg"><label>الموظف</label><select v-model="pm.employee_id" class="in"><option v-for="e in emps.filter((e) => !e.company_id || e.company_id === pm.company_id)" :key="e.id" :value="e.id">{{ e.name }}</option></select></div></div>
    <template #foot><button class="btn sec" @click="pm = null">إغلاق</button><button class="btn pri" @click="addPublisher">تخويل بالنشر</button></template>
  </Modal>

  <Modal v-if="tr" :title="`تتبع: ${tr.announcement.title}`" size="wide" @close="tr = null">
    <div class="grid g3 mb"><Kpi label="المستلمون" :value="tr.announcement.recipients" color="b" /><Kpi label="قرأوا" :value="tr.announcement.reads" color="g" /><Kpi label="أقرّوا" :value="tr.announcement.acks" color="y" /></div>
    <div class="row mb"><span v-for="(n, k) in tr.reactions" :key="k" class="pill gray" :title="REACT[k]"><Icon :name="k" :size="14" /> {{ n }}</span><button class="btn sec sm" @click="remind">تذكير من لم يقرأ / يقرّ</button><button class="btn ghost sm" @click="printProof"><Icon name="external" :size="14" /> إثبات الاطلاع PDF</button></div>
    <div v-dt class="tw"><table><thead><tr><th>المستلم</th><th>القراءة</th><th>تأكيد القراءة</th><th>الجهاز</th></tr></thead><tbody><tr v-for="r in tr.recipients" :key="r.user?.id"><td>{{ r.user?.name }}</td><td class="small">{{ r.read_at ? fmtDT(r.read_at) : '—' }}</td><td class="small">{{ r.ack_at ? fmtDT(r.ack_at) : '—' }}</td><td class="tiny muted">{{ r.device }}</td></tr></tbody></table></div>
    <template #foot><button class="btn sec" @click="tr = null">إغلاق</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { confirmBox } from '../dialog'
import { useI18n } from 'vue-i18n'
import api, { fileUrl } from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtDT } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const REACT = { like: 'أعجبني', thanks: 'شكرًا', celebrate: 'مبارك' }
const pub = ref({ can: false, all: false, companies: [], manage_publishers: [] }); const pm = ref(null)
const pubCompanies = computed(() => (auth.meta.companies || []).filter((c) => pub.value.all || pub.value.companies.map(Number).includes(c.id)))
const rows = ref([]); const nf = ref(null); const tr = ref(null); const emps = ref([]); const file = ref(null); const busy = ref(false)
const reactionsOn = computed(() => auth.meta?.policy?.reactions_enabled !== false)
const scopes = computed(() => {
  const team = pub.value.team ? { team: 'فريقي (الموظفون التابعون لي)' } : {}
  if (pub.value.all) return { group: 'كل المجموعة', company: 'شركات محددة', dept: 'إدارات محددة', job: 'وظائف محددة', contract: 'نوع تعاقد', people: 'أشخاص محددون', ...team }
  if (pub.value.company) return { company: 'شركتي', dept: 'إدارات محددة', job: 'وظائف محددة', people: 'أشخاص محددون', ...team }
  return team
})
const FILTERS = { all: 'الكل', unread: 'غير المقروءة', ack: 'تتطلب تأكيد قراءتي', sent: 'أرسلتها' }
const filter = ref('all')
const peopleQ = ref('')
const expanded = ref(new Set())
const isUnread = (a) => !a.is_sender && (a.require_ack ? !a.ack_at : !a.read_at)
const unreadCount = computed(() => rows.value.filter(isUnread).length)
const shown = computed(() => rows.value.filter((a) => filter.value === 'all' || (filter.value === 'unread' ? isUnread(a) : filter.value === 'ack' ? a.require_ack && !a.ack_at && !a.is_sender : a.is_sender)))
// opening an announcement marks it read, like a mail message (a read confirmation still needs its own button)
async function open(a) {
  expanded.value = new Set([...expanded.value, a.id])
  if (!a.is_sender && !a.read_at) await markRead(a)
}
async function markRead(a) { await api.post(`/announcements/${a.id}/read`).catch(() => {}); a.read_at = new Date().toISOString(); window.dispatchEvent(new Event('hc:announcements')) }
async function openPublishers() { if (!emps.value.length) emps.value = await api.get('/employees/options'); pm.value = { rows: await api.get('/announcements/publishers'), company_id: pub.value.manage_publishers[0], employee_id: null } }
async function addPublisher() { if (!pm.value.employee_id) return ui.toast('اختر الموظف', 'warn'); try { pm.value.rows = await api.post('/announcements/publishers', { company_id: pm.value.company_id, employee_id: pm.value.employee_id }); ui.toast('خُوّل الموظف بالنشر', 'ok') } catch (e) { ui.toast(e.message, 'bad', 6000) } }
async function removePublisher(r) { if (!(await confirmBox(`إلغاء تخويل ${r.employee} بالنشر؟`))) return; pm.value.rows = await api.delete(`/announcements/publishers/${r.id}`) }
async function load() {
  api.get('/announcements/can-publish').then((x) => { pub.value = x }).catch(() => {})
  rows.value = await api.get('/announcements') }
async function openNew(scope) { peopleQ.value = ''; nf.value = { title: '', body: '', require_ack: false, popup: true, expires_at: '', audience: { scope: scope || Object.keys(scopes.value)[0], companies: pubCompanies.value.map((c) => c.id), jobs: [], depts: [], contract: 'full', employee_ids: [] } }; if (!emps.value.length && pub.value.company) emps.value = await api.get('/employees/options').catch(() => []) }
async function publish() {
  if (!nf.value.title.trim() || !nf.value.body.trim()) return ui.toast('العنوان والنص إلزاميان', 'warn')
  busy.value = true
  try {
    const x = new FormData(); x.append('title', nf.value.title); x.append('body', nf.value.body); x.append('require_ack', nf.value.require_ack ? 1 : 0); x.append('popup', nf.value.popup ? 1 : 0); if (nf.value.expires_at) x.append('expires_at', nf.value.expires_at)
    const a = nf.value.audience; x.append('audience[scope]', a.scope); a.companies.forEach((c) => x.append('audience[companies][]', c)); a.jobs.forEach((j) => x.append('audience[jobs][]', j)); a.depts.forEach((d) => x.append('audience[depts][]', d)); x.append('audience[contract]', a.contract); a.employee_ids.forEach((e) => x.append('audience[employee_ids][]', e)); x.append('audience[leaders]', a.leaders ? 1 : 0); if (file.value) x.append('attachment', file.value)
    const r = await api.post('/announcements', x); ui.toast(`نُشر الإعلان إلى ${r.recipients} مستلمًا`, 'ok'); nf.value = null; load()
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function ack(a) { await api.post(`/announcements/${a.id}/ack`); ui.toast('سُجّل تأكيد قراءتك', 'ok'); window.dispatchEvent(new Event('hc:announcements')); load() }
async function react(a, k) { await api.post(`/announcements/${a.id}/react`, { reaction: a.my_reaction === k ? null : k }); load() }
async function openTracking(a) { tr.value = await api.get(`/announcements/${a.id}/tracking`) }
async function remind() { const r = await api.post(`/announcements/${tr.value.announcement.id}/remind`); ui.toast(`أُرسل تذكير إلى ${r.reminded}`, 'ok') }
function printProof() { const w = window.open('', '_blank'); const a = tr.value.announcement; w.document.write(`<html dir="rtl"><head><meta charset="utf-8"><title>إثبات اطلاع</title><style>body{font-family:Tahoma,Arial;padding:30px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:6px}</style></head><body><h2>إثبات اطلاع — ${a.title}</h2><p>${a.sender} · ${fmtDT(a.published_at)}</p><p style="white-space:pre-wrap">${a.body}</p><table><tr><th>المستلم</th><th>القراءة</th><th>الإقرار</th></tr>${tr.value.recipients.map((r) => `<tr><td>${r.user?.name || ''}</td><td>${r.read_at ? fmtDT(r.read_at) : '—'}</td><td>${r.ack_at ? fmtDT(r.ack_at) : '—'}</td></tr>`).join('')}</table></body></html>`); w.document.close(); w.print() }
onMounted(() => { load(); window.addEventListener('hc:announcements', reloadRows) })
onUnmounted(() => window.removeEventListener('hc:announcements', reloadRows))
const reloadRows = () => api.get('/announcements').then((r) => { rows.value = r }).catch(() => {})
</script>

<style scoped>
.ann{cursor:pointer}
.ann.unread{border-inline-start:4px solid var(--brand)}
.udot{display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--brand);margin-inline-end:6px;vertical-align:middle}
.clamp{display:-webkit-box;-webkit-line-clamp:3;line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
</style>
