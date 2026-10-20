<template>
  <div class="page-h">
    <div><h1>{{ t('الإعلانات') }}</h1><p>إعلانات موجّهة (المجموعة، شركات، وظائف، نوع تعاقد، أشخاص) مع إيصالات قراءة وإقرار وتذكير — ينشر الرئيس التنفيذي للمجموعة والموارد البشرية على مستوى المجموعة، والرئيس التنفيذي للشركة لشركته أو من يحددهم من موظفيها (§115). يُعد الموظف مبلَّغًا بما يُنشر من تاريخ إتاحته له، ولا تعني القراءة الموافقة على تعديل جوهري في العقد.</p></div>
    <div class="acts"><button v-if="pub.manage_publishers?.length" class="btn ghost sm" @click="openPublishers"><Icon name="users" :size="16" /> الناشرون المخوّلون</button><button v-if="pub.can" class="btn pri sm" @click="openNew"><Icon name="megaphone" :size="16" /> إعلان جديد</button></div>
  </div>
  <div v-if="!rows.length" class="card"><div class="empty"><div class="ic"><Icon name="megaphone" :size="40" :stroke="1.4" /></div>لا إعلانات</div></div>
  <div v-for="a in rows" :key="a.id" class="card" :class="{ dim: a.expired }">
    <h3>{{ a.title }} <span class="sub">{{ a.sender }} · {{ fmtDT(a.published_at) }}<template v-if="a.expires_at"> · ينتهي {{ a.expires_at }}</template></span>
      <span v-if="a.require_ack" class="pill" :class="a.ack_at ? 'ok' : 'warn'">{{ a.ack_at ? 'أقررت بالاطلاع' : 'يتطلب إقرارًا' }}</span>
      <span v-if="a.is_sender || auth.isAdmin" class="acts"><button class="btn ghost xs" @click="openTracking(a)">التتبع ({{ a.reads }}/{{ a.recipients }} قرأوا · {{ a.acks }} أقرّوا)</button></span>
    </h3>
    <p style="white-space:pre-wrap">{{ a.body }}</p>
    <div class="row">
      <a v-if="a.has_attachment" class="btn ghost xs" href="#" @click.prevent="fileUrl(a.file)"><Icon name="download" :size="14" /> مرفق</a>
      <button v-if="a.require_ack && !a.ack_at && !a.is_sender" class="btn okb xs" @click="ack(a)"><Icon name="check-circle" :size="14" /> أقرّ بالاطلاع</button>
      <template v-if="!a.is_sender && reactionsOn"><button v-for="(l, k) in REACT" :key="k" class="btn xs" :class="a.my_reaction === k ? 'sec' : 'ghost'" style="padding:4px 8px" :title="l" :aria-label="l" @click="react(a, k)"><Icon :name="k" :size="16" /></button></template>
    </div>
  </div>

  <Modal v-if="nf" title="إعلان جديد" @close="nf = null">
    <div class="fg"><label>العنوان *</label><input v-model="nf.title" class="in" /></div>
    <div class="fg"><label>النص *</label><textarea v-model="nf.body" class="in" rows="6" /></div>
    <div class="fr">
      <div class="fg"><label>الجمهور</label><select v-model="nf.audience.scope" class="in"><option v-for="(l, k) in scopes" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="fg"><label>ينتهي في</label><DateInput v-model="nf.expires_at" /></div>
    </div>
    <div v-if="nf.audience.scope === 'company'" class="fg"><label>الشركات</label><div class="row"><label v-for="c in pubCompanies" :key="c.id" class="chk"><input v-model="nf.audience.companies" type="checkbox" :value="c.id" /> {{ c.name }}</label></div></div>
    <div v-else-if="nf.audience.scope === 'job'" class="fg"><label>الوظائف</label><div class="row"><label v-for="j in auth.meta.job_titles" :key="j.id" class="chk small"><input v-model="nf.audience.jobs" type="checkbox" :value="j.id" /> {{ j.name }}</label></div></div>
    <div v-else-if="nf.audience.scope === 'contract'" class="fg"><label>نوع التعاقد</label><select v-model="nf.audience.contract" class="in"><option v-for="c in (auth.meta?.lookups?.contract_types || [])" :key="c.code" :value="c.code">{{ c.name }}</option></select></div>
    <div v-else-if="nf.audience.scope === 'people'" class="fg"><label>الأشخاص</label><div class="row" style="max-height:160px;overflow:auto"><label v-for="e in emps" :key="e.id" class="chk small"><input v-model="nf.audience.employee_ids" type="checkbox" :value="e.id" /> {{ e.name }}</label></div></div>
    <label class="chk"><input v-model="nf.require_ack" type="checkbox" /> يتطلب إقرار الاطلاع (نافذة لا تُغلق حتى الإقرار)</label>
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
    <div v-dt class="tw"><table><thead><tr><th>المستلم</th><th>القراءة</th><th>الإقرار</th><th>الجهاز</th></tr></thead><tbody><tr v-for="r in tr.recipients" :key="r.user?.id"><td>{{ r.user?.name }}</td><td class="small">{{ r.read_at ? fmtDT(r.read_at) : '—' }}</td><td class="small">{{ r.ack_at ? fmtDT(r.ack_at) : '—' }}</td><td class="tiny muted">{{ r.device }}</td></tr></tbody></table></div>
    <template #foot><button class="btn sec" @click="tr = null">إغلاق</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
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
const scopes = computed(() => pub.value.all ? { group: 'كل المجموعة', company: 'شركات محددة', job: 'وظائف محددة', contract: 'نوع تعاقد', people: 'أشخاص محددون' } : { company: 'شركتي', job: 'وظائف محددة', people: 'أشخاص محددون' })
async function openPublishers() { if (!emps.value.length) emps.value = await api.get('/employees/options'); pm.value = { rows: await api.get('/announcements/publishers'), company_id: pub.value.manage_publishers[0], employee_id: null } }
async function addPublisher() { if (!pm.value.employee_id) return ui.toast('اختر الموظف', 'warn'); try { pm.value.rows = await api.post('/announcements/publishers', { company_id: pm.value.company_id, employee_id: pm.value.employee_id }); ui.toast('خُوّل الموظف بالنشر', 'ok') } catch (e) { ui.toast(e.message, 'bad', 6000) } }
async function removePublisher(r) { if (!(await confirmBox(`إلغاء تخويل ${r.employee} بالنشر؟`))) return; pm.value.rows = await api.delete(`/announcements/publishers/${r.id}`) }
async function load() {
  api.get('/announcements/can-publish').then((x) => { pub.value = x }).catch(() => {})
  rows.value = await api.get('/announcements'); rows.value.filter((a) => !a.read_at && !a.is_sender).forEach((a) => api.post(`/announcements/${a.id}/read`).catch(() => {})) }
async function openNew() { nf.value = { title: '', body: '', require_ack: false, expires_at: '', audience: { scope: Object.keys(scopes.value)[0], companies: pubCompanies.value.map((c) => c.id), jobs: [], contract: 'full', employee_ids: [] } }; if (!emps.value.length) emps.value = await api.get('/employees/options') }
async function publish() {
  if (!nf.value.title.trim() || !nf.value.body.trim()) return ui.toast('العنوان والنص إلزاميان', 'warn')
  busy.value = true
  try {
    const x = new FormData(); x.append('title', nf.value.title); x.append('body', nf.value.body); x.append('require_ack', nf.value.require_ack ? 1 : 0); if (nf.value.expires_at) x.append('expires_at', nf.value.expires_at)
    const a = nf.value.audience; x.append('audience[scope]', a.scope); a.companies.forEach((c) => x.append('audience[companies][]', c)); a.jobs.forEach((j) => x.append('audience[jobs][]', j)); x.append('audience[contract]', a.contract); a.employee_ids.forEach((e) => x.append('audience[employee_ids][]', e)); if (file.value) x.append('attachment', file.value)
    const r = await api.post('/announcements', x); ui.toast(`نُشر الإعلان إلى ${r.recipients} مستلمًا`, 'ok'); nf.value = null; load()
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function ack(a) { await api.post(`/announcements/${a.id}/ack`); ui.toast('سُجّل إقرارك', 'ok'); load() }
async function react(a, k) { await api.post(`/announcements/${a.id}/react`, { reaction: a.my_reaction === k ? null : k }); load() }
async function openTracking(a) { tr.value = await api.get(`/announcements/${a.id}/tracking`) }
async function remind() { const r = await api.post(`/announcements/${tr.value.announcement.id}/remind`); ui.toast(`أُرسل تذكير إلى ${r.reminded}`, 'ok') }
function printProof() { const w = window.open('', '_blank'); const a = tr.value.announcement; w.document.write(`<html dir="rtl"><head><meta charset="utf-8"><title>إثبات اطلاع</title><style>body{font-family:Tahoma,Arial;padding:30px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:6px}</style></head><body><h2>إثبات اطلاع — ${a.title}</h2><p>${a.sender} · ${fmtDT(a.published_at)}</p><p style="white-space:pre-wrap">${a.body}</p><table><tr><th>المستلم</th><th>القراءة</th><th>الإقرار</th></tr>${tr.value.recipients.map((r) => `<tr><td>${r.user?.name || ''}</td><td>${r.read_at ? fmtDT(r.read_at) : '—'}</td><td>${r.ack_at ? fmtDT(r.ack_at) : '—'}</td></tr>`).join('')}</table></body></html>`); w.document.close(); w.print() }
onMounted(load)
</script>
