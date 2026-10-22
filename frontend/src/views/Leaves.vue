<template>
  <div class="page-h">
    <div><h1>{{ t('الإجازات') }}</h1><p>رصيد لكل نوع بنظامه المستقل · طلب مع بديل وتفويض · تقويم فريق</p></div>
    <div class="acts"><button v-if="auth.emp" class="btn pri sm" @click="newReq = true"><Icon name="plus" :size="16" /> {{ t('طلب إجازة') }}</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ t(x[1]) }}</button></div>

  <template v-if="tab === 'mine' && mine">
    <div class="grid g4 mb">
      <Kpi v-for="b in mine.balances" :key="b.type" :icon="LT_ICON[b.type] || 'calendar'" :label="b.name" color="b" :hint="`مستهلك ${b.used} · محجوز ${b.reserved}${b.accrual === 'monthly' ? ' · مكتسب ' + b.accrued : ''}`">{{ b.available }} <span class="muted small">{{ b.unit_label && b.type === 'annual' ? b.unit_label : 'يوم' }}</span></Kpi>
    </div>
    <div v-if="mine?.next_year" class="notice info small">لديك إجازات محجوزة في {{ mine.next_year.year }} — تُخصم من رصيد تلك السنة: <span v-for="b in mine.next_year.balances.filter((x) => x.used || x.reserved)" :key="b.type"><b>{{ b.name }}</b> متاح {{ b.available }} (مستهلك {{ b.used }} · محجوز {{ b.reserved }}) </span></div>
    <div class="card"><h3>حركة الرصيد {{ new Date().getFullYear() }}</h3>
      <div v-if="!mine.ledger.length" class="empty">الرصيد المكتسب يُحتسب آليًا من تاريخ المباشرة</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>التاريخ</th><th>النوع</th><th>الحركة</th><th>الأيام</th></tr></thead>
        <tbody><tr v-for="l in mine.ledger" :key="l.id"><td>{{ l.occurred_on }}</td><td>{{ typeName(l.leave_type) }}</td><td>{{ l.note }}</td><td class="num" :class="l.days < 0 ? 'bad' : 'ok'">{{ l.days > 0 ? '+' : '' }}{{ l.days }}</td></tr></tbody></table></div>
    </div>
  </template>

  <template v-else-if="tab === 'report' && report">
    <div v-if="auth.isHr" class="notice info row" style="gap:10px;align-items:center;flex-wrap:wrap">
      <span><b>تعديل الأرصدة:</b> انقر على أي رصيد في الجدول لتعديله مباشرة، أو حمّل نموذج الأرصدة واكتب الرصيد المتبقي لكل موظف ثم ارفعه.</span>
      <span class="spacer" />
      <select v-model="balType" class="in" style="width:auto"><option v-for="tp in report.types" :key="tp.code" :value="tp.code">{{ tp.name }}</option></select>
      <button class="btn sec xs" @click="balanceTemplate"><Icon name="download" :size="14" /> تحميل نموذج الأرصدة</button>
      <label class="btn pri xs" style="cursor:pointer" :class="{ loading: busy }"><Icon name="upload" :size="14" /> رفع ملف الأرصدة<input type="file" accept=".xlsx,.xls,.csv" hidden @change="uploadBalances" /></label>
    </div>
    <div class="card">
      <div v-dt class="tw"><table>
        <thead><tr><th>{{ t('الموظف') }}</th><th>{{ t('الشركة') }}</th><th>الاستحقاق ({{ typeName(balType) }})</th><th>المستهلك</th><th v-for="tp in report.types" :key="tp.code">{{ tp.name }} المتاح</th></tr></thead>
        <tbody><tr v-for="r in report.rows" :key="r.employee.id">
          <td><RouterLink :to="`/employees/${r.employee.id}`">{{ r.employee.name }}</RouterLink><div class="tiny muted">{{ r.employee.emp_no }}</div></td><td>{{ r.company }}</td>
          <td class="num">{{ r.details?.[balType]?.entitled ?? '—' }}</td><td class="num">{{ r.details?.[balType]?.used ?? '—' }}</td>
          <td v-for="tp in report.types" :key="tp.code" class="num" :class="{ bad: r.balances[tp.code] < 0, editable: auth.isHr && !(cell && cell.id === r.employee.id && cell.type === tp.code) }" @click="startCell(r, tp.code)">
            <div v-if="cell && cell.id === r.employee.id && cell.type === tp.code" class="row" style="gap:4px;flex-wrap:nowrap" @click.stop>
              <input v-model.number="cell.value" type="number" step="0.5" class="in" style="width:80px" @keydown.enter="saveCell" @keydown.esc="cell = null" />
              <button class="btn pri xs" :class="{ loading: busy }" @click="saveCell"><Icon name="check" :size="13" /></button><button class="btn ghost xs" @click="cell = null"><Icon name="x" :size="13" /></button>
            </div>
            <template v-else>{{ Number(r.balances[tp.code]).toFixed(1) }} <Icon v-if="auth.isHr" name="edit" :size="12" class="pen" /></template>
          </td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <template v-else-if="tab === 'past'">
    <div class="card">
      <h3>إدخال إجازة سابقة <span class="sub">إجازات أخذها الموظف قبل تشغيل النظام، تُخصم من رصيد سنتها وتظهر في ملفه</span></h3>
      <div class="fr3">
        <div class="fg"><label>الموظف *</label><SearchSelect v-model="pf.employee_id" :options="staffOpts" placeholder="ابحث بالاسم أو الرقم" /></div>
        <div class="fg"><label>نوع الإجازة *</label><select v-model="pf.type" class="in"><option v-for="lt in auth.meta?.leave_types || []" :key="lt.code" :value="lt.code">{{ lt.name }}</option></select></div>
        <div class="fg"><label>عدد الأيام <span class="tiny muted">(يُحسب تلقائيًا، ويمكن تعديله)</span></label><input v-model.number="pf.days" type="number" min="0.5" step="0.5" class="in" :placeholder="pastDays ? String(pastDays) : 'تلقائي'" /></div>
        <div class="fg"><label>من *</label><DateInput v-model="pf.from" /></div>
        <div class="fg"><label>إلى *</label><DateInput v-model="pf.to" /></div>
        <div class="fg"><label>ملاحظة</label><input v-model.trim="pf.note" class="in" placeholder="اختياري" /></div>
      </div>
      <div class="row"><span class="spacer" /><button class="btn pri" :class="{ loading: busy }" :disabled="busy" @click="savePast"><Icon name="check" :size="15" /> حفظ وخصم من الرصيد</button></div>
    </div>
    <div class="card">
      <h3>الإجازات السابقة المسجلة</h3>
      <div v-if="!past.length" class="empty">لا إجازات سابقة مسجلة</div>
      <div v-else v-dt class="tw"><table>
        <thead><tr><th>الموظف</th><th>النوع</th><th>الفترة</th><th>الأيام</th><th>تاريخ الإدخال</th><th /></tr></thead>
        <tbody><tr v-for="l in past" :key="l.id">
          <td><RouterLink :to="`/employees/${l.employee_id}`">{{ l.employee?.name }}</RouterLink></td><td>{{ l.type_name }}</td>
          <td class="small">{{ (l.note || '').replace(/^[^:]*:\s*/, '') }}</td><td class="num">{{ Math.abs(l.days) }}</td><td class="num">{{ fmtD(l.created_at) }}</td>
          <td><button class="btn ghost xs bad" title="حذف وإرجاع الأيام للرصيد" @click="delPast(l)"><Icon name="trash" :size="14" /></button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <div v-else-if="tab === 'cal'" class="card">
    <div class="row mb"><MonthPicker v-model="month" @change="load" /></div>
    <div class="cal">
      <div v-for="(d, i) in DAYS_AR" :key="'h' + i" class="center b small muted">{{ d }}</div>
      <div v-for="i in firstDow" :key="'e' + i" />
      <div v-for="day in daysInMonth" :key="day" class="d">
        <span class="b muted">{{ day }}</span>
        <span v-for="l in leavesOn(day)" :key="l.id" class="lv" :style="l.status === 'pending' ? 'background:var(--warnbg);color:var(--warn)' : ''" :title="l.data.type_name">{{ l.employee?.name.split(' ')[0] }}</span>
      </div>
    </div>
    <div class="row mt small"><span class="pill info">معتمدة</span><span class="pill warn">بانتظار الاعتماد</span></div>
  </div>
  <NewRequest v-if="newReq" initial="leave" @close="newReq = false" @done="load" />
  <template v-if="tab === 'hol'">
    <div class="card">
      <div class="row mb" style="gap:8px;align-items:center"><h3 style="margin:0">العطلات الرسمية</h3><input v-model.number="hYear" type="number" class="in" style="width:100px" @change="load" /><span class="spacer" />
        <button v-if="auth.has('super', 'hr_mgr')" class="btn sec sm" title="يحسب عيد الفطر وعيد الأضحى من تقويم أم القرى، ويوم التأسيس واليوم الوطني مع تعويض ما يقع منها في العطلة الأسبوعية" @click="genHolidays"><Icon name="calendar" :size="14" /> توليد عطلات {{ hYear }} (أم القرى)</button>
        <button v-if="auth.has('super', 'hr_mgr')" class="btn pri sm" @click="hf = { name: '', kind: 'eid', from: '', to: '', company_id: null }"><Icon name="plus" :size="14" /> عطلة</button></div>
      <p class="small muted">عيد الفطر 4 أيام، عيد الأضحى 4 أيام، اليوم الوطني، يوم التأسيس (§58). العطلة داخل الإجازة لا تُحتسب منها، ويُضاف لكل عيد يوم الشركة الإضافي بحسب فئتها.</p>
      <div v-if="!hols.length" class="empty">لا عطلات مسجلة لهذه السنة</div>
      <div v-else class="tw"><table><thead><tr><th>العطلة</th><th>النوع</th><th>من</th><th>إلى</th><th>الأيام</th><th>الشركة</th><th /></tr></thead>
        <tbody><tr v-for="h in hols" :key="h.id"><td><b>{{ h.name }}</b></td><td class="small">{{ HKIND[h.kind] }}</td><td>{{ fmtD(h.from) }}</td><td>{{ fmtD(h.to) }}</td><td class="num">{{ h.days }}</td>
          <td class="small">{{ h.company_id ? (auth.meta?.companies || []).find((c) => c.id === h.company_id)?.name : 'كل الشركات' }}</td>
          <td><template v-if="auth.has('super', 'hr_mgr')"><button class="btn ghost xs" @click="hf = { ...h }"><Icon name="edit" :size="13" /></button><button class="btn ghost xs" @click="delHol(h)"><Icon name="trash" :size="13" /></button></template></td></tr></tbody></table></div>
    </div>
    <Modal v-if="hf" :title="hf.id ? 'تعديل عطلة' : 'عطلة رسمية'" @close="hf = null">
      <div class="fg"><label>الاسم *</label><input v-model="hf.name" class="in" placeholder="مثال: عيد الفطر 1448" /></div>
      <div class="fg"><label>النوع</label><select v-model="hf.kind" class="in"><option v-for="(l, k) in HKIND" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="fr"><div class="fg"><label>من *</label><DateInput v-model="hf.from" /></div><div class="fg"><label>إلى *</label><DateInput v-model="hf.to" :min="hf.from" /></div></div>
      <div class="fg"><label>الشركة</label><select v-model="hf.company_id" class="in"><option :value="null">كل الشركات</option><option v-for="c in auth.meta?.companies || []" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <template #foot><button class="btn sec" @click="hf = null">إلغاء</button><button class="btn pri" @click="saveHol">حفظ</button></template>
    </Modal>
  </template>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import NewRequest from '../components/NewRequest.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { curYM, DAYS_AR, fmtD, pad } from '../utils'
import { alertBox, confirmBox, promptBox } from '../dialog'
import { exportXlsx, readXlsx } from '../excel'
import SearchSelect from '../components/SearchSelect.vue'
import Modal from '../components/Modal.vue'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const tabs = computed(() => [auth.emp && ['mine', 'إجازاتي'], auth.isApprover && ['report', 'الأرصدة'], auth.isHr && ['past', 'إجازات سابقة'], ['cal', 'تقويم الفريق'], ['hol', 'العطلات الرسمية']].filter(Boolean))
// §58 official holidays: not counted inside a leave; the company's extra Eid days (by tier) follow each Eid automatically
const hols = ref([]); const hYear = ref(new Date().getFullYear()); const hf = ref(null)
const HKIND = { official: 'رسمية', eid: 'عيد (يُضاف إليه يوم الشركة حسب الفئة)', company: 'يوم للشركة', bridge: 'يوم الوصل (للفئتين الثانية والثالثة)' }
async function saveHol() {
  try { await api.post('/holidays', hf.value); hf.value = null; ui.toast('حُفظت العطلة', 'ok'); hols.value = await api.get('/holidays', { params: { year: hYear.value } }) } catch (e) { ui.toast(e.message, 'bad') }
}
async function delHol(h) {
  if (!(await confirmBox(`حذف «${h.name}»؟`, { confirmText: 'حذف' }))) return
  await api.delete(`/holidays/${h.id}`); hols.value = await api.get('/holidays', { params: { year: hYear.value } })
}
const tab = ref(auth.emp ? 'mine' : 'report')
const mine = ref(null)
const report = ref(null)
const cal = ref([])
const month = ref(curYM())
const newReq = ref(false)
const LT_ICON = { annual: 'leave', sick: 'sick', emergency: 'emergency', maternity: 'maternity', marriage: 'marriage', bereavement: 'bereavement', unpaid: 'money' }
const typeName = (c) => auth.meta?.leave_types?.find((x) => x.code === c)?.name || c
const daysInMonth = computed(() => { const [y, m] = month.value.split('-').map(Number); return new Date(y, m, 0).getDate() })
const firstDow = computed(() => new Date(month.value + '-01T00:00:00').getDay())
const leavesOn = (day) => { const d = `${month.value}-${pad(day)}`; return cal.value.filter((l) => l.data.from <= d && l.data.to >= d) }
async function load() {
  if (tab.value === 'mine') mine.value = await api.get('/leaves/balances')
  else if (tab.value === 'report') report.value = await api.get('/leaves/report', { params: { company_id: ui.company || undefined } })
  else if (tab.value === 'past') {
    const [p, st] = await Promise.all([api.get('/leaves/past', { params: { company_id: ui.company || undefined } }), staffOpts.value.length ? null : api.get('/employees/options')])
    past.value = p
    if (st) staffOpts.value = st.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no }))
  }
  else if (tab.value === 'hol') hols.value = await api.get('/holidays', { params: { year: hYear.value } })
  else cal.value = await api.get('/leaves/calendar', { params: { month: month.value } })
}
async function genHolidays() {
  try {
    const r = await api.post('/holidays/generate', { year: hYear.value })
    ui.toast(r.added.length ? `أُضيفت ${r.added.length} عطلة — راجع التواريخ مع إعلان رؤية الهلال` : 'عطلات هذه السنة مسجلة مسبقًا', 'ok', 7000)
    hols.value = await api.get('/holidays', { params: { year: hYear.value } })
  } catch (e) { ui.toast(e.message, 'bad') }
}
// balances: edit a cell in place, or download / upload a sheet of actual balances
const balType = ref('annual'); const cell = ref(null)
function startCell(r, type) {
  if (!auth.isHr || (cell.value && cell.value.id === r.employee.id && cell.value.type === type)) return
  cell.value = { id: r.employee.id, type, value: Number(r.balances[type]) }
}
async function saveCell() {
  const c = cell.value
  if (c.value === '' || c.value == null || isNaN(c.value)) return ui.toast('أدخل رقمًا', 'warn')
  busy.value = true
  try { await api.post(`/leaves/balances/${c.id}/set`, { type: c.type, available: c.value }); cell.value = null; ui.toast('حُدّث الرصيد', 'ok'); load() } finally { busy.value = false }
}
const BAL_COLS = ['رقم الموظف', 'اسم الموظف', 'الشركة', 'الاستحقاق', 'المستهلك', 'المتاح حاليًا', 'الرصيد المتبقي', 'المستهلك الفعلي (اختياري)']
async function balanceTemplate() {
  const rows = report.value.rows.map((r) => [r.employee.emp_no, r.employee.name, r.company, r.details?.[balType.value]?.entitled ?? '', r.details?.[balType.value]?.used ?? '', r.balances[balType.value], '', ''])
  await exportXlsx(`نموذج أرصدة ${typeName(balType.value)} ${new Date().getFullYear()}`, [BAL_COLS, ...rows], { kind: 'نموذج الأرصدة', category: 'forms' })
}
async function uploadBalances(ev) {
  const file = ev.target.files[0]; ev.target.value = ''
  if (!file) return
  const num = (v) => { const x = String(v ?? '').replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).trim(); return x === '' || isNaN(Number(x)) ? null : Number(x) }
  const rows = (await readXlsx(file)).map((r) => ({ emp_no: String(r['رقم الموظف'] ?? '').trim() || null, name: String(r['اسم الموظف'] ?? '').trim() || null, available: num(r['الرصيد المتبقي']), used: num(r['المستهلك الفعلي (اختياري)']) }))
    .filter((r) => (r.emp_no || r.name) && (r.available !== null || r.used !== null))
  if (!rows.length) return alertBox('لم أجد أرصدة في الملف. اكتب الرقم في عمود «الرصيد المتبقي» (أو «المستهلك الفعلي») لكل موظف تريد تعديله.', { icon: 'warning' })
  const reason = await promptBox(`سيُحدَّث رصيد «${typeName(balType.value)}» لـ ${rows.length} موظف. اكتب سبب التعديل (يُسجَّل في سجل التدقيق):`, { text: 'أرصدة فعلية قبل تشغيل النظام', multiline: false })
  if (!reason) return
  busy.value = true
  try {
    const r = await api.post('/leaves/balances/import', { type: balType.value, reason, rows })
    const fails = r.results.filter((x) => !x.ok)
    const nl = String.fromCharCode(10)
    await alertBox(`حُدّث ${r.done} رصيد.` + (fails.length ? `${nl}${nl}لم يُحدَّث ${fails.length}:${nl}` + fails.map((x) => `• ${x.row}: ${x.msg}`).join(nl) : ''), { icon: fails.length ? 'warning' : 'success', title: 'نتيجة رفع الأرصدة' })
    load()
  } finally { busy.value = false }
}
// leaves taken before go-live
const past = ref([]); const staffOpts = ref([]); const busy = ref(false)
const pf = ref({ employee_id: null, type: 'annual', from: '', to: '', days: null, note: '' })
const pastDays = computed(() => {
  const f = pf.value
  if (!f.from || !f.to || f.to < f.from) return 0
  const a = new Date(f.from + 'T00:00:00'); const b = new Date(f.to + 'T00:00:00')
  while (a <= b && [5, 6].includes(a.getDay())) a.setDate(a.getDate() + 1)
  while (b >= a && [5, 6].includes(b.getDay())) b.setDate(b.getDate() - 1)
  return a > b ? 0 : Math.round((b - a) / 86400000) + 1
})
async function savePast() {
  const f = pf.value
  if (!f.employee_id || !f.type || !f.from || !f.to) return ui.toast('اختر الموظف والنوع والفترة', 'warn')
  busy.value = true
  try {
    const r = await api.post('/leaves/past', { ...f, days: f.days || undefined })
    ui.toast(`سُجّلت وخُصم ${Math.abs(r.entry.days)} يوم. الرصيد المتاح الآن ${r.balance.available}`, 'ok', 6000)
    pf.value = { ...f, from: '', to: '', days: null, note: '' }
    load()
  } catch { /* shown */ } finally { busy.value = false }
}
async function delPast(l) {
  if (!(await confirmBox(`حذف الإجازة السابقة لـ«${l.employee?.name}» وإرجاع ${Math.abs(l.days)} يوم للرصيد؟`))) return
  await api.delete(`/leaves/past/${l.id}`)
  ui.toast('حُذفت وأُعيدت الأيام للرصيد', 'ok'); load()
}
onMounted(load)
</script>

<style scoped>
td.editable { cursor: pointer; }
td.editable:hover { background: var(--soft, #F1F3F9); }
.pen { opacity: .35; }
td.editable:hover .pen { opacity: .9; color: var(--blue, #1C84C6); }
</style>
