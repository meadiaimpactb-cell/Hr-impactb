<template>
  <Modal :title="a ? `السلفة — ${a.employee?.name || ''}` : '…'" size="wide" @close="$emit('close')">
    <div v-if="!a"><div class="skel" v-for="i in 4" :key="i" /></div>
    <template v-else>
      <p v-if="a.purpose" class="small muted">الغرض: {{ a.purpose }}</p>
      <AdvanceRepayment :v="a" @updated="(v) => { a = { ...a, ...v }; $emit('changed') }" />
    </template>
    <template #foot><button class="btn sec" @click="$emit('close')">إغلاق</button></template>
  </Modal>
</template>

<script setup>
// An advance from the payroll lists: repayment, instalments paid and left, and the transfer document (owner's note).
import { onMounted, ref } from 'vue'
import api from '../api'
import AdvanceRepayment from './AdvanceRepayment.vue'
import Modal from './Modal.vue'

const props = defineProps({ id: Number })
defineEmits(['close', 'changed'])
const a = ref(null)
onMounted(async () => { a.value = await api.get(`/payroll/advances/${props.id}`) })
</script>
