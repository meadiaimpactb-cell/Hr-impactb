<template>
  <div class="page-h"><div><h1>{{ t('الاستيراد') }}</h1><p>ملفات CSV بقوالب محددة الرؤوس: معاينة وتحقق صف بصف، كتابة الصفوف الصحيحة فقط، وإمكانية التراجع عن أي دفعة</p></div></div>
  <div class="grid side-grid">
    <div class="card" style="margin:0">
      <h3>القوالب</h3>
      <div v-for="tp in templates" :key="tp.key" class="row mb" style="justify-content:space-between"><label class="chk"><input v-model="tpl" type="radio" :value="tp.key" /> {{ tp.name }}</label><a class="btn ghost xs" :href="`/api/v1/import/templates/${tp.key}`" @click.prevent="downloadTpl(tp.key)"><Icon name="download" :size="13" /> CSV</a></div>
      <p v-if="cur?.hint" class="tiny muted">{{ cur.hint }}</p>
      <div class="fg mt"><label>الملف</label><input type="file" accept=".csv,text/csv" class="in" @change="file = $event.target.files[0]; preview = null" /></div>
      <div class="row"><button class="btn sec sm" :disabled="!file || !tpl" :class="{ loading: busy }" @click="doPreview">معاينة</button><button class="btn pri sm" :disabled="!preview || !preview.valid" :class="{ loading: busy }" @click="doCommit">استيراد {{ preview?.valid || '' }} صف</button></div>
      <div v-if="cur" class="tiny muted mt">الرؤوس المطلوبة: {{ cur.headers.join(' · ') }}</div>
    </div>
    <div class="card" style="margin:0">
      <div v-if="!preview" class="empty">اختر قالبًا وملفًا ثم «معاينة»</div>
      <template v-else>
        <div class="row mb"><span class="pill ok">صحيح {{ preview.valid }}</span><span class="pill bad">خطأ {{ preview.invalid }}</span><span class="tiny muted">تُكتب الصفوف الصحيحة فقط</span></div>
        <div v-dt class="tw" style="max-height:60vh;overflow:auto"><table><thead><tr><th>#</th><th v-for="h in cur.headers" :key="h">{{ h }}</th><th>الأخطاء</th></tr></thead>
          <tbody><tr v-for="r in preview.rows" :key="r.line" :class="{ 'row-bad': r.errors.length }"><td>{{ r.line }}</td><td v-for="(v, k) in r.data" :key="k" class="small">{{ v }}</td><td class="small bad">{{ r.errors.join(' · ') }}</td></tr></tbody></table></div>
      </template>
    </div>
  </div>
  <div class="card">
    <h3>سجل الدفعات</h3>
    <div v-if="!history.length" class="empty">لا دفعات</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>#</th><th>القالب</th><th>الملف</th><th>بواسطة</th><th>الإجمالي</th><th>صحيح</th><th>خطأ</th><th>الحالة</th><th>التاريخ</th><th /></tr></thead>
      <tbody><tr v-for="b in history" :key="b.id"><td>{{ b.id }}</td><td>{{ tplName(b.template) }}</td><td class="small">{{ b.file_name }}</td><td>{{ b.by }}</td><td>{{ b.total }}</td><td class="ok">{{ b.valid }}</td><td class="bad">{{ b.invalid }}</td><td><span class="pill" :class="b.status === 'done' ? 'ok' : 'gray'">{{ b.status === 'done' ? 'مكتملة' : 'مُلغاة' }}</span></td><td class="small">{{ fmtDT(b.created_at) }}</td><td><button v-if="b.status === 'done' && b.created_count" class="btn ghost xs bad" @click="undo(b)">تراجع ({{ b.created_count }})</button></td></tr></tbody></table></div>
  </div>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useUi } from '../stores/ui'
import { download, fmtDT } from '../utils'

const { t } = useI18n()
const ui = useUi()
const templates = ref([]); const tpl = ref('employees'); const file = ref(null); const preview = ref(null); const history = ref([]); const busy = ref(false)
const cur = computed(() => templates.value.find((x) => x.key === tpl.value))
const tplName = (k) => templates.value.find((x) => x.key === k)?.name || k
async function load() { ;[templates.value, history.value] = await Promise.all([api.get('/import/templates'), api.get('/import')]) }
async function downloadTpl(k) { const t = templates.value.find((x) => x.key === k); download(`import-${k}.csv`, t.headers.map((h) => `"${h}"`).join(',') + '\r\n') }
function fd() { const x = new FormData(); x.append('template', tpl.value); x.append('file', file.value); return x }
async function doPreview() { busy.value = true; try { preview.value = await api.post('/import/preview', fd()) } catch (e) { preview.value = null; ui.toast(e.message === 'رؤوس الأعمدة لا تطابق القالب تمامًا' ? e.message : (e.message || 'ملف غير صالح'), 'bad', 6000) } finally { busy.value = false } }
async function doCommit() { if (!await confirmBox(`استيراد ${preview.value.valid} صفًا؟`)) return; busy.value = true; try { const b = await api.post('/import/commit', fd()); ui.toast(`استُورد ${b.valid} صفًا (دفعة #${b.id})`, 'ok', 6000); preview.value = null; file.value = null; load() } catch (e) { ui.toast(e.message, 'bad') } finally { busy.value = false } }
async function undo(b) { if (!await confirmBox(`التراجع عن الدفعة #${b.id} وحذف ${b.created_count} سجلًا؟`)) return; const r = await api.post(`/import/${b.id}/undo`); ui.toast(`حُذف ${r.undone} سجلًا`, 'ok'); load() }
onMounted(load)
</script>

<style scoped>.row-bad td{background:var(--badbg)}</style>
