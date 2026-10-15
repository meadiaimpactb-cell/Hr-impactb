<template>
  <div class="page-h">
    <div><h1>{{ t('الوثائق والتنبيهات') }}</h1><p>وثائق الموظفين وأوراق الشركات بحالات انتهاء من مواعيد التنبيه في الإعدادات · تنبيهات يومية لمتابع الوثيقة والموظف والموارد البشرية · الأنواع والإلزامية من القوائم العامة</p></div>
    <div class="acts"><button class="btn pri sm" @click="openForm()"><Icon name="plus" :size="16" /> وثيقة</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <template v-if="tab === 'mine'">
    <div class="card">
      <div v-if="!mine.length" class="empty"><div class="ic"><Icon name="folder" :size="40" :stroke="1.4" /></div>لا وثائق مسجلة — ارفع هويتك وعقدك من زر «وثيقة»</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>النوع</th><th>الرقم</th><th>الإصدار</th><th>الانتهاء</th><th>الحالة</th><th>ملف</th><th /></tr></thead>
        <tbody><tr v-for="d in mine" :key="d.id"><td><b>{{ d.type_name }}</b></td><td class="num">{{ d.number || '—' }}</td><td class="num">{{ d.issue_date || '—' }}</td><td class="num">{{ d.expiry_date || '—' }}</td><td><span class="pill" :class="d.state.color">{{ d.state.label }}<span v-if="d.state.days !== null"> ({{ d.state.days }} يوم)</span></span></td><td><a v-if="d.has_file" class="btn ghost xs" href="#" @click.prevent="fileUrl(d.file)"><Icon name="download" :size="14" /></a></td><td class="row" style="gap:4px;flex-wrap:nowrap"><button class="btn ghost xs" @click="openForm(d)"><Icon name="edit" :size="14" /></button><button class="btn sec xs" @click="renewForm = { id: d.id, number: '', expiry_date: '' }">تجديد</button></td></tr></tbody></table></div>
    </div>
  </template>

  <template v-else>
    <div class="grid g4 mb">
      <Kpi label="منتهية" :value="rows.filter((d) => d.state.code === 'expired').length" color="r" /><Kpi label="حرجة / قريبة" :value="rows.filter((d) => ['critical', 'soon'].includes(d.state.code)).length" color="y" />
      <Kpi label="تحتاج متابعة" :value="rows.filter((d) => d.state.code === 'follow').length" color="b" /><Kpi label="إلزامية ناقصة" :value="missing.length" color="p" />
    </div>
    <div class="card">
      <div class="row mb"><select v-model="stateFilter" class="in" style="width:auto"><option value="">كل الحالات</option><option value="expired">منتهية</option><option value="critical">حرجة</option><option value="soon">قريبة الانتهاء</option><option value="follow">تحتاج متابعة</option><option value="valid">سارية</option></select><div class="search"><Icon name="search" :size="16" /><input v-model="q" class="in" placeholder="بحث…" /></div></div>
      <div v-if="!filtered.length" class="empty">لا وثائق</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>{{ tab === 'company' ? 'الشركة' : 'الموظف' }}</th><th>النوع</th><th>الرقم</th><th>الانتهاء</th><th>الحالة</th><th>المتابع</th><th>ملف</th><th /></tr></thead>
        <tbody><tr v-for="d in filtered" :key="d.id"><td><b>{{ d.owner?.name }}</b></td><td>{{ d.type_name }}</td><td class="num">{{ d.number || '—' }}</td><td class="num">{{ d.expiry_date || '—' }}</td><td><span class="pill" :class="d.state.color">{{ d.state.label }}<span v-if="d.state.days !== null"> ({{ d.state.days }})</span></span></td><td class="small">{{ userName(d.follow_user_id) }}</td><td><a v-if="d.has_file" class="btn ghost xs" href="#" @click.prevent="fileUrl(d.file)"><Icon name="download" :size="14" /></a></td><td class="row" style="gap:4px;flex-wrap:nowrap"><button class="btn ghost xs" @click="openForm(d)"><Icon name="edit" :size="14" /></button><button class="btn sec xs" @click="renewForm = { id: d.id, number: '', expiry_date: '' }">تجديد</button><button class="btn ghost xs bad" @click="remove(d)"><Icon name="trash" :size="14" /></button></td></tr></tbody></table></div>
    </div>
    <div v-if="missing.length" class="card">
      <h3>وثائق إلزامية ناقصة</h3>
      <div v-dt class="tw"><table><thead><tr><th>{{ tab === 'company' ? 'الشركة' : 'الموظف' }}</th><th>الوثيقة</th><th /></tr></thead><tbody><tr v-for="(m, i) in missing" :key="i"><td><b>{{ m.owner }}</b></td><td>{{ m.type_name }}</td><td><button class="btn sec xs" @click="openForm({ scope: tab, owner_id: m.owner_id, type: m.type })">إضافة</button></td></tr></tbody></table></div>
    </div>
  </template>

  <Modal v-if="f" :title="f.id ? 'تعديل وثيقة' : 'وثيقة جديدة'" @close="f = null">
    <div class="fr">
      <div class="fg"><label>النطاق</label><select v-model="f.scope" class="in" :disabled="!!f.id || !auth.isApprover"><option value="employee">موظف</option><option value="company">شركة</option></select></div>
      <div v-if="f.scope === 'company'" class="fg"><label>الشركة *</label><select v-model="f.owner_id" class="in" :disabled="!!f.id"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <div v-else-if="auth.isApprover" class="fg"><label>الموظف *</label><SearchSelect v-model="f.owner_id" :options="empOptions" /></div>
      <div class="fg"><label>النوع *</label><select v-model="f.type" class="in"><option v-for="tp in typesFor(f.scope)" :key="tp.code" :value="tp.code">{{ tp.name }}{{ tp.meta?.mandatory ? ' *' : '' }}</option></select></div>
      <div class="fg"><label>الرقم</label><input v-model="f.number" class="in" /></div>
      <div class="fg"><label>تاريخ الإصدار</label><DateInput v-model="f.issue_date" /></div>
      <div class="fg"><label>تاريخ الانتهاء</label><DateInput v-model="f.expiry_date" /></div>
      <div class="fg"><label>الجهة المُصدرة</label><input v-model="f.issuer" class="in" /></div>
      <div v-if="auth.isApprover" class="fg"><label>متابع التجديد</label><SearchSelect v-model="f.follow_user_id" :options="userOptions" /></div>
      <div class="fg"><label>الملف</label><input type="file" class="in" @change="file = $event.target.files[0]" /></div>
    </div>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">حفظ</button></template>
  </Modal>

  <Modal v-if="renewForm" title="تجديد الوثيقة" size="narrow" @close="renewForm = null">
    <div class="fg"><label>الرقم الجديد</label><input v-model="renewForm.number" class="in" /></div>
    <div class="fg"><label>تاريخ الإصدار</label><DateInput v-model="renewForm.issue_date" /></div>
    <div class="fg"><label>تاريخ الانتهاء الجديد *</label><DateInput v-model="renewForm.expiry_date" /></div>
    <div class="fg"><label>الملف الجديد</label><input type="file" class="in" @change="file = $event.target.files[0]" /></div>
    <template #foot><button class="btn sec" @click="renewForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="renew">تجديد</button></template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { fileUrl } from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { norm } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const tabs = computed(() => [auth.emp && ['mine', 'وثائقي'], auth.isApprover && ['employee', 'وثائق الموظفين'], auth.isApprover && ['company', 'أوراق الشركات']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'employee')
const mine = ref([]); const rows = ref([]); const missing = ref([]); const types = ref([]); const stateFilter = ref(''); const q = ref('')
const f = ref(null); const renewForm = ref(null); const file = ref(null); const busy = ref(false); const emps = ref([]); const users = ref([])
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const userOptions = computed(() => users.value.map((u) => ({ value: u.id, label: u.name })))
const userName = (id) => users.value.find((u) => u.id === id)?.name || '—'
const typesFor = (scope) => (auth.meta?.lookups?.document_types || []).filter((x) => (x.meta?.scope || 'employee') === scope)
const filtered = computed(() => rows.value.filter((d) => (!stateFilter.value || d.state.code === stateFilter.value) && (!q.value || norm((d.owner?.name || '') + ' ' + d.type_name + ' ' + (d.number || '')).includes(norm(q.value)))))
async function load() {
  if (tab.value === 'mine') mine.value = await api.get('/documents/mine')
  else { const r = await api.get('/documents', { params: { scope: tab.value } }); rows.value = r.rows; missing.value = r.missing; types.value = r.types }
  if (auth.isApprover && !emps.value.length) [emps.value, users.value] = await Promise.all([api.get('/employees/options'), api.get('/users/options')])
}
function openForm(d) { file.value = null; f.value = d?.id ? { ...d } : { scope: d?.scope || (tab.value === 'company' ? 'company' : 'employee'), owner_id: d?.owner_id || (tab.value === 'mine' ? auth.emp.id : null), type: d?.type || typesFor(d?.scope || 'employee')[0]?.code, number: '', issue_date: '', expiry_date: '', issuer: '', follow_user_id: null } }
function fd(obj) { const x = new FormData(); Object.entries(obj).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '' && typeof v !== 'object') x.append(k, v) }); if (file.value) x.append('attachment', file.value); return x }
async function save() {
  if (!f.value.owner_id || !f.value.type) return ui.toast('حدد المالك والنوع', 'warn')
  busy.value = true
  try { await api.post(f.value.id ? `/documents/${f.value.id}` : '/documents', fd(f.value)); ui.toast('تم الحفظ', 'ok'); f.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function renew() {
  if (!renewForm.value.expiry_date) return ui.toast('تاريخ الانتهاء الجديد إلزامي', 'warn')
  busy.value = true
  try { await api.post(`/documents/${renewForm.value.id}/renew`, fd(renewForm.value)); ui.toast('جُدّدت الوثيقة', 'ok'); renewForm.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function remove(d) { if (!await confirmBox('حذف الوثيقة؟')) return; await api.delete(`/documents/${d.id}`); load() }
onMounted(load)
</script>
