<template>
  <Modal :title="d ? `مسير الرواتب — ${props.company.name} — ${fmtYM(props.period)}` : '…'" size="wide" @close="$emit('close')">
    <div v-if="!d"><div class="skel" v-for="i in 6" :key="i" /></div>
    <template v-else>
      <div class="steps mb">
        <span v-for="(s, i) in STAGES" :key="s[0]" class="step" :class="stepCls(i)">
          <i><Icon v-if="i < curIdx" name="check" :size="12" :stroke="3" /><template v-else>{{ i + 1 }}</template></i>{{ s[1] }}
        </span>
      </div>
      <div class="grid g4 mb">
        <Kpi label="إجمالي المستحقات" color="n"><span class="num">{{ money(totalGross) }}</span></Kpi>
        <Kpi label="إجمالي الاستقطاعات" color="r"><span class="num">{{ money(totalGross - totalNet) }}</span></Kpi>
        <Kpi label="صافي المسير" color="g"><span class="num">{{ money(totalNet) }}</span></Kpi>
        <Kpi label="حصة المنشأة في التأمينات" hint="تُدفع للتأمينات ولا تُخصم من الموظف" color="b"><span class="num">{{ money(employerGosi) }}</span></Kpi>
      </div>

      <div v-if="d.sod_note" class="notice warn mb">{{ d.sod_note }}</div>
      <div v-if="blocking.length" class="notice bad mb">
        <b>لا يُعتمد المسير نهائيًا قبل توثيق الحساب البنكي لكل موظف (المادة 38) — {{ blocking.length }} موظف:</b>
        <ul style="margin:6px 0 0;padding-inline-start:18px"><li v-for="i in blocking" :key="i.employee_id">{{ i.name }} ({{ i.emp_no }}) — {{ i.issue }}<span v-if="i.iban" class="tiny muted" style="direction:ltr;display:inline-block"> {{ i.iban }}</span></li></ul>
      </div>
      <div v-if="warnings.length" class="notice warn mb">
        <b>تنبيهات الحسابات البنكية:</b>
        <ul style="margin:6px 0 0;padding-inline-start:18px"><li v-for="i in warnings" :key="i.employee_id">{{ i.name }} ({{ i.emp_no }}) — {{ i.issue }}</li></ul>
      </div>
      <div v-dt class="tw"><table>
        <thead><tr><th>الموظف</th><th class="c">أيام الخدمة</th><th class="c">الإجمالي</th><th class="c">خصم تأخير</th><th class="c">غياب ومُرحّل</th><th class="c">قسط سلفة</th><th class="c">التأمينات</th><th class="c">خصومات أخرى</th><th class="c">الصافي</th><th v-if="d.can_edit" /></tr></thead>
        <tbody><tr v-for="l in d.lines" :key="l.employee_id">
          <td><b>{{ l.employee_name }}</b><span class="tiny muted">{{ l.emp_no }}</span></td>
          <td class="c">{{ l.service_days }}<span v-if="l.prorate?.partial" class="tiny muted">/{{ l.prorate.denom }}</span></td>
          <td class="num c" :title="l.earnings.map((x) => `${x.name}: ${money(x.amount)}`).join(' · ')">{{ money(l.gross) }}<span v-if="l.overtime" class="tiny muted">إضافي {{ l.overtime.hours }} س</span></td>
          <td class="c">
            <span v-if="lateDed(l)" class="num">{{ money(lateDed(l)) }}</span>
            <span v-if="l.adj_late != null" class="num"> → <b>{{ money(l.adj_late) }}</b> <Icon name="edit" :size="12" class="muted" :title="l.adj_reason" /></span>
            <span v-if="!lateDed(l) && l.adj_late == null">—</span>
          </td>
          <td class="num c" :title="attTitle(l)">{{ absDed(l) ? money(absDed(l)) : '—' }}<span v-if="l.att_overflow > 0" class="tiny muted">يُرحَّل {{ money(l.att_overflow) }}</span></td>
          <td class="num c" :title="advTitle(l)">{{ advDed(l) ? money(advDed(l)) : '—' }}<span v-if="(l.advance_deferrals || []).length" class="tiny muted" title="ما زاد على نصف الأجر يُرحَّل للشهر التالي (§41–42)">مُرحّل {{ money(l.advance_deferrals.reduce((s, x) => s + x.deferred, 0)) }}</span></td>
          <td class="num c" :title="l.gosi ? `${l.gosi.scheme_label} · الموظف ${l.gosi.employee_rate}% · المنشأة ${l.gosi.employer_rate}% = ${money(l.gosi.employer)}` : 'غير مشمول'">{{ gosiDed(l) ? money(gosiDed(l)) : '—' }}</td>
          <td class="num c" :title="otherTitle(l)">{{ otherDed(l) ? money(otherDed(l)) : '—' }}</td>
          <td class="num c"><b>{{ money(l.net) }}</b></td>
          <td v-if="d.can_edit"><button class="btn ghost xs" @click="adjustOpen(l)">تعديل الخصم</button></td>
        </tr></tbody>
      </table></div>

      <div v-if="d.stage_log?.length" class="mt">
        <h4 style="margin:0 0 6px">سجل المراحل</h4>
        <div v-for="(s, i) in d.stage_log" :key="i" class="tiny">{{ s.stage_label }} — {{ s.by_name || '—' }} · {{ fmtDT(s.at) }}<span v-if="s.note" class="muted"> · {{ s.note }}</span></div>
      </div>
    </template>
    <template #foot>
      <button class="btn sec" @click="$emit('close')">إغلاق</button>
      <button v-if="d?.can_bank_file" class="btn ghost" :class="{ loading: busyBank }" @click="bankFile('xlsx')"><Icon name="download" :size="15" /> ملف التحويل البنكي</button>
      <button v-if="d?.can_bank_file" class="btn ghost" @click="bankFile('csv')">CSV</button>
      <button v-if="d?.can_regenerate" class="btn ghost" :class="{ loading: busyRegen }" @click="regenerate"><Icon name="refresh" :size="15" /> إعادة التجميع</button>
      <span v-if="!d?.next_action && d?.next_stage_label && d?.run?.status !== 'approved'" class="pill warn" style="align-self:center">بانتظار: {{ d.next_stage_label }}</span>
      <button v-if="d?.next_action" :disabled="d.next_action === 'approved' && blocking.length > 0" class="btn" :class="[d.next_action === 'approved' ? 'okb' : 'pri', { loading: busyStage }]" @click="stage(d.next_action)">
        <Icon name="check-circle" :size="15" /> {{ STAGE_ACTION_LABEL[d.next_action] }}
      </button>
    </template>
  </Modal>

  <Modal v-if="adjTarget" title="تعديل خصم التأخير" size="narrow" @close="adjTarget = null">
    <p class="small">{{ adjTarget.employee_name }} — الخصم الآلي: <b class="num">{{ money(lateDed(adjTarget)) }}</b></p>
    <div class="fg"><label>القيمة المعدَّلة *</label><input v-model.number="adjAmount" type="number" min="0" step="0.01" class="in" /></div>
    <div class="fg"><label>السبب *</label><textarea v-model="adjReason" class="in" placeholder="مثال: عذر موثق بإذن رسمي" /></div>
    <template #foot><button class="btn sec" @click="adjTarget = null">إلغاء</button><button class="btn pri" :class="{ loading: busyAdj }" @click="saveAdjust">حفظ</button></template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import { useUi } from '../stores/ui'
import { csv, download, fmtDT, fmtYM, money } from '../utils'
import { exportXlsx } from '../excel'
import Kpi from './Kpi.vue'
import Modal from './Modal.vue'

const props = defineProps({ runId: Number, company: Object, period: String })
const emit = defineEmits(['close', 'changed'])
const ui = useUi()
const d = ref(null)
const busyStage = ref(false)
const busyRegen = ref(false)
const busyAdj = ref(false)
const busyBank = ref(false)
const adjTarget = ref(null)
const adjAmount = ref(0)
const adjReason = ref('')

const STAGES = [['draft', 'مسودة آلية'], ['hr_review', 'مراجعة أخصائي الموارد'], ['hr_approved', 'اعتماد مدير الموارد'], ['fin_approved', 'اعتماد المدير المالي'], ['approved', 'اعتماد الرئيس التنفيذي للمجموعة']]
const STAGE_ACTION_LABEL = { hr_review: 'مراجعة أخصائي الموارد', hr_approved: 'اعتماد مدير الموارد', fin_approved: 'اعتماد المدير المالي', approved: 'اعتماد نهائي وقفل المسير' }
const curIdx = computed(() => STAGES.findIndex((s) => s[0] === d.value?.run?.status))
const stepCls = (i) => (i < curIdx.value ? 'done' : i === curIdx.value ? 'cur' : '')
const totalGross = computed(() => (d.value?.lines || []).reduce((s, l) => s + l.gross, 0))
const totalNet = computed(() => (d.value?.lines || []).reduce((s, l) => s + l.net, 0))
const lateDed = (l) => l.deductions.find((x) => x.key === 'late')?.amount || 0
const advDed = (l) => l.deductions.filter((x) => x.key === 'advance').reduce((s, x) => s + x.amount, 0)
const gosiDed = (l) => l.deductions.filter((x) => x.key === 'gosi').reduce((s, x) => s + x.amount, 0)
const employerGosi = computed(() => (d.value?.lines || []).reduce((s, l) => s + (l.gosi?.employer || 0), 0))
const MAIN = ['late', 'absence', 'att_carry', 'advance', 'gosi']
const otherTitle = (l) => l.deductions.filter((x) => !MAIN.includes(x.key)).map((x) => `${x.name || x.key}: ${money(x.amount)}`).join(' · ')
const otherDed = (l) => l.deductions.filter((x) => !MAIN.includes(x.key)).reduce((s, x) => s + x.amount, 0)
const absDed = (l) => l.deductions.filter((x) => ['absence', 'att_carry'].includes(x.key)).reduce((s, x) => s + x.amount, 0)
const attTitle = (l) => [...l.deductions.filter((x) => ['late', 'absence', 'att_carry'].includes(x.key)).map((x) => `${x.name}: ${money(x.amount)}`), l.att_cap != null ? `سقف حسومات الحضور: ${money(l.att_cap)}` : '', l.att_overflow > 0 ? `يُرحَّل للشهر التالي: ${money(l.att_overflow)}` : ''].filter(Boolean).join(' · ')
const advTitle = (l) => l.deductions.filter((x) => x.key === 'advance').map((x) => x.name).join(' · ')
const blocking = computed(() => (d.value?.bank_issues || []).filter((i) => i.blocking))
const warnings = computed(() => (d.value?.bank_issues || []).filter((i) => !i.blocking))

async function load() { d.value = await api.get(`/payroll/runs/${props.runId}`) }
onMounted(load)

async function stage(to) {
  if (!await confirmBox(`تأكيد الانتقال إلى مرحلة «${STAGE_ACTION_LABEL[to]}»؟${to === 'approved' ? ' سيُقفل المسير وتُنشر القسائم للموظفين ويُبلَّغ المديرون المباشرون للعلم.' : ''}`)) return
  busyStage.value = true
  try { await api.post(`/payroll/runs/${props.runId}/stage`, { to }); ui.toast('تم', 'ok'); await load(); emit('changed') } finally { busyStage.value = false }
}
async function bankFile(kind) {
  busyBank.value = true
  try {
    const f = await api.get(`/payroll/runs/${props.runId}/bank-transfer`)
    if (!f.final && !await confirmBox(`المسير لم يُعتمد نهائيًا بعد (${f.status_label}). تنزيل الملف للمراجعة فقط؟`)) return
    const head = ['اسم الموظف', 'الرقم الوظيفي', 'الآيبان', 'صافي الراتب', 'ملاحظة']
    const rows = f.rows.map((r) => [r.name, r.emp_no, r.iban, r.net, r.issue || ''])
    const name = `تحويل بنكي ${f.company?.name || ''} ${f.period}`
    if (kind === 'csv') {
      download(`${name}.csv`, csv([head, ...rows.map((r) => [r[0], r[1], r[2], Number(r[3]).toFixed(2), r[4]])]))
    } else {
      await exportXlsx(name, [head, ...rows, ['الإجمالي', '', '', f.total, '']], { company: f.company?.name, title: `ملف التحويل البنكي للرواتب — ${fmtYM(f.period)}`, period: fmtYM(f.period), kind: 'ملف التحويل البنكي' })
    }
    if (f.issues?.length) ui.toast(`${f.issues.length} موظف عليهم ملاحظات في الحساب البنكي — راجع عمود الملاحظة`, 'warn')
  } finally { busyBank.value = false }
}
async function regenerate() {
  if (!await confirmBox('سيُعاد تجميع المسير من جديد وتُفقد أي تعديلات يدوية.')) return
  busyRegen.value = true
  try { await api.post(`/payroll/runs/${props.runId}/regenerate`); ui.toast('أُعيد التجميع', 'ok'); await load(); emit('changed') } finally { busyRegen.value = false }
}
function adjustOpen(l) { adjTarget.value = l; adjAmount.value = l.adj_late ?? lateDed(l); adjReason.value = l.adj_reason || '' }
async function saveAdjust() {
  if (!adjReason.value.trim()) return ui.toast('السبب إلزامي', 'warn')
  busyAdj.value = true
  try {
    await api.post(`/payroll/runs/${props.runId}/adjust-late`, { employee_id: adjTarget.value.employee_id, adj: adjAmount.value, reason: adjReason.value })
    ui.toast('تم التعديل', 'ok')
    adjTarget.value = null
    await load()
    emit('changed')
  } finally { busyAdj.value = false }
}
</script>

<style scoped>
th.c, td.c { text-align: center; }
</style>
