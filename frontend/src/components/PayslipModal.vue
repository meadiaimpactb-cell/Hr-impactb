<template>
  <Modal :title="d ? `قسيمة راتب — ${d.employee.name} — ${fmtYM(d.payslip.period)}` : '…'" size="wide" @close="$emit('close')">
    <div v-if="!d"><div class="skel" v-for="i in 5" :key="i" /></div>
    <template v-else>
      <div class="row mb">
        <span class="pill" :class="d.payslip.published ? 'ok' : 'gray'">{{ d.payslip.published ? 'منشورة' : 'مسودة' }}</span>
        <span class="pill" :class="d.payslip.hr_approved ? 'ok' : 'warn'"><Icon name="shield" :size="13" /> {{ d.payslip.hr_approved ? 'روجعت واعتُمدت ضمن المسير' : 'بانتظار المراجعة' }}</span>
        <span class="small muted">v{{ d.payslip.version }} · {{ d.payslip.no }}</span>
      </div>

      <div class="grid g2">
        <div>
          <h4 style="margin:0 0 6px">المستحقات</h4>
          <div class="tw"><table>
            <tbody>
              <tr v-for="(e, ei) in d.payslip.line.earnings" :key="ei"><td>{{ e.name }}</td><td class="num">{{ money(e.amount) }}</td></tr>
              <tr><td><b>إجمالي المستحقات</b></td><td class="num"><b>{{ money(d.payslip.line.gross) }}</b></td></tr>
            </tbody>
          </table></div>
          <p v-if="d.payslip.line.prorate?.partial" class="tiny muted">احتساب تناسبي: {{ d.payslip.line.service_days }} يوم خدمة ÷ {{ d.payslip.line.prorate.denom }}</p>
        </div>
        <div>
          <h4 style="margin:0 0 6px">الاستقطاعات</h4>
          <div v-if="!d.payslip.line.deductions.length" class="empty small">لا استقطاعات</div>
          <div v-else class="tw"><table>
            <tbody>
              <tr v-for="(ded, di) in d.payslip.line.deductions" :key="di">
                <td>{{ ded.name }}<span v-if="ded.key === 'late' && d.payslip.adj_late !== null" class="tiny muted"> (آلي {{ money(ded.amount) }} → معدَّل)</span></td>
                <td class="num">{{ money(ded.key === 'late' && d.payslip.adj_late !== null ? d.payslip.adj_late : ded.amount) }}</td>
              </tr>
              <tr><td><b>إجمالي الاستقطاعات</b></td><td class="num"><b>{{ money(d.ded_total) }}</b></td></tr>
            </tbody>
          </table></div>
          <p v-if="d.payslip.adj_reason" class="tiny muted">سبب التعديل: {{ d.payslip.adj_reason }}</p>
        </div>
      </div>

      <div class="net-box mt">
        <div class="small muted">صافي المستحق</div>
        <div class="amt num">{{ money(d.net) }} <span class="small">ر.س</span></div>
      </div>

      <div class="grid g3">
        <Kpi label="رصيد الإجازة السنوية المتبقي" :value="`${d.balances.available} يوم`" color="b" />
        <Kpi label="الأجر الإجمالي" color="n"><span class="num">{{ money(d.payslip.line.gross) }}</span></Kpi>
        <Kpi label="أيام الخدمة في الفترة" :value="d.payslip.line.service_days" color="p" />
      </div>
    </template>

    <template #foot>
      <button class="btn sec" @click="$emit('close')">إغلاق</button>
      <button v-if="d" class="btn sec" @click="printIt"><Icon name="external" :size="15" /> عرض / طباعة PDF</button>
      <button v-if="d?.can_hr_approve" class="btn okb" :class="{ loading: busy }" @click="approve('hr')"><Icon name="check-circle" :size="15" /> نشر القسيمة للموظف</button>
    </template>
  </Modal>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../api'
import { useUi } from '../stores/ui'
import { fmtYM, money } from '../utils'
import Kpi from './Kpi.vue'
import Modal from './Modal.vue'

const props = defineProps({ id: Number })
const emit = defineEmits(['close', 'changed'])
const ui = useUi()
const d = ref(null)
const busy = ref(false)

async function load() { d.value = await api.get(`/payroll/payslips/${props.id}`) }
onMounted(load)

async function approve(role) {
  busy.value = true
  try {
    await api.post(`/payroll/payslips/${props.id}/approve`, { role })
    ui.toast(role === 'mgr' ? 'اعتُمدت من المدير' : 'اعتُمدت من الموارد البشرية — تُنشر عند اكتمال الاعتمادين', 'ok')
    await load()
    emit('changed')
  } finally { busy.value = false }
}
function printIt() { window.open(`/print/payslip/${props.id}`, '_blank') }
</script>
