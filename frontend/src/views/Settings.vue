<template>
  <div class="page-h">
    <div><h1>{{ t('الإعدادات والسياسات') }}</h1><p>كل قاعدة قابلة للتعديل دون برمجة · لكل تعديل نسخة وتاريخ سريان وسبب · يمكن تخصيص أي قاعدة لشركة · تعريفات القواعد نفسها (الاسم والمجموعة) بيانات قابلة للتحرير</p></div>
    <div v-if="auth.has('super')" class="acts"><button class="btn pri sm" @click="defForm = { key: '', label: '', label_en: '', group: '', type: 'text', description: '' }"><Icon name="plus" :size="16" /> قاعدة جديدة</button></div>
  </div>
  <div v-if="!rows.length"><div class="skel" v-for="i in 6" :key="i" /></div>
  <div v-for="(items, g) in groups" :key="g" class="card">
    <h3>{{ g }}</h3>
    <div v-dt class="tw"><table>
      <thead><tr><th style="width:34%">القاعدة</th><th>القيمة</th><th>تخصيصات الشركات</th><th>النسخة</th><th /></tr></thead>
      <tbody><tr v-for="s in items" :key="s.key">
        <td><b>{{ ui.lang === 'en' && s.label_en ? s.label_en : s.label }}</b><span class="tiny muted">{{ s.key }}{{ s.description ? ' · ' + s.description : '' }}{{ s.reason ? ' · ' + s.reason : '' }}</span></td>
        <td class="small">
          <span v-if="s.type === 'bool'" class="pill" :class="s.value ? 'ok' : 'gray'">{{ s.value ? 'مفعّل' : 'معطّل' }}</span>
          <span v-else-if="s.type === 'list'">{{ (s.value || []).join('، ') || '—' }}</span>
          <span v-else-if="s.type === 'json'">{{ Object.keys(s.value || {}).length }} عنصر</span>
          <b v-else class="num">{{ s.value ?? '—' }}</b>
        </td>
        <td class="small">{{ s.overrides.map((o) => `${companyName(o.company_id)}: ${fmt(s, o.value)}`).join(' · ') || '—' }}</td>
        <td class="small">{{ s.version ? 'v' + s.version : 'افتراضي' }}</td>
        <td class="row" style="gap:4px;flex-wrap:nowrap">
          <button class="btn ghost xs" @click="edit(s)">تعديل القيمة</button>
          <button v-if="auth.has('super')" class="btn ghost xs" title="تعديل التعريف" @click="defForm = { ...s }"><Icon name="settings" :size="14" /></button>
        </td>
      </tr></tbody>
    </table></div>
  </div>

  <Modal v-if="f" :title="`تعديل: ${f.label}`" @close="f = null">
    <div class="fg"><label>النطاق</label>
      <select v-model="f.company_id" class="in" @change="pickScope"><option :value="null">المجموعة (القيمة العامة)</option><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">تخصيص: {{ c.name }}</option></select>
    </div>
    <label v-if="f.type === 'bool'" class="chk"><input v-model="f.value" type="checkbox" /> مفعّل</label>
    <div v-else-if="f.type === 'number'" class="fg"><label>القيمة</label><input v-model.number="f.value" type="number" step="any" class="in" /></div>
    <div v-else-if="f.type === 'list'" class="fg"><label>العناصر (سطر لكل عنصر)</label><textarea v-model="f.text" class="in" rows="6" /></div>
    <div v-else-if="f.type === 'json'" class="fg"><label>المفتاح = القيمة (سطر لكل عنصر)</label><textarea v-model="f.text" class="in" rows="10" /></div>
    <div v-else-if="f.type === 'secret'" class="fg"><label>القيمة السرية (تُحفظ ولا تُعرض مجددًا)</label><input v-model="f.value" type="password" class="in" style="direction:ltr" autocomplete="new-password" /></div>
    <div v-else class="fg"><label>القيمة</label><input v-model="f.value" class="in" /></div>
    <div class="fg"><label>سبب التعديل *</label><input v-model="f.reason" class="in" /></div>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" @click="save">حفظ نسخة جديدة</button></template>
  </Modal>

  <Modal v-if="defForm" :title="defForm.id || defForm.builtin !== undefined && defForm.version !== undefined ? `تعريف القاعدة: ${defForm.key}` : 'قاعدة جديدة'" @close="defForm = null">
    <div class="fr">
      <div class="fg"><label>المفتاح (لاتيني) *</label><input v-model="defForm.key" class="in" style="direction:ltr" :disabled="defForm.builtin || !!rows.find((r) => r.key === defForm.key && defForm.version !== undefined)" /></div>
      <div class="fg"><label>النوع</label><select v-model="defForm.type" class="in" :disabled="defForm.builtin"><option v-for="(l, k) in TYPES" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="fg"><label>الاسم *</label><input v-model="defForm.label" class="in" /></div>
      <div class="fg"><label>الاسم بالإنجليزية</label><input v-model="defForm.label_en" class="in" /></div>
      <div class="fg"><label>المجموعة *</label><input v-model="defForm.group" class="in" list="groups" /><datalist id="groups"><option v-for="g in Object.keys(groups)" :key="g" :value="g" /></datalist></div>
    </div>
    <div class="fg"><label>الوصف</label><input v-model="defForm.description" class="in" /></div>
    <p v-if="defForm.builtin" class="tiny muted">قاعدة مدمجة يقرؤها النظام: يمكن تغيير اسمها ومجموعتها ووصفها، لا مفتاحها أو نوعها.</p>
    <template #foot>
      <button v-if="rows.find((r) => r.key === defForm.key) && !defForm.builtin" class="btn dan" @click="removeDef">حذف</button>
      <button class="btn sec" @click="defForm = null">إلغاء</button><button class="btn pri" @click="saveDef">حفظ</button>
    </template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Modal from '../components/Modal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const TYPES = { number: 'رقم', bool: 'تفعيل/تعطيل', list: 'قائمة', json: 'مفاتيح وقيم', text: 'نص', secret: 'سري' }
const rows = ref([])
const f = ref(null)
const defForm = ref(null)
const groups = computed(() => rows.value.reduce((a, s) => ((a[s.group] ||= []).push(s), a), {}))
const companyName = (id) => auth.meta.companies.find((c) => c.id === id)?.name || id
const fmt = (s, v) => (s.type === 'bool' ? (v ? 'مفعّل' : 'معطّل') : Array.isArray(v) ? v.join('، ') : typeof v === 'object' ? '…' : v)
const toText = (s, v) => (s.type === 'list' ? (v || []).join('\n') : s.type === 'json' ? Object.entries(v || {}).map(([k, x]) => `${k} = ${typeof x === 'object' ? JSON.stringify(x) : x}`).join('\n') : '')

async function load() { rows.value = await api.get('/settings') }
function edit(s) { f.value = { ...s, company_id: null, text: toText(s, s.value), value: s.type === 'secret' ? '' : s.value, reason: '' } }
function pickScope() {
  const s = rows.value.find((x) => x.key === f.value.key)
  const o = s.overrides.find((x) => x.company_id === f.value.company_id)
  const v = o ? o.value : s.value
  f.value.value = v
  f.value.text = toText(s, v)
}
async function save() {
  const x = f.value
  if (!x.reason.trim()) return ui.toast('سبب التعديل إلزامي', 'warn')
  let value = x.value
  if (x.type === 'list') value = x.text.split('\n').map((s) => s.trim()).filter(Boolean).map((s) => (s !== '' && !isNaN(s) ? Number(s) : s))
  if (x.type === 'json') value = Object.fromEntries(x.text.split('\n').map((l) => l.split('=')).filter((p) => p.length >= 2).map(([k, ...v]) => { const raw = v.join('=').trim(); try { return [k.trim(), raw.startsWith('{') || raw.startsWith('[') ? JSON.parse(raw) : raw] } catch { return [k.trim(), raw] } }))
  if (x.type === 'bool') value = !!value
  await api.put('/settings', { key: x.key, value, company_id: x.company_id, reason: x.reason })
  ui.toast('حُفظت القاعدة بنسخة جديدة', 'ok')
  f.value = null
  load()
  auth.loadMeta()
}
async function saveDef() {
  const d = defForm.value
  if (!d.key || !d.label || !d.group) return ui.toast('المفتاح والاسم والمجموعة إلزامية', 'warn')
  const body = { key: d.key, label: d.label, label_en: d.label_en, group: d.group, type: d.type, description: d.description }
  const exists = rows.value.find((r) => r.key === d.key)
  try {
    if (exists) await api.put(`/settings/definitions/${d.key}`, body); else await api.post('/settings/definitions', body)
    ui.toast('حُفظ التعريف', 'ok'); defForm.value = null; load()
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') }
}
async function removeDef() {
  if (!await confirmBox(`حذف القاعدة «${defForm.value.label}» وقيمها؟`)) return
  await api.delete(`/settings/definitions/${defForm.value.key}`)
  ui.toast('حُذفت'); defForm.value = null; load()
}
onMounted(load)
</script>
