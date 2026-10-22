<template>
  <div class="page-h">
    <div><h1>{{ t('التطوير والتدريب') }}</h1><p>نقاط التطوير الشهرية (اللائحة §78): المستهدف 100 نقطة = الدرجة الكاملة لمحور التطوير، والحد 150 نقطة = 30 درجة · يعتمد المشرف المباشر الإثبات فتُحتسب النقاط تلقائيًا · الميزانية من فئة الشركة</p></div>
    <div class="acts"><button v-if="auth.emp" class="btn sec sm" @click="$router.push({ path: '/requests', query: { new: 'dev_program' } })"><Icon name="training" :size="16" /> طلب برنامج تطويري</button><button class="btn pri sm" @click="openForm()"><Icon name="plus" :size="16" /> سجل نشاطًا تطويريًا</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <template v-if="tab === 'mine'">
    <div v-if="mine" class="grid g4 mb">
      <Kpi :label="`نقاط ${fmtYM(mine.points.period)}`" :value="mine.points.total" :hint="`المستهدف ${mine.points.target} · الحد ${mine.points.max}${mine.points.carry ? ` · مرحّل ${mine.points.carry}` : ''}`" :color="mine.points.total >= 100 ? 'g' : mine.points.total >= 50 ? 'b' : 'r'" />
      <Kpi label="المستوى" :value="mine.points.level_label" :hint="LEVEL_HINT[mine.points.level]" :color="{ gold: 'g', silver: 'g', bronze: 'g', below: 'b', alert: 'r' }[mine.points.level]" />
      <Kpi label="ميزانية التطوير السنوية" :value="money(mine.budget || 0, 0)" :hint="`المصروف ${money(mine.spent, 0)} ر.س`" :color="mine.budget && mine.spent > mine.budget ? 'r' : 'g'" />
      <Kpi label="ساعات موثقة" :value="mine.records.filter((r) => r.status === 'verified').reduce((s, r) => s + Number(r.hours || 0), 0)" color="p" />
    </div>
    <div v-if="mine?.recognition" class="card">
      <h3>التميز في التطوير <span class="sub">اللائحة §79</span></h3>
      <div class="grid g3">
        <div><b>التطوير السنوي {{ mine.recognition.year }}</b><div class="small">{{ mine.recognition.points.toLocaleString('en') }} نقطة · {{ mine.recognition.gold_months }} أشهر ذهبية</div>
          <div class="tiny" :class="mine.recognition.qualified ? 'ok' : 'muted'">{{ mine.recognition.qualified ? 'مستحق: أولوية في ميزانية التدريب ويوم تعلم إضافي مدفوع في السنة التالية' : mine.recognition.rule }}</div></div>
        <div v-for="k in ['current', 'previous']" :key="k"><b>نجم التطوير — الربع {{ mine.stars[k].quarter }}/{{ mine.stars[k].year }}{{ k === 'current' ? ' (حتى الآن)' : '' }}</b>
          <div v-if="!mine.stars[k].stars.length" class="tiny muted">لا نقاط بعد</div>
          <div v-for="st in mine.stars[k].stars" :key="st.employee.id" class="small">{{ st.employee.name }} — {{ st.points }} نقطة<span v-if="st.employee.id === auth.emp?.id" class="pill ok tiny">أنت</span></div></div>
      </div>
    </div>
    <div v-if="mine" class="card">
      <h3>جدول النقاط <span class="sub">يُحتسب النشاط الواحد مرة واحدة · ما يزيد على 150 نقطة يُرحَّل إلى الشهر التالي بحد 50</span></h3>
      <div class="row" style="gap:6px;flex-wrap:wrap"><span v-for="a in mine.activities" :key="a.code" class="pill gray">{{ a.name }} — <b>{{ a.meta?.points }}</b>{{ a.meta?.cap ? ` (حد ${a.meta.cap}/شهر)` : '' }}</span></div>
    </div>
    <div class="card">
      <div v-if="!mine?.records.length" class="empty"><div class="ic"><Icon name="training" :size="40" :stroke="1.4" /></div>لا سجلات تطوير بعد</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>النشاط</th><th>النوع</th><th>الفترة</th><th>الساعات</th><th>النقاط</th><th>الإثبات</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="r in mine.records" :key="r.id">
          <td><b>{{ r.program }}</b><div class="tiny muted">{{ r.provider }}<span v-if="r.notes"> · {{ r.notes }}</span></div><div v-if="r.applied_note" class="tiny muted">التطبيق في العمل: {{ r.applied_note }}</div><div v-if="r.summary" class="tiny muted">الملخص: {{ r.summary }}</div><span v-if="r.summary_late" class="pill bad tiny" title="يُقدَّم ملخص ما تعلّمته خلال 3 أيام عمل من انتهاء البرنامج (§81)">تأخر الملخص</span></td><td class="small">{{ actName(r.activity) }}</td><td class="num small">{{ fmtD(r.from) }} → {{ fmtD(r.to) }}</td><td>{{ r.hours }}</td>
          <td class="num"><b v-if="r.status === 'verified'">{{ r.points }}</b><span v-else class="muted">{{ r.points || '—' }}</span><div v-if="r.points_month" class="tiny muted">{{ r.points_month }}</div></td>
          <td><a v-if="r.certificate_path" href="#" class="tiny" @click.prevent="fileUrl(r.certificate_path)"><Icon name="download" :size="12" /> عرض</a><span v-else class="tiny muted">—</span></td>
          <td><span class="pill" :class="COLOR[r.status]">{{ r.status_label }}</span></td>
          <td><button v-if="r.status === 'planned'" class="btn okb xs" @click="complete(r)">أكملته — ارفع الإثبات</button></td>
        </tr></tbody></table></div>
    </div>
  </template>

  <template v-else-if="tab === 'plan' && plan">
    <div class="card">
      <div class="row mb" style="gap:8px;align-items:center"><h3 style="margin:0">خطة التطوير الفردية</h3><input v-model.number="planYear" type="number" class="in" style="width:100px" @change="load" /><span class="pill" :class="PLAN_COLOR[plan.status]">{{ plan.status_label }}</span></div>
      <p class="small muted">تُعد في بداية السنة وتتفق عليها مع مشرفك المباشر: الفجوات والمسارات المستهدفة، ونشاط تطويري واحد على الأقل كل شهر مع تطبيقه ودليله (§77).</p>
      <div v-if="plan.manager_note" class="notice warn small">ملاحظة المشرف: {{ plan.manager_note }}</div>
      <div class="fg"><label>الفجوات والمهارات المستهدفة *</label><textarea v-model="plan.gaps" class="in" rows="3" :disabled="plan.status === 'approved'" /></div>
      <div class="tw"><table><thead><tr><th>النشاط أو المسار</th><th style="width:140px">الشهر</th><th>كيف ستطبّقه</th><th /></tr></thead>
        <tbody><tr v-for="(t, i) in plan.tracks" :key="i">
          <td><input v-model="t.title" class="in" :disabled="plan.status === 'approved'" placeholder="مثال: دورة إدارة المشاريع" /></td>
          <td><MonthPicker v-model="t.month" :disabled="plan.status === 'approved'" :ahead="12" /></td>
          <td><input v-model="t.apply" class="in" :disabled="plan.status === 'approved'" /></td>
          <td><button v-if="plan.status !== 'approved'" class="btn ghost xs" @click="plan.tracks.splice(i, 1)"><Icon name="x" :size="13" /></button></td>
        </tr></tbody></table></div>
      <div v-if="plan.status !== 'approved'" class="row mt" style="gap:8px">
        <button class="btn ghost sm" @click="plan.tracks.push({ title: '', month: '', apply: '' })"><Icon name="plus" :size="14" /> نشاط</button><span class="spacer" />
        <button class="btn sec sm" :disabled="busy" @click="savePlan(false)">حفظ مسودة</button><button class="btn pri sm" :class="{ loading: busy }" :disabled="busy" @click="savePlan(true)">إرسال لاعتماد المشرف</button>
      </div>
    </div>
  </template>

  <template v-else-if="tab === 'plans'">
    <div class="card">
      <div class="row mb" style="gap:8px;align-items:center"><h3 style="margin:0">خطط التطوير السنوية</h3><input v-model.number="planYear" type="number" class="in" style="width:100px" @change="load" /></div>
      <div v-if="!plans.length" class="empty">لا موظفين في نطاقك</div>
      <div v-else class="tw"><table><thead><tr><th>الموظف</th><th>الحالة</th><th>الأنشطة</th><th /></tr></thead>
        <tbody><tr v-for="p in plans" :key="p.employee.id"><td><b>{{ p.employee.name }}</b><div class="tiny muted">{{ p.employee.emp_no }}</div></td>
          <td><span class="pill" :class="PLAN_COLOR[p.status]">{{ p.status_label }}</span></td><td class="num">{{ p.tracks?.length || 0 }}</td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><button v-if="p.id" class="btn ghost xs" @click="viewPlan = p">عرض</button>
            <template v-if="p.status === 'submitted'"><button class="btn okb xs" @click="decidePlan(p, 'approve')">اعتماد</button><button class="btn ghost xs" @click="decidePlan(p, 'return')">إعادة</button></template></td></tr></tbody></table></div>
    </div>
    <Modal v-if="viewPlan" :title="`خطة ${viewPlan.employee?.name} — ${viewPlan.year}`" @close="viewPlan = null">
      <p class="small"><b>الفجوات:</b> {{ viewPlan.gaps }}</p>
      <ul class="small"><li v-for="(t, i) in viewPlan.tracks" :key="i"><b>{{ t.title }}</b> <span v-if="t.month" class="muted">({{ t.month }})</span><span v-if="t.apply"> — {{ t.apply }}</span></li></ul>
      <template #foot><button class="btn sec" @click="viewPlan = null">إغلاق</button></template>
    </Modal>
  </template>

  <template v-else-if="tab === 'board'">
    <div v-if="board" class="card">
      <h3>نجم التطوير — الربع {{ board.stars.quarter }}/{{ board.stars.year }} <span class="sub">صاحب أعلى رصيد من نقاط التطوير في كل شركة خلال الربع (§79) — شهادة تقدير وإعلان على مستوى المجموعة</span><span class="acts"><MonthPicker v-model="boardPeriod" @change="load" /></span></h3>
      <div v-if="!board.stars.stars.length" class="small muted">لا نقاط تطوير موثقة في هذا الربع</div>
      <div class="row" style="gap:6px;flex-wrap:wrap"><span v-for="st in board.stars.stars" :key="st.employee.id" class="pill ok">{{ st.company }}: <b>{{ st.employee.name }}</b> — {{ st.points }} نقطة</span></div>
    </div>
    <div v-if="board" class="card">
      <h3>التطوير السنوي {{ boardPeriod.slice(0, 4) }} <span class="sub">1,500 نقطة فأكثر أو المستوى الذهبي 6 أشهر فأكثر: أولوية في ميزانية التدريب ويوم تعلم إضافي مدفوع، ومن عناصر الترشيح للترقية</span></h3>
      <div v-if="!board.recognized.length" class="small muted">لم يستوفِ أحد المعيار بعد</div>
      <div class="row" style="gap:6px;flex-wrap:wrap"><span v-for="r in board.recognized" :key="r.employee.id" class="pill navy">{{ r.employee.name }} — {{ r.year_points }} نقطة · {{ r.gold_months }} ذهبي</span></div>
    </div>
    <div v-if="board" class="card">
      <h3>لوحة نقاط التطوير — {{ fmtYM(board.period) }}</h3>
      <div v-dt class="tw"><table><thead><tr><th>#</th><th>الموظف</th><th>الشركة</th><th>نقاط الشهر</th><th>المستوى</th><th>نقاط السنة</th><th>أشهر ذهبية</th></tr></thead>
        <tbody><tr v-for="(r, i) in board.rows" :key="r.employee.id"><td class="num">{{ i + 1 }}</td><td><b>{{ r.employee.name }}</b></td><td class="small">{{ r.company }}</td><td class="num"><b>{{ r.total }}</b></td>
          <td><span class="pill" :class="{ gold: 'ok', silver: 'ok', bronze: 'ok', below: 'info', alert: 'bad' }[r.level]">{{ r.level_label }}</span></td><td class="num">{{ r.year_points }}</td><td class="num">{{ r.gold_months }}</td></tr></tbody></table></div>
    </div>
  </template>

  <template v-else>
    <div class="card">
      <div class="row mb"><select v-model="status" class="in" style="width:auto" @change="load"><option value="">كل الحالات</option><option value="pending">بانتظار التحقق</option><option value="planned">مخطط</option><option value="verified">موثَّق</option><option value="rejected">مرفوض</option></select></div>
      <div v-if="!all.length" class="empty">لا سجلات</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>النشاط</th><th>النوع</th><th>الفترة</th><th>الساعات</th><th>النقاط</th><th>الإثبات</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="r in all" :key="r.id">
          <td><b>{{ r.employee?.name }}</b><span class="tiny muted">{{ r.company }}</span></td><td>{{ r.program }}<div class="tiny muted">{{ r.provider }}</div></td><td class="small">{{ actName(r.activity) }}</td><td class="num small">{{ fmtD(r.from) }} → {{ fmtD(r.to) }}</td><td>{{ r.hours }}</td><td class="num">{{ r.status === 'verified' ? r.points : '—' }}</td>
          <td><a v-if="r.certificate_path" href="#" class="tiny" @click.prevent="fileUrl(r.certificate_path)"><Icon name="download" :size="12" /> عرض</a><span v-else class="tiny muted">—</span></td>
          <td><span class="pill" :class="COLOR[r.status]">{{ r.status_label }}</span></td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><template v-if="r.status === 'pending' && canVerify(r)"><button class="btn okb xs" @click="verify(r, 'verified')">اعتماد الإثبات</button><button class="btn ghost xs bad" @click="verify(r, 'rejected')">رفض</button></template></td>
        </tr></tbody></table></div>
    </div>
  </template>

  <Modal v-if="f" :title="f.employee_id ? 'إسناد برنامج لموظف' : 'نشاط تطويري'" @close="f = null">
    <div v-if="auth.isApprover" class="fg"><label>الموظف (اتركه فارغًا لتسجيل نشاط لنفسك)</label><SearchSelect v-model="f.employee_id" :options="empOptions" /></div>
    <div class="fr">
      <div class="fg" style="grid-column:span 2"><label>نوع النشاط *</label><select v-model="f.activity" class="in"><option v-for="a in activities" :key="a.code" :value="a.code">{{ a.name }} — {{ a.meta?.points }} نقطة</option></select></div>
      <div class="fg" style="grid-column:span 2"><label>اسم البرنامج أو النشاط *</label><input v-model="f.program" class="in" placeholder="مثال: أساسيات إدارة المشاريع" /></div>
      <div class="fg"><label>الجهة</label><input v-model="f.provider" class="in" /></div>
      <div class="fg"><label>الساعات</label><input v-model.number="f.hours" type="number" min="0" class="in" /></div>
      <div class="fg"><label>من</label><DateInput v-model="f.from" /></div><div class="fg"><label>إلى (تاريخ الإكمال)</label><DateInput v-model="f.to" :min="f.from" /></div>
      <div class="fg"><label>التكلفة (ر.س)</label><input v-model.number="f.cost" type="number" min="0" class="in" /></div>
      <div class="fg"><label>الإثبات <span class="tiny muted">شهادة أو ملخص أو تقرير</span></label><input type="file" class="in" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" @change="f.certificate = $event.target.files[0]" /></div>
    </div>
    <div class="fg"><label>ملخص / ما تعلمته</label><textarea v-model="f.notes" class="in" rows="2" /></div>
    <div class="fg"><label>التطبيق في العمل <span class="tiny muted">(اختياري) كيف ستطبق ما تعلمته أو طبقته فعلًا</span></label><textarea v-model="f.applied_note" class="in" rows="2" /></div>
    <div class="tiny muted mb">الساعات تحدد فئة الدورة: أقل من 4 ساعات 15 نقطة · من 4 إلى 10 ساعات 30 · أكثر من 10 إلى 30 ساعة 60 · أكثر من 30 ساعة 100. لا يُحتسب النشاط الواحد إلا مرة واحدة.</div>
    <div class="notice info small">يُرسل النشاط إلى مشرفك المباشر لاعتماد الإثبات، وتُحتسب النقاط في شهر الإكمال.</div>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">حفظ</button></template>
  </Modal>
</template>

<script setup>
import { promptBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { fileUrl } from '../api'
import DateInput from '../components/DateInput.vue'
import { fmtD, fmtYM } from '../utils'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { money } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const COLOR = { planned: 'info', pending: 'warn', verified: 'ok', rejected: 'bad' }
const tabs = computed(() => [auth.emp && ['mine', 'سجلي'], auth.emp && ['plan', 'خطتي السنوية'], auth.isApprover && auth.canView('DEV') && ['plans', 'خطط الفريق'], auth.isApprover && auth.canView('DEV') && ['all', 'سجلات الفريق'], auth.isApprover && auth.canView('DEV') && ['board', 'لوحة التطوير ونجم الربع']].filter(Boolean))
// §77: individual development plan for the year, agreed with the direct manager
const plan = ref(null); const plans = ref([]); const planYear = ref(new Date().getFullYear())
const PLAN_COLOR = { none: 'gray', draft: 'info', submitted: 'warn', approved: 'ok', returned: 'bad' }
async function savePlan(submit) {
  const p = plan.value
  if (!p.gaps?.trim()) return ui.toast('اكتب الفجوات أو المهارات المستهدفة', 'warn')
  const tracks = (p.tracks || []).filter((t) => t.title?.trim())
  if (!tracks.length) return ui.toast('أضف نشاطًا تطويريًا واحدًا على الأقل', 'warn')
  busy.value = true
  try { plan.value = await api.post('/dev-plans', { year: planYear.value, gaps: p.gaps, tracks, submit }); ui.toast(submit ? 'أُرسلت الخطة لاعتماد مشرفك' : 'حُفظت المسودة', 'ok') } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function decidePlan(p, action) {
  const note = action === 'return' ? await promptBox('سبب الإعادة للتعديل:') : null
  if (action === 'return' && !note) return
  await api.post(`/dev-plans/${p.id}/decide`, { action, note }); ui.toast(action === 'approve' ? 'اعتُمدت الخطة' : 'أُعيدت الخطة', 'ok'); load()
}
const viewPlan = ref(null)
const tab = ref(auth.emp ? 'mine' : 'all')
const board = ref(null); const boardPeriod = ref(new Date().toISOString().slice(0, 7))
const mine = ref(null); const all = ref([]); const status = ref(''); const f = ref(null); const busy = ref(false); const emps = ref([])
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const kindName = (k) => (auth.meta?.lookups?.dev_kinds || []).find((x) => x.code === k)?.name || k || '—'
const LEVEL_HINT = { gold: '150 نقطة — الدرجة القصوى (30) وإشادة في لوحة المتميزين', silver: '125–149 — درجة تزيد على الكاملة', bronze: '100–124 — الدرجة الكاملة', below: '50–99 — درجة جزئية', alert: 'أقل من 50 — علامة برتقالية وجلسة توجيه' }
const activities = computed(() => mine.value?.activities || auth.meta?.lookups?.dev_activities || [])
const actName = (c) => activities.value.find((a) => a.code === c)?.name || c || '—'
const canVerify = (r) => auth.has('super') || auth.isHr || (auth.emp && r.employee?.manager_id === auth.emp.id)
async function load() {
  if (tab.value === 'plan') { const p = await api.get('/dev-plans/mine', { params: { year: planYear.value } }); if (!p.tracks?.length) p.tracks = [{ title: '', month: '', apply: '' }]; plan.value = p; return }
  if (tab.value === 'plans') { plans.value = await api.get('/dev-plans', { params: { year: planYear.value } }); return }
  if (tab.value === 'mine') mine.value = await api.get('/development/mine')
  else if (tab.value === 'board') board.value = await api.get('/development', { params: { board: 1, period: boardPeriod.value, company_id: ui.company || undefined } })
  else all.value = await api.get('/development', { params: { status: status.value || undefined } })
}
function _unusedCatalog(code) { const c = (auth.meta?.lookups?.dev_catalog || []).find((x) => x.code === code); if (!c) return; f.value.program = c.name; f.value.provider = c.meta?.provider || ''; f.value.hours = Number(c.meta?.hours || 0); f.value.cost = Number(c.meta?.cost || 0) }
async function openForm() {
  if (!mine.value && auth.emp) mine.value = await api.get('/development/mine')
  f.value = { employee_id: null, activity: activities.value[0]?.code || null, program: '', provider: '', kind: (auth.meta?.lookups?.dev_kinds || [])[0]?.code, hours: 0, cost: 0, certificate: null }
  if (auth.isApprover && !emps.value.length) emps.value = await api.get('/employees/options')
}
async function save() {
  if (!f.value.program?.trim()) return ui.toast('اسم البرنامج إلزامي', 'warn')
  busy.value = true
  const fd = new FormData()
  Object.entries(f.value).forEach(([k, v]) => { if (v !== null && v !== '' && v !== undefined) fd.append(k, v) })
  try { await api.post('/development', fd, { headers: { 'Content-Type': 'multipart/form-data' } }); ui.toast('تم الحفظ وأُرسل للاعتماد', 'ok'); f.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function complete(r) {
  let summary = null
  if (r.source === 'assigned') { // §81: what you learned and how you will apply it, within 3 working days of the end
    summary = await promptBox('ملخص ما تعلمته والتطبيق المتوقع في عملك (§81):', { multiline: true, confirmText: 'التالي: رفع الإثبات' })
    if (!summary) return
  }
  const inp = document.createElement('input'); inp.type = 'file'; inp.accept = '.pdf,.jpg,.jpeg,.png,.doc,.docx'
  inp.onchange = async () => { const fd = new FormData(); if (inp.files[0]) fd.append('certificate', inp.files[0]); if (summary) fd.append('summary', summary); await api.post(`/development/${r.id}/complete`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }); ui.toast('أُرسل الإثبات لاعتماد مشرفك', 'ok'); load() }
  inp.click()
}
async function verify(r, decision) { const note = decision === 'rejected' ? await promptBox('سبب الرفض:') : null; if (decision === 'rejected' && !note) return; await api.post(`/development/${r.id}/verify`, { decision, note }); ui.toast('تم', 'ok'); load() }
onMounted(load)
</script>
