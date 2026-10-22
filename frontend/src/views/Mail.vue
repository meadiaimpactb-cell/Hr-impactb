<template>
  <div class="page-h">
    <div><h1>{{ t('البريد الداخلي') }}</h1><p>مراسلات داخلية بين المستخدمين بمرفقات وحالة قراءة</p></div>
    <div class="acts"><button v-if="auth.emp?.manager?.user_id" class="btn sec sm" @click="openNew(auth.emp.manager)"><Icon name="send" :size="16" /> مراسلة مديري المباشر</button><button class="btn pri sm" @click="openNew()"><Icon name="mail" :size="16" /> رسالة جديدة</button></div>
  </div>
  <div v-if="auth.has('super', 'hr_mgr')" class="tabs"><button :class="{ on: tab === 'mine' }" @click="tab = 'mine'">بريدي</button><button :class="{ on: tab === 'all' }" @click="tab = 'all'; loadAll()">كل المراسلات (حوكمة)</button></div>
  <div v-if="tab === 'all'" class="card">
    <div class="notice info small">اطلاع الحوكمة (اللائحة §115): يُسجَّل كل اطلاع في سجل التدقيق ولا يُشعَر به المتراسلون.</div>
    <div class="row" style="gap:8px"><input v-model.trim="gq" class="in" style="max-width:280px" placeholder="بحث بالموضوع أو اسم المتراسل…" @keyup.enter="loadAll" /><DateInput v-model="gFrom" placeholder="من تاريخ" /><DateInput v-model="gTo" placeholder="إلى تاريخ" /><button class="btn sec sm" @click="loadAll"><Icon name="search" :size="14" /> بحث</button></div>
    <div v-if="!gov.length" class="empty">لا نتائج</div>
    <details v-for="g in gov" :key="g.id" class="gov"><summary><b>{{ g.subject }}</b> <span class="tiny muted">{{ g.participants.join('، ') }} · {{ fmtDT(g.last_at) }} · {{ g.messages.length }}</span></summary>
      <div v-for="(m, i) in g.messages" :key="i" class="msg"><div class="tiny muted">{{ m.from }} · {{ fmtDT(m.at) }}</div><div style="white-space:pre-wrap">{{ m.body }}</div></div>
    </details>
  </div>
  <div v-else class="grid mail-grid">
    <div class="card mail-list">
      <div class="row" style="gap:6px;margin-bottom:8px;flex-wrap:nowrap"><input v-model.trim="q" class="in" placeholder="بحث بالموضوع أو المرسل…" style="min-width:0" /><label class="chk tiny" style="white-space:nowrap"><input v-model="onlyUnread" type="checkbox" /> غير المقروء</label></div>
      <div class="row" style="gap:6px;margin-bottom:8px;flex-wrap:wrap"><select v-model="fSender" class="in" style="flex:1;min-width:140px"><option value="">كل المرسلين</option><option v-for="x in senders" :key="x" :value="x">{{ x }}</option></select>
        <DateInput v-model="fFrom" placeholder="من تاريخ" /><DateInput v-model="fTo" placeholder="إلى تاريخ" /><button v-if="fSender || fFrom || fTo" class="btn ghost xs" @click="fSender = ''; fFrom = ''; fTo = ''">مسح</button></div>
      <div class="tabs mb"><button :class="{ on: box === 'in' }" @click="box = 'in'">المحادثات</button><button :class="{ on: box === 'drafts' }" @click="box = 'drafts'; loadDrafts()">المسودات<span v-if="drafts.length" class="cnt">{{ drafts.length }}</span></button></div>
      <template v-if="box === 'drafts'">
        <div class="notice info small">المسودة لم تُرسل بعد، ويمكنك تعديلها أو حذفها. الرسالة المرسلة لا تُحذف لأنها أصبحت سجلًا معتمدًا.</div>
        <div v-if="!drafts.length" class="empty">لا مسودات</div>
        <div v-for="d in drafts" :key="d.id" class="thread" @click="openDraft(d)">
          <div class="row" style="justify-content:space-between;flex-wrap:nowrap"><b>{{ d.subject || '(بدون موضوع)' }}</b><button class="btn ghost xs" title="حذف المسودة" @click.stop="removeDraft(d)"><Icon name="trash" :size="14" /></button></div>
          <div class="small muted">إلى: {{ d.to_names.join('، ') || '—' }} · {{ fmtDT(d.updated_at) }}</div><div class="tiny muted">{{ (d.body || '').slice(0, 80) }}</div>
        </div>
      </template>
      <template v-else>
      <div v-if="!shown.length" class="empty">لا رسائل</div>
      <div v-for="th in shown" :key="th.id" class="thread" :class="{ on: cur?.id === th.id, unread: th.unread }" @click="open(th.id)">
        <div class="row" style="justify-content:space-between"><b>{{ th.subject }}</b><span class="tiny muted">{{ fmtDT(th.last_at) }}</span></div>
        <div class="small muted">{{ th.others }} · {{ th.count }}<span v-if="th.unread" class="pill info" style="margin-inline-start:6px;font-size:10.5px">غير مقروءة</span></div><div class="tiny muted"><b v-if="th.last_from">{{ th.last_from }}:</b> {{ th.preview }}</div>
      </div>
      </template>
    </div>
    <div class="card" style="margin:0">
      <div v-if="!cur" class="empty">اختر محادثة</div>
      <template v-else>
        <h3>{{ cur.subject }} <span class="sub">{{ cur.participants.length > 2 ? 'محادثة جماعية: ' : 'محادثة خاصة مع: ' }}{{ cur.participants.filter((p) => p.id !== auth.user.id).map((p) => p.name).join('، ') }}</span></h3>
        <div style="max-height:50vh;overflow:auto">
          <div v-for="m in cur.messages" :key="m.id" class="msg" :class="{ mine: m.mine }"><div class="tiny muted">{{ m.from?.name }} · {{ fmtDT(m.at) }}</div><div style="white-space:pre-wrap">{{ m.body }}</div><a v-if="m.file" class="btn ghost xs" href="#" @click.prevent="fileUrl(m.file)"><Icon name="download" :size="13" /> مرفق</a></div>
        </div>
        <div class="row mt" style="align-items:flex-end"><textarea v-model="reply" class="in" rows="2" placeholder="اكتب ردًا…" style="flex:1" /><input type="file" style="max-width:180px" @change="file = $event.target.files[0]" /><button class="btn pri" :class="{ loading: busy }" @click="send"><Icon name="send" :size="15" flip-rtl /></button></div>
      </template>
    </div>
  </div>

  <Modal v-if="nf" :title="nf.draft_id ? 'مسودة رسالة' : 'رسالة جديدة'" @close="nf = null">
    <div v-if="companies.length > 1" class="fg"><label>الشركة</label><select v-model="coFilter" class="in"><option value="">كل الشركات المتاحة لك</option><option v-for="c in companies" :key="c" :value="c">{{ c }}</option></select></div>
    <div class="fg"><label>إلى * <span class="tiny muted">(يمكن اختيار أكثر من مستلم)</span></label><SearchSelect :model-value="null" :options="users.filter((u) => !nf.to.includes(u.id) && (!coFilter || u.company === coFilter)).map((u) => ({ value: u.id, label: u.name, sub: [u.role, u.company].filter(Boolean).join(' · ') }))" placeholder="ابحث عن مستلم…" @update:model-value="(v) => v && nf.to.push(v)" /><div v-if="nf.to.length" class="row" style="gap:6px;margin-top:6px"><span v-for="id in nf.to" :key="id" class="pill info">{{ users.find((u) => u.id === id)?.name || id }} <a style="cursor:pointer" @click="nf.to = nf.to.filter((x) => x !== id)">×</a></span></div><span class="tiny muted">تصل الرسالة داخل المنصة، ونسخة منها إلى البريد الإلكتروني الرسمي للمستلم.</span></div>
    <label v-if="nf.to.length > 1" class="chk"><input v-model="nf.group" type="checkbox" /> محادثة جماعية مشتركة <span class="tiny muted">(بدونها تصل الرسالة لكل مستلم في محادثة خاصة منفصلة، ولا يرى أي مستلم الآخرين ولا ردودهم)</span></label>
    <div class="fg"><label>الموضوع *</label><input v-model="nf.subject" class="in" /></div>
    <div class="fg"><label>الرسالة *</label><textarea v-model="nf.body" class="in" rows="5" /></div>
    <div class="fg"><label>مرفق</label><input type="file" class="in" @change="file = $event.target.files[0]" /></div>
    <template #foot><button v-if="nf.draft_id" class="btn dan" style="margin-inline-end:auto" @click="removeDraft({ id: nf.draft_id }, true)"><Icon name="trash" :size="14" /> حذف المسودة</button><button class="btn sec" @click="nf = null">إلغاء</button><button class="btn ghost" :disabled="busy" @click="saveDraft">حفظ كمسودة</button><button class="btn pri" :class="{ loading: busy }" @click="create">إرسال</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { confirmBox } from '../dialog'
import DateInput from '../components/DateInput.vue'
import { useI18n } from 'vue-i18n'
import api, { fileUrl } from '../api'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtDT } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const threads = ref([]); const cur = ref(null); const reply = ref(''); const file = ref(null); const busy = ref(false); const nf = ref(null); const users = ref([])
const tab = ref('mine'); const q = ref(''); const onlyUnread = ref(false); const coFilter = ref('')
const gov = ref([]); const gq = ref(''); const gFrom = ref(''); const gTo = ref('')
const companies = computed(() => [...new Set(users.value.map((u) => u.company).filter(Boolean))])
const fSender = ref(''); const fFrom = ref(''); const fTo = ref('')
const senders = computed(() => [...new Set(threads.value.flatMap((t) => t.senders || []).filter(Boolean))].sort())
const shown = computed(() => threads.value.filter((t) => (!onlyUnread.value || t.unread) && (!q.value || `${t.subject} ${t.others || ''} ${t.preview || ''}`.toLowerCase().includes(q.value.toLowerCase()))
  && (!fSender.value || (t.senders || []).includes(fSender.value)) && (!fFrom.value || String(t.last_at).slice(0, 10) >= fFrom.value) && (!fTo.value || String(t.last_at).slice(0, 10) <= fTo.value)))
async function load() { threads.value = await api.get('/mail') }
async function loadAll() { gov.value = await api.get('/mail/all', { params: { q: gq.value || undefined, from: gFrom.value || undefined, to: gTo.value || undefined } }) }
async function open(id) {
  cur.value = await api.get(`/mail/${id}`)
  const th = threads.value.find((x) => x.id === id)
  if (th?.unread) { th.unread = false; window.dispatchEvent(new CustomEvent('hc:mail-read')) } // the header counter drops right away
}
// form data for messages with an attachment: yes/no options go as 1/0 (a form cannot carry true/false)
function fd(obj) { const x = new FormData(); Object.entries(obj).forEach(([k, v]) => { if (Array.isArray(v)) v.forEach((i) => x.append(k + '[]', i)); else if (typeof v === 'boolean') x.append(k, v ? 1 : 0); else if (v !== null && v !== undefined && v !== '') x.append(k, v) }); if (file.value) x.append('attachment', file.value); return x }
async function send() { if (!reply.value.trim()) return; busy.value = true; try { cur.value = await api.post(`/mail/${cur.value.id}/reply`, fd({ body: reply.value })); reply.value = ''; file.value = null; load() } finally { busy.value = false } }
async function openNew(manager) { nf.value = { to: manager?.user_id ? [manager.user_id] : [], subject: '', body: '', group: false }; if (!users.value.length) users.value = await api.get('/mail/recipients') }
async function create() {
  if (!nf.value.to.length || !nf.value.subject.trim() || !nf.value.body.trim()) return ui.toast('المستلم والموضوع والرسالة إلزامية', 'warn')
  busy.value = true
  try { const wasDraft = nf.value.draft_id; const r = await api.post('/mail', fd(nf.value)); nf.value = null; file.value = null; box.value = 'in'; await load(); if (wasDraft) loadDrafts(); open(r.id) } finally { busy.value = false }
}
const box = ref('in'); const drafts = ref([])
async function loadDrafts() { drafts.value = await api.get('/mail/drafts') }
async function openDraft(d) { await openNew(); nf.value = { to: [...d.to], subject: d.subject || '', body: d.body || '', group: !!d.group, draft_id: d.id } }
async function saveDraft() {
  const n = nf.value
  if (!n.subject.trim() && !n.body.trim() && !n.to.length) return ui.toast('المسودة فارغة', 'warn')
  busy.value = true
  try {
    await api.post('/mail/drafts', { id: n.draft_id || null, to: n.to, subject: n.subject, body: n.body, group: n.group })
    ui.toast(file.value ? 'حُفظت المسودة (المرفق لا يُحفظ في المسودة)' : 'حُفظت المسودة', 'ok'); nf.value = null; file.value = null; box.value = 'drafts'; loadDrafts()
  } finally { busy.value = false }
}
async function removeDraft(d, fromModal) {
  if (!(await confirmBox('حذف هذه المسودة نهائيًا؟', { confirmText: 'حذف' }))) return
  await api.delete(`/mail/drafts/${d.id}`); if (fromModal) nf.value = null; ui.toast('حُذفت المسودة', 'ok'); loadDrafts()
}
onMounted(() => { load(); loadDrafts() })
</script>

<style scoped>
.thread{padding:10px;border-bottom:1px solid var(--line);cursor:pointer;border-radius:8px}.thread:hover{background:var(--soft)}.thread.on{background:var(--infobg)}.thread.unread{background:var(--infobg);border-inline-start:4px solid var(--blue, #1C84C6)}.thread.unread b{color:var(--navy);font-weight:800}.thread.unread::before{content:'';display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--blue, #1C84C6);margin-inline-end:6px}
.msg{padding:10px 12px;border-radius:12px;background:var(--soft);margin-bottom:8px;max-width:80%}.msg.mine{background:var(--infobg);margin-inline-start:auto}
.mail-grid{grid-template-columns:minmax(0,1fr);gap:14px}
.gov{border-bottom:1px solid var(--line);padding:8px 0}.gov summary{cursor:pointer}
.mail-list{margin:0;max-height:40dvh;overflow:auto;overscroll-behavior:contain}
@media(min-width:768px){.mail-grid{grid-template-columns:minmax(260px,340px) minmax(0,1fr)}.mail-list{max-height:70dvh}}
@media(max-width:767px){.msg{max-width:92%}}
</style>
