<template>
  <div class="ht">
    <div v-if="!disabled" class="row presets">
      <span class="tiny muted">تعبئة سريعة:</span>
      <button v-for="p in PRESETS" :key="p.label" type="button" class="btn ghost xs" @click="preset(p.days)">{{ p.label }}</button>
    </div>
    <div v-if="!rows.length" class="tiny muted mt">لا توجد أيام عمل. اضغط «إضافة» لإضافة يوم.</div>
    <div v-for="r in rows" :key="r.d" class="line">
      <select class="in day" :value="r.d" :disabled="disabled" title="اليوم" @change="move(r.d, Number($event.target.value))">
        <option v-for="d in ORDER" :key="d" :value="d" :disabled="d !== r.d && used(d)">{{ DAYS_AR[d] }}</option>
      </select>
      <div class="times">
        <input type="time" class="in t" :value="r.t[0]" :disabled="disabled" title="من" @change="set(r.d, 0, $event.target.value)" />
        <span class="tiny muted">إلى</span>
        <input type="time" class="in t" :value="r.t[1]" :disabled="disabled" title="إلى" @change="set(r.d, 1, $event.target.value)" />
      </div>
      <span v-if="r.t[1] < r.t[0]" class="pill warn" title="تنتهي الوردية في اليوم التالي">ليلية</span>
      <button v-if="!disabled" type="button" class="btn ghost xs x" title="حذف اليوم (يصبح إجازة أسبوعية)" @click="remove(r.d)"><Icon name="x" :size="15" /></button>
    </div>
    <button v-if="!disabled && rows.length < 7" type="button" class="link-btn" @click="add">إضافة</button>
    <div class="tiny muted mt">الأيام غير المضافة إجازة أسبوعية.</div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { DAYS_AR } from '../utils'

// v-model: { "0": ["10:00", "19:00"], … } keyed by day of week (0 = Sunday). Missing day = day off.
const props = defineProps({ modelValue: { type: Object, default: () => ({}) }, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const ORDER = [6, 0, 1, 2, 3, 4, 5] // the Saudi week starts on Saturday
const PRESETS = [
  { label: 'الأحد – الخميس', days: [0, 1, 2, 3, 4] },
  { label: 'السبت – الخميس', days: [6, 0, 1, 2, 3, 4] },
  { label: 'كل الأيام', days: ORDER },
]
const val = () => props.modelValue || {}
const rows = computed(() => ORDER.filter((d) => Array.isArray(val()[String(d)])).map((d) => ({ d, t: val()[String(d)] })))
const used = (d) => Array.isArray(val()[String(d)])
const lastTimes = () => (rows.value.length ? [...rows.value[rows.value.length - 1].t] : ['10:00', '19:00'])
const put = (v) => emit('update:modelValue', v)
function set(d, i, t) { const v = { ...val() }; const x = [...v[String(d)]]; x[i] = t || x[i]; v[String(d)] = x; put(v) }
function remove(d) { const v = { ...val() }; delete v[String(d)]; put(v) }
function add() { const d = ORDER.find((x) => !used(x)); if (d === undefined) return; put({ ...val(), [String(d)]: lastTimes() }) }
function move(from, to) { if (from === to) return; const v = { ...val() }; v[String(to)] = v[String(from)]; delete v[String(from)]; put(v) }
function preset(days) { const t = lastTimes(); const v = {}; days.forEach((d) => { v[String(d)] = val()[String(d)] ? [...val()[String(d)]] : [...t] }); put(v) }
</script>

<style scoped>
.presets { gap: 6px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.line { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
.in.day { width: 150px; flex: none; }
.times { display: flex; align-items: center; gap: 6px; direction: ltr; }
.in.t { width: 104px; padding: 6px 8px; flex: none; }
.x { color: var(--muted); }
.link-btn { background: none; border: 0; padding: 2px 0; color: var(--brand, var(--pri)); cursor: pointer; font: inherit; font-size: 13px; }
.link-btn:hover { text-decoration: underline; }
</style>
