<template>
  <div class="card" style="margin:8px 0">
    <h3 style="margin-top:0">السداد والتحويل <span class="sub">{{ v.status_label }}</span></h3>
    <div class="grid g4 mb">
      <Kpi label="المبلغ" color="n"><span class="num">{{ money(v.amount) }}</span></Kpi>
      <Kpi label="المسدَّد" :hint="`${v.paid_count} من ${v.installments} أقساط`" color="g"><span class="num">{{ money(v.recovered) }}</span></Kpi>
      <Kpi label="المتبقي" :hint="`${v.left_count} أقساط متبقية`" color="r"><span class="num">{{ money(v.remaining) }}</span></Kpi>
      <Kpi label="القسط القادم" :hint="v.next ? fmtYM(v.next.period) : 'لا أقساط متبقية'" color="y"><span class="num">{{ v.next ? money(v.next.amount) : '—' }}</span></Kpi>
    </div>
    <div class="bar g mb"><i :style="{ width: (v.installments ? (v.paid_count / v.installments) * 100 : 0) + '%' }" /></div>
    <p class="small"><b>طريقة السداد:</b> {{ v.repay_method_label }} — {{ v.installments }} قسط × <span class="num">{{ money(v.monthly_amount) }}</span> ر.س</p>

    <div class="notice small" :class="v.transfer ? 'ok' : 'warn'">
      <template v-if="v.transfer">
        <b>حُوِّلت السلفة</b> بتاريخ {{ fmtD(v.transfer.on) }}<template v-if="v.transfer.ref"> · مرجع التحويل {{ v.transfer.ref }}</template><template v-if="v.transfer.by"> · أرفقه {{ v.transfer.by }}</template>
        <a href="#" class="btn ghost xs" style="margin-inline-start:6px" @click.prevent="fileUrl(v.transfer.path)"><Icon name="download" :size="13" /> مستند التحويل</a>
      </template>
      <template v-else>بانتظار تحويل المبلغ من الإدارة المالية وإرفاق مستند التحويل.</template>
    </div>
    <div v-if="v.can_transfer" class="fr3 mt" style="align-items:end">
      <div class="fg"><label>{{ v.transfer ? 'استبدال مستند التحويل' : 'مستند التحويل *' }} <span class="tiny muted">(PDF أو صورة)</span></label><input type="file" class="in" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" @change="file = $event.target.files[0]" /></div>
      <div class="fg"><label>مرجع التحويل</label><input v-model="ref_" class="in" dir="ltr" placeholder="رقم العملية البنكية" /></div>
      <div class="fg"><label>تاريخ التحويل</label><DateInput v-model="on" /></div>
      <div><button class="btn pri sm" :class="{ loading: busy }" :disabled="!file" @click="upload"><Icon name="upload" :size="14" /> إرفاق مستند التحويل</button></div>
    </div>

    <div class="tw mt"><table>
      <thead><tr><th>الشهر</th><th>القسط</th><th>الحالة</th></tr></thead>
      <tbody><tr v-for="(s, i) in v.schedule" :key="i">
        <td>{{ fmtYM(s.period) }}<span v-if="s.deferred_from" class="tiny muted"> مؤجل من {{ fmtYM(s.deferred_from) }}</span></td>
        <td class="num">{{ money(s.amount) }}<span v-if="s.status === 'deducted' && s.paid_amount != null && s.paid_amount < s.amount" class="tiny muted"> خُصم {{ money(s.paid_amount) }}</span></td>
        <td><span class="pill" :class="s.status === 'deducted' ? 'ok' : 'gray'">{{ s.status === 'deducted' ? 'سُدِّد' : 'مستحق' }}</span></td>
      </tr></tbody>
    </table></div>
  </div>
</template>

<script setup>
// An approved advance (owner's note): how it is repaid, instalments paid and left, and the transfer document finance attaches.
import { ref } from 'vue'
import api, { fileUrl } from '../api'
import { useUi } from '../stores/ui'
import { fmtD, fmtYM, money, today } from '../utils'
import Kpi from './Kpi.vue'

const props = defineProps({ v: { type: Object, required: true } })
const emit = defineEmits(['updated'])
const ui = useUi()
const file = ref(null); const ref_ = ref(props.v.transfer?.ref || ''); const on = ref(props.v.transfer?.on || today()); const busy = ref(false)
async function upload() {
  busy.value = true
  try {
    const fd = new FormData()
    fd.append('file', file.value)
    if (ref_.value) fd.append('ref', ref_.value)
    if (on.value) fd.append('on', on.value)
    emit('updated', await api.post(`/payroll/advances/${props.v.id}/transfer`, fd))
    file.value = null
    ui.toast('أُرفق مستند التحويل وأُبلغ الموظف', 'ok')
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
</script>
