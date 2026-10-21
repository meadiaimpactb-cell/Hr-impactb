<template>
  <div class="page-h"><div><h1>{{ t('مركز التقارير') }}</h1><p>تقارير جدولية بحسب الدور والشركة والفترة، مع تصدير وطباعة مضبوطين بالنطاق (§117): الرئيس التنفيذي للمجموعة كل التقارير، والرئيس التنفيذي للشركة تقارير موظفي شركته، والموارد البشرية نطاقها، والمالية التقارير المالية، والمشرف فريقه</p><p v-if="catalog[0]?.scope" class="tiny muted">نطاقك: {{ catalog[0].scope }}</p></div></div>
  <div class="grid side-grid">
    <div class="card" style="margin:0">
      <div v-for="c in sorted" :key="c.key" class="row" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)">
        <button class="btn xs" :class="cur?.key === c.key ? 'sec' : 'ghost'" style="flex:1;justify-content:flex-start" @click="run(c)">{{ c.name }}</button>
        <button class="btn ghost xs" :title="c.favorite ? 'إزالة من المفضلة' : 'إضافة للمفضلة'" @click="fav(c)"><span :class="c.favorite ? 'ok' : 'muted'">★</span></button>
      </div>
    </div>
    <div class="card" style="margin:0">
      <div class="row mb">
        <PeriodPicker v-show="cur?.period === 'range'" ref="pp" v-model:from="from" v-model:to="to" :initial="preset" />
        <MonthPicker v-if="cur?.period === 'month'" v-model="period" />
        <span v-else-if="cur?.period === 'asof'" class="row" style="gap:6px"><span class="small">الوضع في تاريخ</span><DateInput v-model="asof" style="width:auto" /></span>
        <span v-else-if="cur && cur.period !== 'range'" class="tiny muted">الوضع الحالي (لحظي)</span>
        <select v-model="company" class="in" style="width:auto"><option :value="null">كل الشركات</option><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        <button v-if="cur" class="btn pri sm" :class="{ loading: busy }" @click="run(cur)"><Icon name="refresh" :size="14" /> تشغيل</button>
        <span class="spacer" />
        <button v-if="res && cur?.can_export !== false" class="btn ghost sm" @click="exportCsv"><Icon name="download" :size="14" /> Excel</button><button v-if="res && cur?.can_export !== false" class="btn ghost sm" @click="print"><Icon name="external" :size="14" /> طباعة</button>
      </div>
      <div v-if="!res" class="empty">اختر تقريرًا</div>
      <template v-else>
        <h3>{{ res.name }} <span class="sub">{{ res.rows.length }} صف · {{ res.generated_at }}</span></h3>
        <div v-dt class="tw" style="max-height:65vh;overflow:auto"><table><thead><tr><th v-for="c in res.columns" :key="c">{{ c }}</th></tr></thead><tbody><tr v-for="(r, i) in res.rows" :key="i"><td v-for="(v, j) in r" :key="j" :class="{ num: typeof v === 'number' }">{{ typeof v === 'number' ? v.toLocaleString('en-US') : (v ?? '—') }}</td></tr></tbody></table></div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { useUi } from '../stores/ui'
import { exportXlsx } from '../excel'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuth } from '../stores/auth'
import { csv, curYM, download, today } from '../utils'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const route = useRoute()
const catalog = ref([]); const cur = ref(null); const res = ref(null); const busy = ref(false); const company = ref(ui.company || null) // follows the company chosen in the top bar
const preset = ref('month'); const from = ref(''); const to = ref(today()); const period = ref(curYM()); const pp = ref(null); const asof = ref(today())
const sorted = computed(() => [...catalog.value].sort((a, b) => (b.favorite - a.favorite)))
async function load() {
  catalog.value = await api.get('/reports-center')
  // opened from the assistant: /reports-center?key=…&from=…&to=…&period=…
  const qy = route.query
  const c = qy.key && catalog.value.find((x) => x.key === qy.key)
  if (c) { if (qy.from) { preset.value = 'custom'; from.value = qy.from } if (qy.to) to.value = qy.to; if (qy.period) period.value = qy.period; cur.value = c; await nextTick(); run(c) }
}
watch(() => ui.company, (v) => { company.value = v || null; if (cur.value) run(cur.value) })
async function run(c) {
  cur.value = c
  if (c.period === 'range' && !from.value) { await nextTick(); pp.value?.setPreset(preset.value) }
  busy.value = true; try { res.value = await api.get(`/reports-center/${c.key}`, { params: { from: from.value, to: c.period === 'asof' ? asof.value : to.value, period: period.value, company_id: company.value || undefined } }) } finally { busy.value = false } }
async function fav(c) { await api.post(`/reports-center/${c.key}/favorite`); c.favorite = !c.favorite }
const logExport = (kind) => api.post(`/reports-center/${res.value.key}/exported`, { kind, from: from.value, to: to.value, period: period.value, company_id: company.value || null }).catch(() => {})
function exportCsv() { logExport('excel'); exportXlsx(`${res.value.key}-${today()}`, ([res.value.columns, ...res.value.rows]), { title: res.value.name, company: auth.meta?.companies?.find((c) => c.id === company.value)?.name, period: cur.value?.period === 'month' ? period.value.split('-').reverse().join('/') : cur.value?.period === 'range' ? `${from.value.split('-').reverse().join('/')} — ${to.value.split('-').reverse().join('/')}` : cur.value?.period === 'asof' ? `في ${asof.value.split('-').reverse().join('/')}` : '' }) }
function print() { logExport('print'); const w = window.open('', '_blank'); w.document.write(`<html dir="rtl"><head><meta charset="utf-8"><title>${res.value.name}</title><style>body{font-family:Tahoma,Arial;padding:24px}table{border-collapse:collapse;width:100%;font-size:12px}td,th{border:1px solid #ccc;padding:5px}th{background:#f2f4f8}</style></head><body><h2>${res.value.name}</h2><p>${res.value.generated_at}</p><table><tr>${res.value.columns.map((c) => `<th>${c}</th>`).join('')}</tr>${res.value.rows.map((r) => `<tr>${r.map((v) => `<td>${v ?? ''}</td>`).join('')}</tr>`).join('')}</table></body></html>`); w.document.close(); w.print() }
onMounted(load)
</script>
