<template>
  <div class="page-h">
    <div><h1>{{ t('الشركات والباقات') }}</h1><p>تُضاف الشركات دون تعديل برمجي · المزايا تنزل تلقائيًا على موظفي كل باقة</p></div>
    <div class="acts"><button class="btn pri sm" @click="edit({})"><Icon name="plus" :size="16" /> شركة</button></div>
  </div>
  <div class="tabs"><button :class="{ on: tab === 'c' }" @click="tab = 'c'">الشركات</button><button :class="{ on: tab === 't' }" @click="tab = 't'">محرر مزايا الباقات</button></div>

  <div v-if="tab === 'c'" class="card">
    <DataTable :rows="companies" :columns="columns" :selectable="auth.has('super')" store-key="companies" export-name="companies" search-placeholder="ابحث بالرمز أو الاسم أو الموقع…" @row-click="view = $event">
      <template #bulk="{ selected, clear }">
        <button class="btn sec xs" @click="bulkActive(selected, true, clear)"><Icon name="check-circle" :size="14" /> تفعيل</button>
        <button class="btn sec xs" @click="bulkActive(selected, false, clear)"><Icon name="ban" :size="14" /> إيقاف</button>
        <select class="in xs" style="width:auto;min-height:28px" @change="bulkTier(selected, $event, clear)"><option value="">تغيير الباقة…</option><option v-for="tr in tiers" :key="tr.id" :value="tr.id">{{ tr.name }}</option></select>
      </template>
      <template #cell-code="{ row: c }"><b>{{ c.code }}</b><span v-if="c.is_holding" class="tiny muted">القابضة</span></template>
      <template #cell-name="{ row: c }">
        {{ c.name }}<span class="tiny muted">{{ c.name_en }}</span>
        <a v-if="c.website" :href="c.website" target="_blank" rel="noopener" class="site" dir="ltr" title="فتح موقع الشركة" @click.stop>{{ c.website.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '') }} <Icon name="external" :size="12" /></a>
      </template>
      <template #cell-ceo="{ row: c }">{{ c.ceo?.name || '—' }}</template>
      <template #cell-tier="{ row: c }"><span v-if="c.tier" class="pill" :style="{ background: c.tier.color + '22', color: c.tier.color }">{{ c.tier.name }}</span><span v-else class="pill warn">بلا باقة</span></template>
      <template #cell-status="{ row: c }"><span class="pill" :class="c.active ? 'ok' : 'gray'">{{ c.active ? 'نشطة' : 'موقوفة' }}</span></template>
      <template #cell-actions="{ row: c }">
        <div class="row" style="gap:2px;flex-wrap:nowrap;justify-content:flex-end" @click.stop>
          <a v-if="c.website" class="btn ghost xs" :href="c.website" target="_blank" rel="noopener" title="فتح الموقع"><Icon name="external" :size="15" /></a>
          <button class="btn ghost xs" title="عرض" @click="view = c"><Icon name="eye" :size="15" /></button>
          <button class="btn ghost xs" title="تعديل" @click="edit(c)"><Icon name="edit" :size="15" /></button>
          <ActionMenu :items="menu(c)" />
        </div>
      </template>
    </DataTable>
  </div>

  <Modal v-if="view" :title="view.name" @close="view = null">
    <div class="tw"><table class="kv">
      <tr><th>الرمز</th><td>{{ view.code }}{{ view.is_holding ? ' · الشركة القابضة' : '' }}</td></tr>
      <tr><th>الاسم بالإنجليزية</th><td>{{ view.name_en || '—' }}</td></tr>
      <tr><th>الموقع الإلكتروني</th><td><a v-if="view.website" :href="view.website" target="_blank" rel="noopener" dir="ltr">{{ view.website }} <Icon name="external" :size="12" /></a><span v-else>—</span></td></tr>
      <tr><th>الرئيس التنفيذي</th><td>{{ view.ceo?.name || '—' }}</td></tr>
      <tr><th>الباقة</th><td>{{ view.tier?.name || '—' }}</td></tr>
      <tr><th>عدد الموظفين</th><td>{{ view.employees_count }}</td></tr>
      <tr><th>السجل التجاري</th><td>{{ view.legal_no || '—' }}</td></tr>
      <tr><th>الرقم الضريبي</th><td>{{ view.tax_no || '—' }}</td></tr>
      <tr><th>العملة · المنطقة الزمنية</th><td>{{ view.currency }} · {{ view.timezone }}</td></tr>
      <tr><th>معرّف أودو</th><td>{{ view.odoo_id || '—' }}</td></tr>
      <tr><th>الحالة</th><td>{{ view.active ? 'نشطة' : 'موقوفة' }}</td></tr>
    </table></div>
    <template #foot>
      <RouterLink class="btn ghost" :to="{ path: '/employees' }" @click="ui.company = view.id">موظفو الشركة</RouterLink>
      <button class="btn sec" @click="view = null">إغلاق</button>
      <button class="btn pri" @click="edit(view); view = null"><Icon name="edit" :size="15" /> تعديل</button>
    </template>
  </Modal>

  <div v-else class="card">
    <h3>مصفوفة المزايا <span class="sub">تعديلها صلاحية المدير العام · كل حفظ ينشئ نسخة جديدة مع السبب</span></h3>
    <div v-dt class="tw"><table>
      <thead><tr><th>البند</th><th v-for="tr in tiers" :key="tr.id" :style="{ color: tr.color }">{{ tr.name }} <span class="tiny muted">v{{ tr.version }} · {{ tr.companies_count }} شركات</span></th></tr></thead>
      <tbody><tr v-for="k in benefitKeys" :key="k">
        <td><b>{{ BEN[k] || k }}</b><span class="tiny muted">{{ k }}</span></td>
        <td v-for="tr in tiers" :key="tr.id"><input v-model="tr.benefits[k]" class="in" style="min-height:32px;padding:3px 8px" :disabled="!auth.has('super')" /></td>
      </tr></tbody>
    </table></div>
    <div v-if="auth.has('super')" class="row mt">
      <input v-model="reason" class="in" style="max-width:340px" placeholder="سبب التعديل (إلزامي)" />
      <button class="btn pri sm" @click="saveTiers">حفظ نسخة جديدة</button>
      <span class="spacer" />
      <button class="btn sec sm" @click="newBenefit"><Icon name="plus" :size="16" /> بند مزايا جديد</button>
      <button class="btn sec sm" @click="newTier"><Icon name="plus" :size="16" /> باقة جديدة</button>
    </div>
  </div>

  <Modal v-if="form" :title="form.id ? 'تعديل شركة' : 'شركة جديدة'" @close="form = null">
    <div class="fr">
      <div class="fg"><label>الرمز *</label><input v-model="form.code" class="in" /></div>
      <div class="fg"><label>الاسم بالعربية *</label><input v-model="form.name" class="in" /></div>
      <div class="fg"><label>الاسم بالإنجليزية</label><input v-model="form.name_en" class="in" /></div>
      <div class="fg"><label>السجل التجاري</label><input v-model="form.legal_no" class="in" /></div>
      <div class="fg"><label>الرقم الضريبي</label><input v-model="form.tax_no" class="in" /></div>
      <div class="fg"><label>الموقع الإلكتروني</label><input v-model="form.website" class="in" dir="ltr" type="url" placeholder="https://impactv.sa" /></div>
      <div class="fg"><label>الباقة</label><select v-model="form.tier_id" class="in"><option v-for="tr in tiers" :key="tr.id" :value="tr.id">{{ tr.name }}</option></select></div>
      <div class="fg"><label>جدول الدوام الافتراضي <span class="tiny muted">(يُسند للموظف الجديد تلقائيًا)</span></label><select v-model="form.default_schedule_id" class="in"><option :value="null">—</option><option v-for="sc in auth.meta?.schedules || []" :key="sc.id" :value="sc.id">{{ sc.name }}</option></select></div>
      <div class="fg"><label>معرّف أودو</label><input v-model="form.odoo_id" class="in" /></div>
      <div class="fg"><label>الرئيس التنفيذي</label><SearchSelect v-model="form.ceo_employee_id" :options="empOptions" placeholder="ابحث عن موظف" /></div>
      <div class="fg"><label>العملة</label><input v-model="form.currency" class="in" maxlength="3" /></div>
      <div class="fg"><label>المنطقة الزمنية</label><input v-model="form.timezone" class="in" style="direction:ltr" /></div>
      <div class="fg"><label>قاعدة الاحتساب التناسبي</label><select v-model="form.prorate_rule" class="in"><option value="actual">أيام الشهر الفعلية</option><option value="30">قاعدة الثلاثين يومًا</option></select></div>
    </div>
    <label class="chk"><input v-model="form.active" type="checkbox" /> نشطة</label>
    <template #foot><button class="btn sec" @click="form = null">إلغاء</button><button class="btn pri" @click="saveCompany">حفظ</button></template>
  </Modal>
</template>

<script setup>
import { alertBox, confirmBox, promptBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import ActionMenu from '../components/ActionMenu.vue'
import DataTable from '../components/DataTable.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const tab = ref('c')
const companies = ref([])
const tiers = ref([])
const form = ref(null)
const reason = ref('')
const view = ref(null)
const columns = [
  { key: 'code', label: 'الرمز' },
  { key: 'name', label: 'الشركة', value: (c) => `${c.name} ${c.name_en || ''} ${c.website || ''}`, exportValue: (c) => c.name },
  { key: 'name_en', label: 'الاسم بالإنجليزية', hidden: true },
  { key: 'website', label: 'الموقع الإلكتروني', hidden: true },
  { key: 'ceo', label: 'الرئيس التنفيذي', value: (c) => c.ceo?.name },
  { key: 'tier', label: 'الباقة', value: (c) => c.tier?.name },
  { key: 'employees_count', label: 'الموظفون' },
  { key: 'legal_no', label: 'السجل التجاري' },
  { key: 'tax_no', label: 'الرقم الضريبي', hidden: true },
  { key: 'odoo_id', label: 'أودو', hidden: true },
  { key: 'status', label: 'الحالة', value: (c) => (c.active ? 'نشطة' : 'موقوفة') },
  { key: 'actions', label: '', sortable: false, search: false, export: false, class: 'dt-actions' },
]
const bodyOf = (c, patch = {}) => ({ code: c.code, name: c.name, name_en: c.name_en, legal_no: c.legal_no, tax_no: c.tax_no, website: c.website || null, tier_id: c.tier_id, default_schedule_id: c.default_schedule_id || null, odoo_id: c.odoo_id, prorate_rule: c.prorate_rule || 'actual', active: !!c.active, ceo_employee_id: c.ceo_employee_id || null, currency: c.currency || 'SAR', timezone: c.timezone || 'Asia/Riyadh', ...patch })
function menu(c) {
  return [
    { label: 'عرض البيانات', icon: 'eye', action: () => (view.value = c) },
    { label: 'تعديل', icon: 'edit', action: () => edit(c) },
    { label: 'فتح موقع الشركة', icon: 'external', hidden: !c.website, action: () => window.open(c.website, '_blank', 'noopener') },
    { divider: true },
    { label: c.active ? 'إيقاف الشركة' : 'تفعيل الشركة', icon: c.active ? 'ban' : 'check-circle', disabled: c.is_holding, action: () => setActive(c, !c.active) },
    { label: 'حذف الشركة', icon: 'trash', danger: true, hidden: !auth.has('super'), disabled: c.is_holding || c.employees_count > 0, action: () => remove(c) },
  ]
}
async function setActive(c, on) {
  if (!on && !await confirmBox(`إيقاف شركة «${c.name}»؟ تبقى بياناتها وموظفوها كما هم، ولا تظهر في قوائم الاختيار الجديدة.`)) return
  try { await api.put(`/companies/${c.id}`, bodyOf(c, { active: on })); ui.toast(on ? 'فُعّلت الشركة' : 'أُوقفت الشركة', 'ok'); load(); auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
async function remove(c) {
  if (!await confirmBox(`حذف شركة «${c.name}» نهائيًا؟`, { title: 'حذف الشركة', confirmText: 'حذف' })) return
  try { await api.delete(`/companies/${c.id}`); ui.toast('حُذفت الشركة', 'ok'); load(); auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
async function bulkActive(rows, on, clear) {
  const list = rows.filter((c) => !c.is_holding)
  if (!list.length || !await confirmBox(`${on ? 'تفعيل' : 'إيقاف'} ${list.length} شركة؟`, { danger: !on })) return
  for (const c of list) await api.put(`/companies/${c.id}`, bodyOf(c, { active: on }))
  ui.toast('تم', 'ok'); clear(); load(); auth.loadMeta()
}
async function bulkTier(rows, ev, clear) {
  const tierId = +ev.target.value; ev.target.value = ''
  const tr = tiers.value.find((x) => x.id === tierId); if (!tr) return
  if (!await confirmBox(`ربط ${rows.length} شركة بباقة «${tr.name}»؟ تتحدث أرصدة وبدلات موظفيها فورًا.`)) return
  for (const c of rows) await api.put(`/companies/${c.id}`, bodyOf(c, { tier_id: tierId }))
  ui.toast('حُدّثت الباقات', 'ok'); clear(); load(); auth.loadMeta()
}
const BEN = computed(() => auth.meta?.benefit_labels || {})
const benefitKeys = computed(() => Object.keys(tiers.value[0]?.benefits || {}))
async function load() { [companies.value, tiers.value] = await Promise.all([api.get('/companies'), api.get('/tiers')]) }
const empOptions = ref([])
const edit = async (c) => {
  if (!empOptions.value.length) empOptions.value = (await api.get('/employees/options')).map((e) => ({ value: e.id, label: e.name, sub: e.emp_no }))
  form.value = { active: true, prorate_rule: 'actual', currency: 'SAR', timezone: 'Asia/Riyadh', tier_id: tiers.value.find((x) => x.is_default)?.id || tiers.value[0]?.id, ...c }
}
async function newTier() {
  const name = await promptBox('اسم الباقة الجديدة', { multiline: false, confirmText: 'إضافة' })
  if (!name) return
  const copy = tiers.value[tiers.value.length - 1]
  await api.post('/tiers', { name, copy_from: copy?.id, reason: 'إنشاء باقة جديدة' })
  ui.toast(`أُنشئت الباقة بنسخ مزايا «${copy?.name}» — عدّل القيم ثم احفظ`, 'ok', 5000)
  load()
}
async function newBenefit() {
  // Arabic name only: the internal code is generated, and the item is saved to every package right away
  const label = ((await promptBox('اسم بند المزايا الجديد', { text: 'مثال: بدل نادي رياضي', multiline: false, confirmText: 'التالي' })) || '').trim()
  if (!label) return
  const labels = { ...(auth.meta?.benefit_labels || {}) }
  if (Object.values(labels).some((l) => String(l).trim() === label)) return ui.toast('يوجد بند بهذا الاسم', 'warn')
  const raw = ((await promptBox(`القيمة الافتراضية لـ«${label}» في كل الباقات`, { text: 'رقم (مبلغ أو عدد أيام)، ويمكن تعديله لكل باقة لاحقًا', multiline: false, required: false, confirmText: 'إضافة البند' })) ?? '0').trim()
  const val = raw === '' ? 0 : Number(raw.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
  if (Number.isNaN(val)) return ui.toast('القيمة يجب أن تكون رقمًا', 'warn')
  let key = 'custom' + Date.now().toString(36)
  while (benefitKeys.value.includes(key)) key += '1'
  const reason = `بند مزايا جديد: ${label}`
  await api.put('/settings', { key: 'benefitLabels', value: { ...labels, [key]: label }, reason })
  for (const tr of tiers.value) await api.put(`/tiers/${tr.id}`, { benefits: { ...tr.benefits, [key]: val }, reason })
  await Promise.all([auth.loadMeta(), load()])
  ui.toast(`أُضيف «${label}» إلى كل الباقات وحُفظ — عدّل قيمته لأي باقة ثم «حفظ نسخة جديدة»`, 'ok', 6000)
}
async function saveCompany() {
  const f = form.value
  const body = bodyOf(f)
  if (f.id) await api.put(`/companies/${f.id}`, body); else await api.post('/companies', body)
  ui.toast('تم الحفظ', 'ok'); form.value = null; load(); auth.loadMeta()
}
async function saveTiers() {
  if (!reason.value.trim()) return ui.toast('سبب التعديل إلزامي', 'warn')
  const cast = (v) => (v === 'true' ? true : v === 'false' ? false : v !== '' && !isNaN(v) ? Number(v) : v)
  for (const tr of tiers.value) await api.put(`/tiers/${tr.id}`, { benefits: Object.fromEntries(Object.entries(tr.benefits).map(([k, v]) => [k, cast(v)])), reason: reason.value })
  ui.toast('حُفظت الباقات', 'ok'); reason.value = ''; load()
}
onMounted(load)
</script>

<style scoped>
.site { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; margin-top: 2px; }
.site:hover { text-decoration: underline; }
</style>
