<template>
  <div v-if="!rv"><div v-for="i in 4" :key="i" class="skel" /></div>
  <template v-else>
    <div class="page-h">
      <div>
        <h1>تقرير {{ fmtYM(rv.period) }} — {{ rv.employee?.name }}</h1>
        <p class="row" style="gap:6px;flex-wrap:wrap">
          <span class="pill" :class="STATUS_COLOR[rv.status]">{{ rv.status_label }}</span>
          <span v-if="rv.late" class="pill bad">متأخر</span>
          <span v-if="rv.preview?.parts?.report?.after_month_end" class="pill bad">رُفع بعد نهاية الشهر (−10)</span>
          <span v-if="rv.first_submitted_at" class="tiny muted">أول رفع {{ fmtDT(rv.first_submitted_at) }}</span>
          <span v-if="rv.preview?.parts?.disc?.coop_pending" class="pill warn">التعاون بانتظار تقدير المشرف</span>
          <span v-if="rv.can_review" class="pill info">مرحلتك: {{ ROLE[rv.review_role] }}</span>
          <span class="tiny muted">رقم الموظف {{ rv.employee?.emp_no }} · {{ rv.items_total }} مهام</span>
        </p>
      </div>
      <div class="acts">
        <button class="btn sec sm" :class="{ loading: dl }" @click="download"><Icon name="download" :size="15" /> تحميل Excel</button>
        <button class="btn ghost sm" @click="print"><Icon name="printer" :size="15" /> طباعة</button>
      </div>
    </div>

    <div v-if="rv.preview" class="grid g5 mb">
      <Kpi label="الإنجاز" :value="rv.preview.axes.achieve" color="b" />
      <Kpi label="الحضور" :value="rv.preview.exempt_attendance ? 'معفى' : rv.preview.axes.attend" color="g" />
      <Kpi label="التطوير" :value="rv.preview.axes.dev" color="p" />
      <Kpi label="الانضباط" :value="rv.preview.axes.disc" color="y" />
      <Kpi label="الدرجة" :value="rv.preview.score" :hint="rv.preview.grade.label" :color="rv.preview.grade.pass ? 'g' : 'r'" />
    </div>

    <div class="card">
      <h3>منجزات الشهر <span class="sub">مرتبة حسب المحاور كما في النموذج المعتمد</span></h3>
      <ReportItems :items="rv.items" :mode="rv.can_review && rv.review_role === 'mgr' ? 'review' : 'view'" />
    </div>

    <div class="card">
      <h3>التطوير المنجز خلال الشهر <span class="sub">يُدرج تلقائيًا من سجل التطوير بعد اعتماد المشرف (§35/§78) · {{ rv.dev_points?.total ?? 0 }} / {{ rv.dev_points?.target ?? 100 }} نقطة{{ rv.dev_points?.carry ? ` (منها ${rv.dev_points.carry} مرحّلة)` : '' }} · المستوى: {{ rv.dev_points?.level_label || '—' }}</span></h3>
      <div v-if="!rv.dev_records?.length" class="small muted">لا أنشطة تطوير معتمدة في هذا الشهر — سجّلها من شاشة «التطوير والتدريب».</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>النشاط أو البرنامج</th><th>الجهة</th><th>النوع</th><th>الساعات</th><th>الحالة</th><th>النقاط</th></tr></thead>
        <tbody><tr v-for="d in rv.dev_records" :key="d.id"><td><b>{{ d.program }}</b></td><td>{{ d.provider || '—' }}</td><td class="small">{{ d.activity_name || d.activity }}</td><td>{{ d.hours || '—' }}</td><td><span class="pill" :class="d.counted ? 'ok' : 'warn'">{{ d.status_label }}</span></td><td class="num"><b>{{ d.counted ? d.points : '—' }}</b></td></tr></tbody></table></div>
      <div v-if="rv.dev?.notes" class="small muted mt" style="white-space:pre-line">{{ rv.dev.notes }}</div>
    </div>

    <div v-if="rv.can_adjust || rv.adjust_reason" class="card">
      <h3>تعديل بعد الاعتماد</h3>
      <div v-if="rv.adjust_reason" class="notice info tiny">آخر تعديل: {{ rv.hr_bonus }} نقاط — {{ rv.adjust_reason }}</div>
      <div v-if="rv.can_adjust" class="fr">
        <div class="fg"><label>النقاط الإضافية (0–10)</label><input v-model.number="adj.hr_bonus" type="number" min="0" max="10" class="in" /></div>
        <div class="fg"><label>سبب التعديل *</label><input v-model="adj.reason" class="in" /></div>
        <div class="fg" style="align-self:end"><button class="btn sec" :class="{ loading: busy }" @click="adjust">حفظ التعديل وإعادة النشر</button></div>
      </div>
    </div>

    <div v-if="empOwn.length && (rv.can_review && rv.review_role === 'mgr' || empOwn.some((i) => i.mgr_approved != null))" class="card">
      <h3>البنود الشخصية للموظف <span class="sub">حتى 3 بنود لا يزيد وزن كل منها على 15، ولا تُحتسب إلا بموافقة المشرف المباشر (§35)</span></h3>
      <div class="tw"><table><thead><tr><th>البند</th><th>المستهدف</th><th>مؤشر النجاح</th><th>الوزن</th><th>نسبة الموظف</th><th>قرار المشرف</th></tr></thead>
        <tbody><tr v-for="it in empOwn" :key="it.id"><td><b>{{ it.title }}</b></td><td class="small">{{ it.target || '—' }}</td><td class="small">{{ it.indicator || '—' }}</td><td class="num">{{ it.weight }}</td><td class="num">{{ it.pct ?? '—' }}%</td>
          <td><select v-if="rv.can_review && rv.review_role === 'mgr'" v-model="it.approved" class="in" style="min-height:30px;padding:2px 6px"><option :value="null" disabled>اختر…</option><option :value="true">موافقة — يُحتسب</option><option :value="false">رفض — لا يُحتسب</option></select>
            <span v-else class="pill" :class="it.mgr_approved ? 'ok' : it.mgr_approved === false ? 'bad' : 'gray'">{{ it.mgr_approved ? 'موافق عليه' : it.mgr_approved === false ? 'مرفوض' : 'بانتظار المشرف' }}</span></td></tr></tbody></table></div>
    </div>

    <div v-if="rv.can_review && rv.review_role === 'mgr'" class="card">
      <h3>المستهدفات <span class="sub">للمشرف المباشر تعديل المستهدف المرحَّل لهذا الشهر ومستهدفات الشهر القادم التي تنتقل تلقائيًا للتقرير التالي (§35)</span></h3>
      <div class="tw"><table><thead><tr><th>البند</th><th>مستهدف هذا الشهر</th><th>مستهدفات الشهر القادم</th></tr></thead>
        <tbody><tr v-for="it in rv.items" :key="it.id"><td><b>{{ it.title }}</b><span v-if="it.carried_from" class="pill gray tiny">مرحَّل</span></td>
          <td><textarea v-model="it.target" class="in" rows="1" /></td><td><textarea v-model="it.next_target" class="in" rows="1" /></td></tr></tbody></table></div>
    </div>

    <div v-if="rv.can_review" class="card review-box">
      <h3>المراجعة — {{ ROLE[rv.review_role] }}</h3>
      <div class="fr">
        <div v-if="rv.review_role === 'mgr'" class="fg"><label>التعاون (0–{{ rv.limits.coop }}) * <span class="tiny muted">تقدير المشرف من وقائع موثقة (§71)</span></label><input v-model.number="rvf.coop" type="number" min="0" :max="rv.limits.coop" step="0.5" class="in" /></div>
        <template v-if="rv.review_role === 'hr'">
          <div class="fg"><label>تقدير الموارد البشرية للتطوير (0–{{ rv.limits.hr_score }})</label><input v-model.number="rvf.hr_score" type="number" min="0" :max="rv.limits.hr_score" class="in" /></div>
          <div class="fg"><label>التفاعل (0–{{ rv.limits.interaction }})</label><input v-model.number="rvf.interaction" type="number" min="0" :max="rv.limits.interaction" class="in" /></div>
          <div class="fg"><label>درجات إضافية لإنجاز استثنائي (0–10)</label><input v-model.number="rvf.hr_bonus" type="number" min="0" max="10" class="in" /></div>
          <div v-if="rvf.hr_bonus > 0" class="fg" style="grid-column:1/-1"><label>سبب الإضافة * <span class="tiny muted">إنجاز استثنائي موثق بتوصية المشرف المباشر (§71)</span></label><input v-model="rvf.bonus_reason" class="in" /></div>
        </template>
        <div v-if="rv.review_role === 'ceo'" class="fg"><label>جودة المخرجات (0–{{ rv.limits.quality }})</label><input v-model.number="rvf.quality" type="number" min="0" :max="rv.limits.quality" class="in" /></div>
        <div class="fg" style="grid-column:1/-1"><label>ملاحظة / سبب الإرجاع</label><textarea v-model="rvf.note" class="in" rows="2" /></div>
      </div>
      <div v-if="rv.review_role === 'mgr'" class="tiny muted mb">قيّم كل مهمة في خانة «تقييم المدير %» أعلاه. اتركها فارغة لاعتماد تقييم الموظف كما هو.</div>
      <div class="row" style="gap:8px;justify-content:flex-end">
        <button class="btn dan" :class="{ loading: busy }" @click="review('return')">إرجاع للموظف</button>
        <button class="btn okb" :class="{ loading: busy }" @click="review('approve')">{{ rv.review_role === 'ceo' ? 'اعتماد ونشر التقييم' : 'اعتماد وتمرير' }}</button>
      </div>
    </div>

    <div v-if="rv.timeline?.length" class="card"><h3>المسار</h3><ul class="timeline"><li v-for="(x, i) in rv.timeline" :key="i"><b>{{ x.label }}</b> <span class="tiny muted">{{ fmtDT(x.at) }}</span></li></ul></div>
  </template>
</template>

<script setup>
// One monthly report on its own page (easier to read than a dialog): grouped achievements, scores,
// the reviewer's form for the current stage, post-approval adjustment, Excel download and print.
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import ReportItems from '../components/ReportItems.vue'
import { exportReportForm } from '../excel'
import { useUi } from '../stores/ui'
import { fmtDT, fmtYM } from '../utils'

const props = defineProps({ id: [String, Number] })
const ui = useUi()
const router = useRouter()
const STATUS_COLOR = { draft: 'gray', submitted: 'warn', mgr_reviewed: 'info', hr_reviewed: 'info', approved: 'ok', returned: 'bad' }
const ROLE = { mgr: 'المدير المباشر', hr: 'الموارد البشرية', ceo: 'رئيس الشركة' }
const rv = ref(null); const rvf = ref({}); const adj = ref({ hr_bonus: 0, reason: '' }); const busy = ref(false); const dl = ref(false)

async function load() {
  rv.value = await api.get(`/reports/monthly/${props.id}`)
  adj.value = { hr_bonus: rv.value.hr_bonus || 0, reason: '' }
  rv.value.items.forEach((i) => { if (i.source === 'emp') i.approved = i.mgr_approved ?? null })
  rvf.value = { coop: rv.value.coop ?? null, hr_score: rv.value.dev?.hr_score ?? 0, interaction: rv.value.dev?.interaction ?? 0, hr_bonus: rv.value.hr_bonus || 0, bonus_reason: rv.value.hr_bonus ? (rv.value.adjust_reason || '') : '', quality: rv.value.quality ?? Math.round(rv.value.limits.quality * 0.8), note: '' }
}
const empOwn = computed(() => (rv.value?.items || []).filter((i) => i.source === 'emp'))
async function review(action) {
  if (action === 'return' && !rvf.value.note?.trim()) return ui.toast('سبب الإرجاع إلزامي', 'warn')
  if (action === 'approve' && rv.value.review_role === 'mgr') {
    if (rv.value.items.some((i) => i.mgr_score != null && i.mgr_score !== i.pct && !i.mgr_note?.trim())) return ui.toast('اكتب ملاحظة لكل مهمة اختلف فيها تقييمك عن تقييم الموظف', 'warn', 5000)
    if (rvf.value.coop == null || rvf.value.coop === '') return ui.toast('تقدير التعاون إلزامي', 'warn')
    if (empOwn.value.some((i) => i.approved == null)) return ui.toast('وافق على كل بند شخصي أو ارفضه', 'warn')
  }
  if (action === 'approve' && rv.value.review_role === 'hr' && rvf.value.hr_bonus > 0 && !rvf.value.bonus_reason?.trim()) return ui.toast('سبب الدرجات الإضافية إلزامي', 'warn')
  busy.value = true
  try {
    const items = rv.value.items.map((i) => ({ id: i.id, mgr_score: i.mgr_score, mgr_note: i.mgr_note, ...(rv.value.review_role === 'mgr' ? { target: i.target ?? null, next_target: i.next_target ?? null } : {}), ...(i.source === 'emp' && i.approved != null ? { approved: i.approved } : {}) }))
    await api.post(`/reports/monthly/${rv.value.id}/review`, { action, ...rvf.value, items })
    ui.toast(action === 'approve' ? 'تم الاعتماد' : 'أُرجع التقرير للموظف', 'ok')
    router.push('/reports')
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function adjust() {
  if (!adj.value.reason?.trim()) return ui.toast('سبب التعديل إلزامي', 'warn')
  busy.value = true
  try { await api.post(`/reports/monthly/${rv.value.id}/adjust`, adj.value); ui.toast('عُدّل ونُشر التقييم', 'ok'); load() } finally { busy.value = false }
}
async function download() {
  dl.value = true
  try {
    const [x] = await api.post('/reports/monthly/export', { period: rv.value.period, employee_ids: [rv.value.employee.id] })
    if (x) await exportReportForm(x.info, rv.value.items, { kind: 'التقرير الشهري', category: 'reports' })
  } finally { dl.value = false }
}
const print = () => window.print()
onMounted(load)
</script>

<style scoped>
.review-box { border: 2px solid #F2C14E; }
@media print { .acts, .review-box { display: none !important; } }
</style>
