<template>
  <div class="page-h"><div><h1>{{ t('مركز التقارير') }}</h1><p>تقارير جدولية على نطاق صلاحياتك بمحدد فترة موحّد، تصدير CSV وطباعة، ومفضلة لكل مستخدم</p></div></div>
  <div class="grid side-grid">
    <div class="card" style="margin:0">
      <div v-for="c in sorted" :key="c.key" class="row" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)">
        <button class="btn xs" :class="cur?.key === c.key ? 'sec' : 'ghost'" style="flex:1;justify-content:flex-start" @click="run(c)">{{ c.name }}</button>
        <button class="btn ghost xs" :title="c.favorite ? 'إزالة من المفضلة' : 'إضافة للمفضلة'" @click="fav(c)"><span :class="c.favorite ? 'ok' : 'muted'">★</span></button>
      </div>
    </div>
    <div class="card" style="margin:0">
      <div class="row mb">
        <template v-if="cur?.period === 'range'"><select v-model="preset" class="in" style="width:auto" @change="applyPreset"><option value="today">اليوم</option><option value="week">هذا الأسبوع</option><option value="month">هذا الشهر</option><option value="quarter">هذا الربع</option><option value="year">هذه السنة</option><option value="custom">مخصص</option></select><DateInput v-model="from" style="width:auto" /><DateInput v-model="to" style="width:auto" /></template>
        <input v-else-if="cur?.period === 'month'" v-model="period" type="month" class="in" style="width:auto" />
        <select v-model="company" class="in" style="width:auto"><option :value="null">كل الشركات</option><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        <button v-if="cur" class="btn pri sm" :class="{ loading: busy }" @click="run(cur)"><Icon name="refresh" :size="14" /> تشغيل</button>
        <span class="spacer" />
        <button v-if="res" class="btn ghost sm" @click="exportCsv"><Icon name="download" :size="14" /> Excel</button><button v-if="res" class="btn ghost sm" @click="print"><Icon name="external" :size="14" /> طباعة</button>
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
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuth } from '../stores/auth'
import { csv, curYM, download, today } from '../utils'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const catalog = ref([]); const cur = ref(null); const res = ref(null); const busy = ref(false); const company = ref(ui.company || null) // follows the company chosen in the top bar
const preset = ref('month'); const from = ref(''); const to = ref(today()); const period = ref(curYM())
const sorted = computed(() => [...catalog.value].sort((a, b) => (b.favorite - a.favorite)))
function applyPreset() {
  const d = new Date(); const p = (x) => x.toISOString().slice(0, 10)
  if (preset.value === 'today') from.value = to.value = p(d)
  else if (preset.value === 'week') { const s = new Date(d); s.setDate(d.getDate() - d.getDay()); from.value = p(s); to.value = p(d) }
  else if (preset.value === 'month') { from.value = p(new Date(d.getFullYear(), d.getMonth(), 1)); to.value = p(d) }
  else if (preset.value === 'quarter') { from.value = p(new Date(d.getFullYear(), Math.floor(d.getMonth() / 3) * 3, 1)); to.value = p(d) }
  else if (preset.value === 'year') { from.value = `${d.getFullYear()}-01-01`; to.value = p(d) }
}
async function load() { catalog.value = await api.get('/reports-center'); applyPreset() }
watch(() => ui.company, (v) => { company.value = v || null; if (cur.value) run(cur.value) })
async function run(c) { cur.value = c; busy.value = true; try { res.value = await api.get(`/reports-center/${c.key}`, { params: { from: from.value, to: to.value, period: period.value, company_id: company.value || undefined } }) } finally { busy.value = false } }
async function fav(c) { await api.post(`/reports-center/${c.key}/favorite`); c.favorite = !c.favorite }
function exportCsv() { exportXlsx(`${res.value.key}-${today()}`, ([res.value.columns, ...res.value.rows]), { title: res.value.name, company: auth.meta?.companies?.find((c) => c.id === company.value)?.name, period: cur.value?.period === 'month' ? period.value.split('-').reverse().join('/') : cur.value?.period === 'range' ? `${from.value.split('-').reverse().join('/')} — ${to.value.split('-').reverse().join('/')}` : '' }) }
function print() { const w = window.open('', '_blank'); w.document.write(`<html dir="rtl"><head><meta charset="utf-8"><title>${res.value.name}</title><style>body{font-family:Tahoma,Arial;padding:24px}table{border-collapse:collapse;width:100%;font-size:12px}td,th{border:1px solid #ccc;padding:5px}th{background:#f2f4f8}</style></head><body><h2>${res.value.name}</h2><p>${res.value.generated_at}</p><table><tr>${res.value.columns.map((c) => `<th>${c}</th>`).join('')}</tr>${res.value.rows.map((r) => `<tr>${r.map((v) => `<td>${v ?? ''}</td>`).join('')}</tr>`).join('')}</table></body></html>`); w.document.close(); w.print() }
onMounted(load)
</script>
