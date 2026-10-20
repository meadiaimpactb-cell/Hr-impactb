<template>
  <Modal :title="a ? `جدول سداد السلفة — ${a.employee?.name || ''}` : '…'" @close="$emit('close')">
    <div v-if="!a"><div class="skel" v-for="i in 4" :key="i" /></div>
    <template v-else>
      <div class="grid g3 mb">
        <Kpi label="المبلغ" color="n"><span class="num">{{ money(a.amount) }}</span></Kpi>
        <Kpi label="المسدد" color="g"><span class="num">{{ money(a.amount - a.remaining) }}</span></Kpi>
        <Kpi label="المتبقي" color="r"><span class="num">{{ money(a.remaining) }}</span></Kpi>
      </div>
      <div class="bar g mb"><i :style="{ width: (a.paid / a.installments) * 100 + '%' }" /></div>
      <p v-if="a.purpose" class="small muted">الغرض: {{ a.purpose }}</p>
      <div class="tw"><table>
        <thead><tr><th>الشهر</th><th>القسط</th><th>الحالة</th></tr></thead>
        <tbody><tr v-for="(s, i) in a.schedule" :key="i">
          <td>{{ fmtYM(s.period) }}<span v-if="s.deferred_from" class="tiny muted">مؤجل من {{ fmtYM(s.deferred_from) }} (نصف الأجر — المادة 42)</span></td>
          <td class="num">{{ money(s.amount) }}<span v-if="s.status === 'deducted' && s.paid_amount != null && s.paid_amount < s.amount" class="tiny muted">خُصم {{ money(s.paid_amount) }} وأُجّل {{ money(s.deferred || s.amount - s.paid_amount) }}</span></td>
          <td><span class="pill" :class="s.status === 'deducted' ? 'ok' : 'gray'">{{ s.status === 'deducted' ? 'خُصم' : 'مستحق' }}</span></td>
        </tr></tbody>
      </table></div>
    </template>
    <template #foot><button class="btn sec" @click="$emit('close')">إغلاق</button></template>
  </Modal>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../api'
import { fmtYM, money } from '../utils'
import Kpi from './Kpi.vue'
import Modal from './Modal.vue'

const props = defineProps({ id: Number })
defineEmits(['close'])
const a = ref(null)
onMounted(async () => { a.value = await api.get(`/payroll/advances/${props.id}`) })
</script>
