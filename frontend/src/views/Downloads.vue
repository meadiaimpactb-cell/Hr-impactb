<template>
  <div class="page-h">
    <div><h1>مركز التنزيل</h1><p>كل ملف تصدّره من النظام يُحفظ هنا تلقائيًا، فتعيد تنزيله في أي وقت خلال {{ keepDays }} يومًا.</p></div>
    <div class="acts"><input v-model="month" type="month" class="in" style="width:auto" title="تصفية بالشهر" /><select v-model="co" class="in" style="width:auto" title="تصفية بالشركة"><option value="">كل الشركات</option><option v-for="c in auth.meta?.companies || []" :key="c.id" :value="c.name">{{ c.name }}</option></select><input v-model.trim="q" class="in" placeholder="بحث باسم الملف أو الموظف أو الشركة" style="width:240px" /></div>
  </div>

  <div class="tabs">
    <button :class="{ on: tab === '' }" @click="tab = ''">كل الملفات <span class="pill gray">{{ files.length }}</span></button>
    <button v-for="(l, k) in categories" :key="k" :class="{ on: tab === k }" @click="tab = k">{{ l }} <span class="pill gray">{{ files.filter((f) => f.category === k).length }}</span></button>
  </div>

  <div class="card">
    <div v-if="loading"><div v-for="i in 4" :key="i" class="skel" /></div>
    <div v-else-if="!shown.length" class="empty">
      <Icon name="inbox" :size="34" />
      <p>{{ files.length ? 'لا ملفات مطابقة' : 'لا ملفات بعد. أي تصدير Excel من الجداول أو التقارير يظهر هنا.' }}</p>
    </div>
    <div v-else class="tw"><table class="dl">
      <thead><tr><th>الملف</th><th>المصدر</th><th>الحالة</th><th style="width:120px">إجراء</th></tr></thead>
      <tbody><tr v-for="f in shown" :key="f.id">
        <td><div class="row" style="gap:10px;flex-wrap:nowrap"><span class="fic"><Icon name="sheet" :size="18" /></span><div><b>{{ f.name }}</b><div class="tiny muted num">{{ fmtDT(f.created_at) }} · {{ size(f.size) }}</div></div></div></td>
        <td>{{ f.kind }}<div class="tiny muted">{{ f.format.toUpperCase() }} · {{ categories[f.category] || '' }}</div></td>
        <td><span class="ok small">جاهز للتنزيل</span></td>
        <td><div class="row" style="gap:6px;flex-wrap:nowrap">
          <button class="btn sec xs round" title="تنزيل" :class="{ loading: busy === f.id }" @click="download(f)"><Icon name="download" :size="15" /></button>
          <button class="btn ghost xs bad" title="حذف" @click="remove(f)"><Icon name="trash" :size="15" /></button>
        </div></td>
      </tr></tbody>
    </table></div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import { confirmBox } from '../dialog'
import { useUi } from '../stores/ui'
import { useAuth } from '../stores/auth'
import { fmtDT } from '../utils'

const ui = useUi()
const auth = useAuth()
const files = ref([]); const categories = ref({}); const keepDays = ref(90)
const tab = ref(''); const q = ref(''); const month = ref(''); const co = ref(''); const loading = ref(true); const busy = ref(null)
const shown = computed(() => files.value.filter((f) => (!tab.value || f.category === tab.value) && (!month.value || String(f.created_at).startsWith(month.value)) && (!q.value || `${f.name} ${f.kind}`.includes(q.value)) && (!co.value || `${f.name} ${f.kind}`.includes(co.value))))
const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)

async function load() {
  loading.value = true
  try { const r = await api.get('/exports'); files.value = r.files; categories.value = r.categories; keepDays.value = r.keep_days } finally { loading.value = false }
}
async function download(f) {
  busy.value = f.id
  try {
    const blob = await api.get(`/exports/${f.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = Object.assign(document.createElement('a'), { href: url, download: `${f.name}.${f.format}` })
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } finally { busy.value = null }
}
async function remove(f) {
  if (!(await confirmBox(`حذف «${f.name}» من مركز التنزيل؟`))) return
  await api.delete(`/exports/${f.id}`)
  files.value = files.value.filter((x) => x.id !== f.id)
  ui.toast('حُذف الملف', 'ok')
}
onMounted(load)
</script>

<style scoped>
.fic { width: 36px; height: 36px; border-radius: 50%; background: var(--soft, #F1F3F9); display: grid; place-items: center; flex: none; color: var(--navy, #262E6B) }
.round { border-radius: 50%; width: 34px; height: 34px; padding: 0; display: grid; place-items: center }
.empty { text-align: center; padding: 40px 10px; color: var(--muted, #6B7094) }
.dl td { vertical-align: middle }
</style>
