<template>
  <div class="row" style="gap:6px;flex-wrap:wrap;align-items:center">
    <select v-model="preset" class="in" style="width:auto" aria-label="الفترة" @change="apply">
      <option v-for="(l, k) in PRESETS" :key="k" :value="k">{{ l }}</option>
    </select>
    <MonthPicker v-if="preset === 'month_pick'" v-model="month" :ahead="0" @change="apply" />
    <template v-if="preset === 'custom'">
      <span class="tiny muted">من</span><DateInput :model-value="from" style="width:auto" @update:model-value="set($event, to)" />
      <span class="tiny muted">إلى</span><DateInput :model-value="to" :min="from" style="width:auto" @update:model-value="set(from, $event)" />
    </template>
    <span v-else class="tiny muted num">{{ fmt(from) }} — {{ fmt(to) }}</span>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import MonthPicker from './MonthPicker.vue'

// Report period (owner's note): a from–to range, or a preset — today, this / last week, this / last month, a month from
// the list, this quarter, this year. Weeks start on Sunday. v-model:from / v-model:to carry YYYY-MM-DD.
const props = defineProps({ from: { type: String, default: '' }, to: { type: String, default: '' }, initial: { type: String, default: 'month' } })
const emit = defineEmits(['update:from', 'update:to', 'change'])
const PRESETS = { today: 'اليوم', week: 'هذا الأسبوع', prev_week: 'الأسبوع السابق', month: 'هذا الشهر', prev_month: 'الشهر السابق', month_pick: 'شهر من القائمة', quarter: 'هذا الربع', year: 'هذه السنة', custom: 'من تاريخ إلى تاريخ' }
const preset = ref(props.initial)
const p = (x) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}` // local date, no UTC shift
const month = ref(p(new Date()).slice(0, 7))
const fmt = (d) => (d ? d.split('-').reverse().join('/') : '')
function set(f, t) { emit('update:from', f); emit('update:to', t); emit('change', { from: f, to: t, preset: preset.value }) }
function apply() {
  const d = new Date()
  const k = preset.value
  if (k === 'custom') return set(props.from || p(new Date(d.getFullYear(), d.getMonth(), 1)), props.to || p(d))
  if (k === 'today') return set(p(d), p(d))
  if (k === 'week') { const s = new Date(d); s.setDate(d.getDate() - d.getDay()); return set(p(s), p(d)) }
  if (k === 'prev_week') { const s = new Date(d); s.setDate(d.getDate() - d.getDay() - 7); const e = new Date(s); e.setDate(s.getDate() + 6); return set(p(s), p(e)) }
  if (k === 'month') return set(p(new Date(d.getFullYear(), d.getMonth(), 1)), p(d))
  if (k === 'prev_month') return set(p(new Date(d.getFullYear(), d.getMonth() - 1, 1)), p(new Date(d.getFullYear(), d.getMonth(), 0)))
  if (k === 'month_pick') { const [y, m] = month.value.split('-').map(Number); return set(p(new Date(y, m - 1, 1)), p(new Date(y, m, 0))) }
  if (k === 'quarter') return set(p(new Date(d.getFullYear(), Math.floor(d.getMonth() / 3) * 3, 1)), p(d))
  if (k === 'year') return set(`${d.getFullYear()}-01-01`, p(d))
}
defineExpose({ setPreset: (k) => { preset.value = k; apply() } })
onMounted(() => { if (preset.value !== 'custom' || !props.from) apply() })
</script>
