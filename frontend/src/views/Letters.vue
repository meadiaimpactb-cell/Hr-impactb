<template>
  <div class="page-h">
    <div><h1>{{ t('الخطابات الرسمية') }}</h1><p>خطابات مرقّمة من قوالب قابلة للتحرير بمتغيرات، تُصدر من طلب معتمد أو مباشرة من الموارد البشرية، وتُوقَّع إلكترونيًا وتُطبع PDF</p></div>
    <div class="acts"><button v-if="auth.isHr" class="btn pri sm" @click="openIssue"><Icon name="plus" :size="16" /> إصدار خطاب</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <div v-if="tab !== 'templates'" class="card">
    <div v-if="!rows.length" class="empty"><div class="ic"><Icon name="signature" :size="40" :stroke="1.4" /></div>لا خطابات</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>الرقم</th><th v-if="tab === 'all'">الموظف</th><th>النوع</th><th>الجهة</th><th>اللغة</th><th>التاريخ</th><th>التوقيع</th><th /></tr></thead>
      <tbody><tr v-for="l in rows" :key="l.id"><td class="num"><b>{{ l.no }}</b></td><td v-if="tab === 'all'">{{ l.employee?.name }}</td><td>{{ l.kind_name }}<span v-if="l.includes_salary" class="pill warn">يتضمن الراتب</span></td><td>{{ l.to || '—' }}</td><td>{{ l.lang === 'en' ? 'EN' : 'ع' }}</td><td class="num">{{ l.issued_at }}</td><td><span class="pill" :class="l.signed ? 'ok' : 'gray'">{{ l.signed ? 'موقَّع' : 'غير موقَّع' }}</span></td>
        <td class="row" style="gap:4px;flex-wrap:nowrap"><RouterLink class="btn ghost xs" :to="`/print/letter/${l.id}`"><Icon name="external" :size="14" /> عرض / طباعة</RouterLink><button v-if="auth.isHr && !l.signed" class="btn sec xs" @click="sign(l)"><Icon name="signature" :size="14" /> توقيع</button></td></tr></tbody></table></div>
  </div>

  <div v-else>
    <div class="notice info">المتغيرات المتاحة: <code v-for="p in placeholders" :key="p" style="margin-inline-end:6px" v-text="'{{' + p + '}}'" /></div>
    <div class="card"><div v-dt class="tw"><table><thead><tr><th>النوع</th><th>اللغة</th><th>العنوان</th><th>النص</th><th>راتب</th><th>النسخة</th><th /></tr></thead>
      <tbody><tr v-for="tp in templates" :key="tp.id"><td><b>{{ kindName(tp.kind) }}</b><div class="tiny muted">{{ tp.company?.name || 'المجموعة (افتراضي)' }}</div></td><td>{{ tp.lang }}</td><td>{{ tp.title }}</td><td class="small" style="max-width:420px;white-space:pre-wrap">{{ tp.body }}</td><td>{{ tp.includes_salary ? '✓' : '—' }}</td><td>v{{ tp.version }}</td><td><button class="btn ghost xs" @click="tf = { ...tp }">تعديل</button></td></tr></tbody></table></div>
      <button class="btn sec sm" @click="tf = { kind: kinds[0]?.code, lang: 'ar', title: '', body: '', includes_salary: false, active: true }"><Icon name="plus" :size="14" /> قالب</button>
    </div>
  </div>

  <Modal v-if="isf" title="إصدار خطاب" @close="isf = null">
    <div class="fg"><label>الموظف *</label><SearchSelect v-model="isf.employee_id" :options="empOptions" /></div>
    <div class="fr"><div class="fg"><label>النوع</label><select v-model="isf.kind" class="in"><option v-for="k in kinds" :key="k.code" :value="k.code">{{ k.name }}</option></select></div><div class="fg"><label>اللغة</label><select v-model="isf.lang" class="in"><option value="ar">العربية</option><option value="en">English</option></select></div></div>
    <div class="fg"><label>الجهة الموجّه إليها</label><input v-model="isf.to" class="in" /></div>
    <template #foot><button class="btn sec" @click="isf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="issue">إصدار</button></template>
  </Modal>

  <Modal v-if="tf" :title="tf.id ? 'تعديل قالب' : 'قالب جديد'" size="wide" @close="tf = null">
    <div class="fr3"><div class="fg"><label>النوع</label><select v-model="tf.kind" class="in" :disabled="!!tf.id"><option v-for="k in kinds" :key="k.code" :value="k.code">{{ k.name }}</option></select></div><div class="fg"><label>اللغة</label><select v-model="tf.lang" class="in" :disabled="!!tf.id"><option value="ar">ar</option><option value="en">en</option></select></div><div class="fg"><label>العنوان *</label><input v-model="tf.title" class="in" /></div></div>
    <div class="fg"><label>الشركة <span class="tiny muted">(فارغ = قالب المجموعة الافتراضي؛ يُفضَّل قالب الشركة بهويتها عند وجوده)</span></label><select v-model="tf.company_id" class="in" :disabled="!!tf.id"><option :value="null">المجموعة — افتراضي</option><option v-for="c in auth.meta?.companies || []" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
    <div class="fg"><label>النص *</label><textarea v-model="tf.body" class="in" rows="10" /></div>
    <div class="row"><label class="chk"><input v-model="tf.includes_salary" type="checkbox" /> يتضمن الراتب (يتطلب صلاحية الرواتب)</label><label class="chk"><input v-model="tf.active" type="checkbox" /> مفعّل</label></div>
    <template #foot><button class="btn sec" @click="tf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveTpl">حفظ نسخة</button></template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const tabs = computed(() => [auth.emp && ['mine', 'خطاباتي'], auth.isApprover && ['all', 'كل الخطابات'], auth.isAdmin && ['templates', 'القوالب']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'all')
const rows = ref([]); const templates = ref([]); const kinds = ref([]); const placeholders = ref([]); const isf = ref(null); const tf = ref(null); const emps = ref([]); const busy = ref(false)
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const kindName = (c) => kinds.value.find((k) => k.code === c)?.name || c
async function load() {
  if (!kinds.value.length) { const r = await api.get('/letters/templates'); templates.value = r.templates; kinds.value = r.kinds; placeholders.value = r.placeholders }
  if (tab.value === 'mine') rows.value = await api.get('/letters/mine')
  else if (tab.value === 'all') rows.value = await api.get('/letters')
  else templates.value = (await api.get('/letters/templates')).templates
}
async function openIssue() { isf.value = { employee_id: null, kind: kinds.value[0]?.code, lang: 'ar', to: '' }; if (!emps.value.length) emps.value = await api.get('/employees/options') }
async function issue() { if (!isf.value.employee_id) return ui.toast('اختر الموظف', 'warn'); busy.value = true; try { const l = await api.post('/letters', isf.value); ui.toast(`صدر الخطاب ${l.no}`, 'ok'); isf.value = null; load() } catch (e) { ui.toast(e.message, 'bad', 6000) } finally { busy.value = false } }
async function sign(l) {
  const sig = await api.get('/signature')
  if (!sig.signature) return ui.toast('احفظ توقيعك أولًا من «ملفي»', 'warn', 5000)
  if (!await confirmBox(`توقيع الخطاب ${l.no} إلكترونيًا؟ يُثبَّت نص الخطاب ببصمة SHA-256.`)) return
  try { await api.post(`/letters/${l.id}/sign`); ui.toast('وُقّع الخطاب', 'ok'); load() } catch (e) { ui.toast(e.message, 'bad') }
}
async function saveTpl() { busy.value = true; try { if (tf.value.id) await api.put(`/letters/templates/${tf.value.id}`, tf.value); else await api.post('/letters/templates', tf.value); ui.toast('حُفظ القالب', 'ok'); tf.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false } }
onMounted(load)
</script>
