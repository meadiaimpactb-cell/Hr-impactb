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

      <div v-dt class="tw"><table>
        <thead><tr><th>الموظف</th><th class="c">أيام الخدمة</th><th class="c">الإجمالي</th><th class="c">خصم تأخير</th><th class="c">قسط سلفة</th><th class="c">التأمينات</th><th class="c">خصومات أخرى</th><th class="c">الصافي</th><th v-if="d.can_edit" /></tr></thead>
        <tbody><tr v-for="l in d.lines" :key="l.employee_id">
          <td><b>{{ l.employee_name }}</b><span class="tiny muted">{{ l.emp_no }}</span></td>
          <td class="c">{{ l.service_days }}<span v-if="l.prorate?.partial" class="tiny muted">/{{ l.prorate.denom }}</span></td>
          <td class="num c">{{ money(l.gross) }}</td>
          <td class="c">
            <span v-if="lateDed(l)" class="num">{{ money(lateDed(l)) }}</span>
            <span v-if="l.adj_late != null" class="num"> → <b>{{ money(l.adj_late) }}</b> <Icon name="edit" :size="12" class="muted" :title="l.adj_reason" /></span>
            <span v-if="!lateDed(l) && l.adj_late == null">—</span>
          </td>
          <td class="num c">{{ advDed(l) ? money(advDed(l)) : '—' }}</td>
          <td class="num c" :title="l.gosi ? `${l.gosi.scheme_label} · الموظف ${l.gosi.employee_rate}% · المنشأة ${l.gosi.employer_rate}% = ${money(l.gosi.employer)}` : 'غير مشمول'">{{ gosiDed(l) ? money(gosiDed(l)) : '—' }}</td>
          <td class="num c" :title="otherTitle(l)">{{ otherDed(l) ? money(otherDed(l)) : '—' }}</td>
          <td class="num c"><b>{{ money(l.net) }}</b></td>
          <td v-if="d.can_edit"><button class="btn ghost xs" @click="adjustOpen(l)">تعديل الخصم</button></td>
        </tr></tbody>
      </table></div>
    </template>
    <template #foot>
      <button class="btn sec" @click="$emit('close')">إغلاق</button>
      <button v-if="d?.can_regenerate" class="btn ghost" :class="{ loading: busyRegen }" @click="regenerate"><Icon name="refresh" :size="15" /> إعادة التجميع</button>
      <span v-if="!d?.next_action && d?.next_stage_label && d?.run?.status !== 'approved'" class="pill warn" style="align-self:center">بانتظار: {{ d.next_stage_label }}</span>
      <button v-if="d?.next_action" class="btn" :class="[d.next_action === 'approved' ? 'okb' : 'pri', { loading: busyStage }]" @click="stage(d.next_action)">
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
import { fmtYM, money } from '../utils'
import Kpi from './Kpi.vue'
import Modal from './Modal.vue'

const props = defineProps({ runId: Number, company: Object, period: String })
const emit = defineEmits(['close', 'changed'])
const ui = useUi()
const d = ref(null)
const busyStage = ref(false)
const busyRegen = ref(false)
const busyAdj = ref(false)
const adjTarget = ref(null)
const adjAmount = ref(0)
const adjReason = ref('')

const STAGES = [['draft', 'مسودة آلية'], ['hr_review', 'مراجعة أخصائي الموارد'], ['hr_approved', 'اعتماد مدير الموارد'], ['fin_approved', 'اعتماد المدير المالي'], ['approved', 'اعتماد المدير العام']]
const STAGE_ACTION_LABEL = { hr_review: 'مراجعة أخصائي الموارد', hr_approved: 'اعتماد مدير الموارد', fin_approved: 'اعتماد المدير المالي', approved: 'اعتماد نهائي وقفل المسير' }
const curIdx = computed(() => STAGES.findIndex((s) => s[0] === d.value?.run?.status))
const stepCls = (i) => (i < curIdx.value ? 'done' : i === curIdx.value ? 'cur' : '')
const totalGross = computed(() => (d.value?.lines || []).reduce((s, l) => s + l.gross, 0))
const totalNet = computed(() => (d.value?.lines || []).reduce((s, l) => s + l.net, 0))
const lateDed = (l) => l.deductions.find((x) => x.key === 'late')?.amount || 0
const advDed = (l) => l.deductions.filter((x) => x.key === 'advance').reduce((s, x) => s + x.amount, 0)
const gosiDed = (l) => l.deductions.filter((x) => x.key === 'gosi').reduce((s, x) => s + x.amount, 0)
const employerGosi = computed(() => (d.value?.lines || []).reduce((s, l) => s + (l.gosi?.employer || 0), 0))
const otherTitle = (l) => l.deductions.filter((x) => !['late', 'advance', 'gosi'].includes(x.key)).map((x) => `${x.name || x.key}: ${money(x.amount)}`).join(' · ')
const otherDed = (l) => l.deductions.filter((x) => !['late', 'advance', 'gosi'].includes(x.key)).reduce((s, x) => s + x.amount, 0)

async function load() { d.value = await api.get(`/payroll/runs/${props.runId}`) }
onMounted(load)

async function stage(to) {
  if (!await confirmBox(`تأكيد الانتقال إلى مرحلة «${STAGE_ACTION_LABEL[to]}»؟${to === 'approved' ? ' سيُقفل المسير وتُولَّد قسائم الرواتب.' : ''}`)) return
  busyStage.value = true
  try { await api.post(`/payroll/runs/${props.runId}/stage`, { to }); ui.toast('تم', 'ok'); await load(); emit('changed') } finally { busyStage.value = false }
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
