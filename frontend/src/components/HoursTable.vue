<template>
  <div class="ht">
    <div class="row" style="gap:8px;align-items:center;margin-bottom:6px">
      <span class="tiny muted">علّم أيام العمل (السبت أو أي يوم) وحدد وقت كل يوم؛ اليوم غير المعلّم إجازة أسبوعية.</span>
      <template v-if="!disabled">
        <span class="tiny muted">تطبيق على كل أيام العمل:</span>
        <input v-model="allStart" type="time" class="in xs" /><span class="tiny muted">إلى</span><input v-model="allEnd" type="time" class="in xs" />
        <button type="button" class="btn ghost xs" @click="applyAll">تطبيق</button>
      </template>
    </div>
    <div class="days">
      <div v-for="d in 7" :key="d - 1" class="day" :class="{ off: !on(d - 1) }">
        <label class="chk"><input type="checkbox" :checked="on(d - 1)" :disabled="disabled" @change="toggle(d - 1, $event.target.checked)" /> {{ DAYS_AR[d - 1] }}</label>
        <div class="times">
          <input type="time" class="in xs" :value="h(d - 1)[0]" :disabled="disabled || !on(d - 1)" @change="set(d - 1, 0, $event.target.value)" />
          <span class="tiny muted">إلى</span>
          <input type="time" class="in xs" :value="h(d - 1)[1]" :disabled="disabled || !on(d - 1)" @change="set(d - 1, 1, $event.target.value)" />
          <span v-if="on(d - 1) && h(d - 1)[1] < h(d - 1)[0]" class="pill warn" title="تنتهي الوردية في اليوم التالي">ليلية</span>
          <span v-else-if="!on(d - 1)" class="tiny muted">إجازة — فعّل اليوم لتحديد وقته</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { DAYS_AR } from '../utils'

// v-model: { "0": ["10:00", "19:00"], … } keyed by day of week (0 = Sunday). Missing day = day off.
const props = defineProps({ modelValue: { type: Object, default: () => ({}) }, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const allStart = ref('09:00')
const allEnd = ref('17:00')
const on = (d) => Array.isArray(props.modelValue?.[String(d)])
const h = (d) => props.modelValue[String(d)] || [allStart.value, allEnd.value]
function put(obj) { emit('update:modelValue', obj) }
function toggle(d, checked) {
  const v = { ...props.modelValue }
  if (checked) v[String(d)] = [allStart.value, allEnd.value]; else delete v[String(d)]
  put(v)
}
function set(d, i, val) { const v = { ...props.modelValue }; const t = [...(v[String(d)] || ['09:00', '17:00'])]; t[i] = val || t[i]; v[String(d)] = t; put(v) }
function applyAll() { const v = {}; for (const k of Object.keys(props.modelValue)) v[k] = [allStart.value, allEnd.value]; put(v) }
</script>

<style scoped>
.days { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr)); gap: 8px 12px; }
.day { display: flex; flex-direction: column; gap: 6px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 10px; min-width: 0; }
.day.off { opacity: .7; }
.times { display: flex; align-items: center; gap: 6px; direction: ltr; flex-wrap: wrap; }
.in.xs { padding: 4px 6px; width: 104px; font-size: 13px; flex: none; }
</style>
