<template>
  <div class="page-h">
    <div><h1>{{ t('المشاريع') }}</h1><p>مشاريع ربعية بخطة 12 أسبوعًا ونسبة إنجاز لكل أسبوع، أعضاء، وعقبات بمالك وقرار — تربط أعمال الفريق بالتقارير الشهرية</p></div>
    <div class="acts"><select v-model="quarter" class="in" style="width:auto" @change="load"><option value="">كل الأرباع</option><option v-for="q in quarters" :key="q" :value="q">{{ q }}</option></select><button v-if="auth.isApprover" class="btn pri sm" @click="pf = { name: '', goal: '', company_id: auth.meta.companies[0]?.id, owner_employee_id: auth.emp?.id, quarter: curQuarter(), members: [], status: 'active' }"><Icon name="plus" :size="16" /> مشروع</button></div>
  </div>
  <div v-if="!rows.length" class="card"><div class="empty"><div class="ic"><Icon name="kanban" :size="40" :stroke="1.4" /></div>لا مشاريع</div></div>
  <div class="grid g3">
    <div v-for="p in rows" :key="p.id" class="card" style="cursor:pointer" @click="open(p.id)">
      <h3>{{ p.name }} <span class="sub">{{ p.quarter }} · {{ p.company }}</span><span class="pill" :class="{ active: 'info', done: 'ok', archived: 'gray' }[p.status]">{{ { active: 'نشط', done: 'مكتمل', archived: 'مؤرشف' }[p.status] }}</span></h3>
      <div class="row" style="justify-content:space-between"><span class="small">المالك: {{ p.owner || '—' }}</span><span class="small">{{ (p.members || []).length }} أعضاء</span></div>
      <div class="bar mt"><i :style="`width:${p.progress}%`" /></div>
      <div class="row mt" style="justify-content:space-between"><b>{{ p.progress }}%</b><span class="tiny muted">{{ p.done_weeks }}/12 أسابيع · {{ p.late_weeks }} متأخر · {{ p.open_obstacles }} عقبات مفتوحة</span></div>
    </div>
  </div>

  <Modal v-if="d" :title="d.name" size="wide" @close="d = null">
    <div class="row mb"><span class="pill info">{{ d.quarter }}</span><span class="small muted">{{ d.company }} · المالك {{ d.owner || '—' }} · الأعضاء: {{ d.members_list.map((m) => m.name).join('، ') || '—' }}</span><span v-if="d.can_edit && auth.isApprover" class="acts"><button class="btn ghost xs" @click="pf = { id: d.id, name: d.name, goal: d.goal, company_id: d.company_id, owner_employee_id: d.owner_employee_id, quarter: d.quarter, members: d.members || [], status: d.status }">تعديل</button></span></div>
    <p v-if="d.goal" class="small">{{ d.goal }}</p>
    <div class="grid g2">
      <div><h4 style="margin:0 0 6px">خطة الأسابيع</h4>
        <div v-dt class="tw"><table><thead><tr><th>#</th><th>البداية</th><th>الخطة</th><th>%</th><th>الحالة</th><th /></tr></thead>
          <tbody><tr v-for="w in d.weeks" :key="w.n"><td>{{ w.n }}</td><td class="num small">{{ w.start }}</td><td class="small">{{ w.plan || '—' }}<div v-if="w.note" class="tiny muted">{{ w.note }}</div></td><td>{{ w.pct }}%</td><td><span class="pill" :class="{ planned: 'gray', progress: 'info', done: 'ok', late: 'bad' }[w.status]">{{ { planned: 'مخطط', progress: 'جارٍ', done: 'منجز', late: 'متأخر' }[w.status] }}</span></td><td><button v-if="d.can_edit" class="btn ghost xs" @click="wf = { ...w }"><Icon name="edit" :size="13" /></button></td></tr></tbody></table></div>
      </div>
      <div><h4 style="margin:0 0 6px">العقبات <button v-if="d.can_edit" class="btn sec xs" @click="obf = { title: '', owner_employee_id: null, severity: 'med', due_date: '' }"><Icon name="plus" :size="13" /> عقبة</button></h4>
        <div v-if="!d.obstacles.length" class="empty">لا عقبات</div>
        <div v-for="o in d.obstacles" :key="o.id" class="item-card">
          <div class="row" style="justify-content:space-between"><b>{{ o.title }}</b><span class="pill" :class="o.status === 'open' ? { high: 'bad', med: 'warn', low: 'gray' }[o.severity] : 'ok'">{{ o.status === 'open' ? { high: 'عالية', med: 'متوسطة', low: 'منخفضة' }[o.severity] : 'مغلقة' }}</span></div>
          <div class="tiny muted">المالك: {{ o.owner || '—' }}<template v-if="o.due_date"> · الاستحقاق {{ o.due_date }}</template></div>
          <div v-if="o.decision" class="small">القرار: {{ o.decision }}</div>
          <button v-if="o.status === 'open' && d.can_edit" class="btn ghost xs mt" @click="obf = { ...o }">إغلاق بقرار</button>
        </div>
      </div>
    </div>
    <template #foot><button class="btn sec" @click="d = null">إغلاق</button></template>
  </Modal>

  <Modal v-if="wf" :title="`الأسبوع ${wf.n} — ${wf.start}`" size="narrow" @close="wf = null">
    <div class="fg"><label>الخطة</label><textarea v-model="wf.plan" class="in" rows="3" /></div>
    <div class="fr"><div class="fg"><label>نسبة الإنجاز %</label><input v-model.number="wf.pct" type="number" min="0" max="100" class="in" /></div><div class="fg"><label>الحالة</label><select v-model="wf.status" class="in"><option value="planned">مخطط</option><option value="progress">جارٍ</option><option value="done">منجز</option><option value="late">متأخر</option></select></div></div>
    <div class="fg"><label>ملاحظة</label><input v-model="wf.note" class="in" /></div>
    <template #foot><button class="btn sec" @click="wf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveWeek">حفظ</button></template>
  </Modal>
  <Modal v-if="obf" :title="obf.id ? 'إغلاق العقبة' : 'عقبة جديدة'" size="narrow" @close="obf = null">
    <template v-if="!obf.id">
      <div class="fg"><label>العنوان *</label><input v-model="obf.title" class="in" /></div>
      <div class="fr"><div class="fg"><label>المالك</label><select v-model="obf.owner_employee_id" class="in"><option :value="null">—</option><option v-for="m in d.members_list" :key="m.id" :value="m.id">{{ m.name }}</option></select></div><div class="fg"><label>الخطورة</label><select v-model="obf.severity" class="in"><option value="high">عالية</option><option value="med">متوسطة</option><option value="low">منخفضة</option></select></div></div>
      <div class="fg"><label>الاستحقاق</label><DateInput v-model="obf.due_date" /></div>
    </template>
    <div v-else class="fg"><label>القرار *</label><textarea v-model="obf.decision" class="in" rows="3" /></div>
    <template #foot><button class="btn sec" @click="obf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveObstacle">{{ obf.id ? 'إغلاق' : 'إضافة' }}</button></template>
  </Modal>
  <Modal v-if="pf" :title="pf.id ? 'تعديل مشروع' : 'مشروع جديد'" @close="pf = null">
    <div class="fr"><div class="fg" style="grid-column:span 2"><label>الاسم *</label><input v-model="pf.name" class="in" /></div><div class="fg"><label>الشركة</label><select v-model="pf.company_id" class="in"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div><div class="fg"><label>الربع</label><input v-model="pf.quarter" class="in" style="direction:ltr" placeholder="2026-Q4" :disabled="!!pf.id" /></div><div class="fg"><label>المالك</label><SearchSelect v-model="pf.owner_employee_id" :options="empOptions" /></div><div class="fg"><label>الحالة</label><select v-model="pf.status" class="in"><option value="active">نشط</option><option value="done">مكتمل</option><option value="archived">مؤرشف</option></select></div></div>
    <div class="fg"><label>الهدف</label><textarea v-model="pf.goal" class="in" rows="2" /></div>
    <div class="fg"><label>الأعضاء</label><div class="row" style="max-height:150px;overflow:auto"><label v-for="e in emps" :key="e.id" class="chk small"><input v-model="pf.members" type="checkbox" :value="e.id" /> {{ e.name }}</label></div></div>
    <template #foot><button class="btn sec" @click="pf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveProject">حفظ</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const curQuarter = () => { const d = new Date(); return `${d.getFullYear()}-Q${Math.floor(d.getMonth() / 3) + 1}` }
const quarters = computed(() => { const y = new Date().getFullYear(); return [y - 1, y, y + 1].flatMap((yy) => [1, 2, 3, 4].map((q) => `${yy}-Q${q}`)) })
const quarter = ref(curQuarter()); const rows = ref([]); const d = ref(null); const wf = ref(null); const obf = ref(null); const pf = ref(null); const emps = ref([]); const busy = ref(false)
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
async function load() { rows.value = await api.get('/projects', { params: { quarter: quarter.value || undefined } }); if (auth.isApprover && !emps.value.length) emps.value = await api.get('/employees/options') }
async function open(id) { d.value = await api.get(`/projects/${id}`) }
async function saveWeek() { busy.value = true; try { d.value = await api.post(`/projects/${d.value.id}/week`, wf.value); wf.value = null; load() } finally { busy.value = false } }
async function saveObstacle() {
  busy.value = true
  try { if (obf.value.id) { if (!obf.value.decision?.trim()) return ui.toast('القرار إلزامي', 'warn'); d.value = await api.put(`/projects/${d.value.id}/obstacles/${obf.value.id}`, { status: 'closed', decision: obf.value.decision }) } else { if (!obf.value.title?.trim()) return ui.toast('العنوان إلزامي', 'warn'); d.value = await api.post(`/projects/${d.value.id}/obstacles`, obf.value) } obf.value = null; load() } finally { busy.value = false }
}
async function saveProject() { if (!pf.value.name?.trim()) return ui.toast('الاسم إلزامي', 'warn'); busy.value = true; try { const r = pf.value.id ? await api.put(`/projects/${pf.value.id}`, pf.value) : await api.post('/projects', pf.value); ui.toast('حُفظ المشروع', 'ok'); pf.value = null; d.value = r; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false } }
onMounted(load)
</script>
