<template>
  <div v-if="!rv"><div v-for="i in 4" :key="i" class="skel" /></div>
  <template v-else>
    <div class="page-h">
      <div>
        <h1>تقرير {{ fmtYM(rv.period) }} — {{ rv.employee?.name }}</h1>
        <p class="row" style="gap:6px;flex-wrap:wrap">
          <span class="pill" :class="STATUS_COLOR[rv.status]">{{ rv.status_label }}</span>
          <span v-if="rv.late" class="pill bad">متأخر</span>
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
      <h3>التطوير هذا الشهر</h3>
      <div class="small">{{ rv.dev?.program || 'لم يُذكر برنامج' }}<span v-if="rv.dev?.provider"> · {{ rv.dev.provider }}</span> · {{ rv.dev?.completed ? 'مكتمل' : 'غير مكتمل' }} · {{ rv.dev?.applied ? 'طُبّق في العمل' : 'لم يُطبَّق بعد' }}</div>
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

    <div v-if="rv.can_review" class="card review-box">
      <h3>المراجعة — {{ ROLE[rv.review_role] }}</h3>
      <div class="fr">
        <div v-if="rv.review_role === 'mgr'" class="fg"><label>التعاون (0–{{ rv.limits.coop }})</label><input v-model.number="rvf.coop" type="number" min="0" :max="rv.limits.coop" class="in" /></div>
        <template v-if="rv.review_role === 'hr'">
          <div class="fg"><label>تقدير الموارد البشرية للتطوير (0–{{ rv.limits.hr_score }})</label><input v-model.number="rvf.hr_score" type="number" min="0" :max="rv.limits.hr_score" class="in" /></div>
          <div class="fg"><label>التفاعل (0–{{ rv.limits.interaction }})</label><input v-model.number="rvf.interaction" type="number" min="0" :max="rv.limits.interaction" class="in" /></div>
          <div class="fg"><label>نقاط إضافية (0–10)</label><input v-model.number="rvf.hr_bonus" type="number" min="0" max="10" class="in" /></div>
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
import { onMounted, ref } from 'vue'
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
  rvf.value = { coop: rv.value.coop ?? rv.value.limits.coop, hr_score: rv.value.dev?.hr_score ?? 0, interaction: rv.value.dev?.interaction ?? 0, hr_bonus: rv.value.hr_bonus || 0, quality: rv.value.quality ?? Math.round(rv.value.limits.quality * 0.8), note: '' }
}
async function review(action) {
  if (action === 'return' && !rvf.value.note?.trim()) return ui.toast('سبب الإرجاع إلزامي', 'warn')
  if (action === 'approve' && rv.value.review_role === 'mgr' && rv.value.items.some((i) => i.mgr_score != null && i.mgr_score !== i.pct && !i.mgr_note?.trim())) return ui.toast('اكتب ملاحظة لكل مهمة اختلف فيها تقييمك عن تقييم الموظف', 'warn', 5000)
  busy.value = true
  try {
    await api.post(`/reports/monthly/${rv.value.id}/review`, { action, ...rvf.value, items: rv.value.items.map((i) => ({ id: i.id, mgr_score: i.mgr_score, mgr_note: i.mgr_note })) })
    ui.toast(action === 'approve' ? 'تم الاعتماد' : 'أُرجع التقرير للموظف', 'ok')
    router.push('/reports')
  } finally { busy.value = false }
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
