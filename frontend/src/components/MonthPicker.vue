<template>
  <select class="in" style="width:auto" :value="modelValue || ''" :disabled="disabled" :aria-label="label" @change="pick($event.target.value)">
    <option v-if="allowEmpty" value="">{{ emptyLabel }}</option>
    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
  </select>
</template>

<script setup>
import { computed } from 'vue'

// Month chosen from a list (owner's note: "يحدد الشهر من قائمة") instead of the browser's month input, which some
// browsers show as a plain text box. Value: YYYY-MM.
const props = defineProps({
  modelValue: { type: String, default: '' },
  allowEmpty: Boolean,
  emptyLabel: { type: String, default: 'كل الأشهر' },
  back: { type: Number, default: 36 },
  ahead: { type: Number, default: 3 },
  disabled: Boolean,
  label: { type: String, default: 'الشهر' },
})
const emit = defineEmits(['update:modelValue', 'change'])
const NAMES = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
const months = computed(() => {
  const now = new Date()
  const cur = ym(now)
  const prev = ym(new Date(now.getFullYear(), now.getMonth() - 1, 1))
  const out = []
  for (let i = props.ahead; i >= -props.back; i--) {
    const d = new Date(now.getFullYear(), now.getMonth() + i, 1)
    const v = ym(d)
    out.push({ value: v, label: `${NAMES[d.getMonth()]} ${d.getFullYear()}${v === cur ? ' — الشهر الحالي' : v === prev ? ' — الشهر السابق' : ''}` })
  }
  // a stored value outside the window still shows
  if (props.modelValue && !out.some((m) => m.value === props.modelValue)) {
    const [y, m] = props.modelValue.split('-').map(Number)
    if (y && m) out.push({ value: props.modelValue, label: `${NAMES[m - 1]} ${y}` })
  }
  return out
})
function pick(v) { emit('update:modelValue', v); emit('change', v) }
</script>
