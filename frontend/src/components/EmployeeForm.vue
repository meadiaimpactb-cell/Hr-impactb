<template>
  <Modal :title="id ? 'تعديل ملف موظف' : 'إضافة موظف جديد'" size="wide" @close="$emit('close')">
    <div v-if="!ready"><div class="skel" v-for="i in 6" :key="i" /></div>
    <template v-else>
      <h4 class="muted" style="margin:0 0 6px">البيانات الشخصية</h4>
      <div class="fr3">
        <F label="الاسم بالعربية" req k="name" /><F label="الاسم بالإنجليزية" k="name_en" /><div class="fg"><label>رقم الموظف <span class="tiny muted">{{ id ? '' : '(يُولَّد تلقائيًا ويمكن تعديله)' }}</span></label><div class="row" style="gap:4px;flex-wrap:nowrap"><input v-model.trim="f.emp_no" class="in" :class="{ err: errors.emp_no }" dir="ltr" /><button v-if="!id" type="button" class="btn ghost xs" title="توليد رقم جديد" @click="nextNo"><Icon name="refresh" :size="14" /></button></div><span v-if="errors.emp_no" class="e">{{ errors.emp_no[0] }}</span></div>
        <div class="fg"><label>رقم الهوية / الإقامة</label><input :value="f.national_id" class="in num" :class="{ err: errors.national_id }" inputmode="numeric" maxlength="20" placeholder="أرقام فقط" @input="f.national_id = $event.target.value.replace(/\D/g, '')" /><span v-if="errors.national_id" class="e">{{ errors.national_id[0] }}</span></div>
        <div class="fg"><label>الجنسية</label><SearchSelect v-model="f.nationality" :options="countries" placeholder="اختر الدولة…" /></div>
        <F label="تاريخ الميلاد" k="birth_date" type="date" />
        <div class="fg"><label>الجنس</label><select v-model="f.gender" class="in"><option value="m">ذكر</option><option value="f">أنثى</option></select></div>
        <div class="fg"><label>الجوال</label><PhoneInput v-model="f.phone" :error="!!errors.phone" /><span v-if="errors.phone" class="e">{{ errors.phone[0] }}</span></div>
        <div class="fg"><label>البريد الرسمي <span v-if="f.email_enabled" class="req">*</span></label><input v-model.trim="f.email" class="in" :class="{ err: errors.email }" type="email" dir="ltr" placeholder="name@impactv.sa" @blur="checkEmail" /><span v-if="errors.email" class="e">{{ errors.email[0] }}</span></div>
      </div>
      <label class="chk"><input v-model="f.email_enabled" type="checkbox" /> البريد مفعّل (ألغِ التفعيل للعمال بلا بريد — الدخول برقم الموظف)</label>

      <h4 class="muted" style="margin:12px 0 6px">الوظيفة والتعاقد</h4>
      <div class="fr3">
        <div v-if="auth.isAdmin" class="fg"><label>الشركة القانونية <span class="req">*</span></label><SearchSelect v-model="f.company_id" :options="opts.companies" :error="!!errors.company_id" :add-label="canAdd ? 'إضافة شركة' : ''" @add="openQuick('company', $event)" /></div>
        <div class="fg"><label>الوظيفة</label><SearchSelect v-model="f.job_title_id" :options="opts.jobs" :add-label="canAdd ? 'إضافة وظيفة' : ''" @add="openQuick('job', $event)" /></div>
        <div class="fg"><label>الإدارة</label><SearchSelect v-model="f.dept" :options="opts.depts" :add-label="canAdd ? 'إضافة إدارة' : ''" @add="addDept" /></div>
        <div class="fg"><label>المدير المباشر</label><SearchSelect v-model="f.manager_id" :options="opts.emps" :add-label="canAdd ? 'إضافة مدير جديد' : ''" @add="openQuick('manager', $event)" /></div>
        <F label="تاريخ الالتحاق" req k="hire_date" type="date" />
        <div class="fg"><label>نوع التعاقد</label><SearchSelect v-model="f.contract_type" :options="opts.contracts" :error="!!errors.contract_type" :add-label="canAdd ? 'إضافة نوع تعاقد' : ''" @add="addContract" /></div>
        <F v-if="f.contract_type === 'part'" label="نسبة الدوام (0.5 = نصف)" k="part_ratio" type="number" />
        <div class="fg" style="grid-column:1/-1">
          <label>ساعات الدوام</label>
          <div class="row" style="gap:12px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:240px"><SearchSelect v-model="f.schedule_id" :options="opts.schedules" placeholder="جدول الدوام المعتمد…" :add-label="canAdd ? 'إضافة جدول دوام' : ''" @add="openQuick('schedule', $event)" /></div>
            <label class="chk"><input v-model="customHours" type="checkbox" @change="toggleCustom" /> تخصيص ساعات هذا الموظف</label>
          </div>
          <HoursTable v-model="hoursView" :disabled="!customHours" class="mt" />
          <p class="tiny muted" style="margin:4px 0 0">{{ customHours ? 'الساعات أدناه خاصة بهذا الموظف وتتقدم على الجدول.' : 'الساعات المعروضة من الجدول المختار؛ فعّل التخصيص لتغييرها لهذا الموظف فقط.' }}</p>
        </div>
        <div class="fg">
          <label>مواقع التحضير</label>
          <SearchSelect :model-value="null" :options="opts.locations.filter((l) => !f.location_ids.includes(l.value))" placeholder="أضف موقعًا…" :add-label="canAdd ? 'إضافة موقع على الخريطة' : ''" @update:model-value="(v) => v && f.location_ids.push(v)" @add="openQuick('location', $event)" />
          <div v-if="f.location_ids.length" class="row" style="gap:6px;margin-top:6px"><span v-for="id in f.location_ids" :key="id" class="pill info">{{ locName(id) }} <a style="cursor:pointer" @click="f.location_ids = f.location_ids.filter((x) => x !== id)">×</a></span></div>
        </div>
        <F v-if="!id" label="الأجر الأساسي (ر.س) — اختياري" k="basic" type="number" />
        <div v-if="!id" class="fg" style="grid-column:1/-1">
          <label>البدلات <span class="tiny muted">(اختياري — أي عدد. بدل السكن والنقل والاتصال تُحسب من باقة الشركة ما لم تكتبها هنا)</span></label>
          <div v-for="(al, i) in f.allowances" :key="i" class="row" style="gap:6px;flex-wrap:nowrap;margin-bottom:6px">
            <input v-model="al.name" class="in" list="alw-names" placeholder="اسم البدل، مثال: بدل سكن" style="flex:2" />
            <input v-model.number="al.amount" type="number" min="0" class="in" placeholder="المبلغ" style="flex:1" />
            <button type="button" class="btn ghost xs bad" title="حذف" @click="f.allowances.splice(i, 1)"><Icon name="trash" :size="14" /></button>
          </div>
          <datalist id="alw-names"><option>بدل السكن</option><option>بدل النقل</option><option>بدل الاتصال</option><option>بدل طبيعة عمل</option><option>بدل غلاء معيشة</option><option>حوافز فردية</option></datalist>
          <button type="button" class="btn sec xs" @click="f.allowances.push({ name: '', amount: null })"><Icon name="plus" :size="14" /> إضافة بدل</button>
          <div class="notice info small mt" style="margin-bottom:0">الراتب الإجمالي = الأساسي + البدلات: <b class="num">{{ grossPreview.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</b> ر.س <span class="tiny muted">(بدلات الباقة غير المكتوبة هنا تُضاف تلقائيًا عند الحفظ)</span></div>
        </div>
        <div class="fg"><label>الآيبان (IBAN)</label><IbanInput v-model="f.bank_iban" :error="!!errors.bank_iban" /><span v-if="errors.bank_iban" class="e">{{ errors.bank_iban[0] }}</span></div>
      </div>

      <!-- inline creation panels: nothing leaves this form -->
      <div v-if="quick" ref="quickEl" class="quick">
        <div class="row mb" style="justify-content:space-between"><b>{{ QUICK_TITLES[quick.kind] }}</b><button class="btn ghost xs" @click="quick = null"><Icon name="x" :size="14" /></button></div>
        <template v-if="quick.kind === 'company'">
          <div class="fr3"><div class="fg"><label>الاسم *</label><input v-model="quick.d.name" class="in" /></div><div class="fg"><label>الاسم بالإنجليزية</label><input v-model="quick.d.name_en" class="in" /></div><div class="fg"><label>الرمز (لاتيني) *</label><input v-model="quick.d.code" class="in" dir="ltr" placeholder="مثال: IRTH" /></div>
            <div class="fg"><label>الموقع الإلكتروني</label><input v-model="quick.d.website" class="in" dir="ltr" type="url" placeholder="impactv.sa" /></div><div class="fg"><label>السجل التجاري</label><input v-model="quick.d.legal_no" class="in" dir="ltr" /></div><div class="fg"><label>الرقم الضريبي</label><input v-model="quick.d.tax_no" class="in" dir="ltr" /></div></div>
        </template>
        <template v-else-if="quick.kind === 'job'">
          <div class="fr3"><div class="fg"><label>المسمى *</label><input v-model="quick.d.name" class="in" /></div><div class="fg"><label>المسمى بالإنجليزية</label><input v-model="quick.d.name_en" class="in" /></div><div class="fg"><label>الرمز (لاتيني) *</label><input v-model="quick.d.code" class="in" dir="ltr" placeholder="مثال: ACC" /></div></div>
        </template>
        <template v-else-if="quick.kind === 'manager'">
          <div class="fr3">
            <div class="fg"><label>اسم المدير *</label><input v-model="quick.d.name" class="in" /></div><div class="fg"><label>رقم الموظف *</label><input v-model="quick.d.emp_no" class="in" dir="ltr" /></div>
            <div class="fg"><label>الشركة *</label><SearchSelect v-model="quick.d.company_id" :options="opts.companies" /></div>
            <div class="fg"><label>الوظيفة</label><SearchSelect v-model="quick.d.job_title_id" :options="opts.jobs" /></div><div class="fg"><label>تاريخ الالتحاق</label><DateInput v-model="quick.d.hire_date" /></div><div class="fg"><label>الأجر الأساسي</label><input v-model="quick.d.basic" type="number" class="in" /></div>
          </div>
          <p class="tiny muted">يُنشأ كموظف نشط ويمكن استكمال ملفه لاحقًا من قائمة الموظفين.</p>
        </template>
        <template v-else-if="quick.kind === 'schedule'">
          <div class="fr"><div class="fg"><label>اسم الجدول *</label><input v-model="quick.d.name" class="in" placeholder="مثال: وردية المساء 2–10" /></div><label class="chk" style="align-self:end"><input v-model="quick.d.flex" type="checkbox" /> يسمح بالساعة المرنة</label></div>
          <HoursTable v-model="quick.d.hours" />
        </template>
        <template v-else-if="quick.kind === 'location'">
          <div class="fr"><div class="fg"><label>اسم الموقع *</label><input v-model="quick.d.name" class="in" placeholder="مثال: المقر الرئيسي — الرياض" /></div><div class="fg"><label>الشركة (اختياري)</label><SearchSelect v-model="quick.d.company_id" :options="opts.companies" placeholder="المجموعة" /></div></div>
          <LocationPicker v-model:lat="quick.d.lat" v-model:lng="quick.d.lng" v-model:radius="quick.d.radius" />
        </template>
        <div v-if="quick.error" class="notice bad mt">{{ quick.error }}</div>
        <div class="row mt"><button class="btn pri sm" :class="{ loading: quick.busy }" :disabled="quick.busy" @click="saveQuick">حفظ واختيار</button><button class="btn sec sm" @click="quick = null">إلغاء</button></div>
      </div>

      <div class="grid g3">
        <label class="chk"><input v-model="f.punch_required" type="checkbox" /> إلزامية التحضير</label>
        <label class="chk"><input v-model="f.punch_anywhere" type="checkbox" /> يُسمح له بالتحضير من أي مكان</label>
        <div class="fg" style="max-width:220px"><label>هامش إضافي على النطاق (متر)</label><input v-model.number="f.geo_tolerance" type="number" min="0" class="in" placeholder="مثال: 250" /></div>
        <label class="chk"><input v-model="f.flex_enabled" type="checkbox" /> الساعة المرنة</label>
        <label class="chk"><input v-model="f.daily_note_required" type="checkbox" /> إلزام المنجز اليومي</label>
        <label class="chk" title="تحسب مكافأة نهاية الخدمة حسب نظام العمل السعودي — انظر الشرح أدناه"><input v-model="f.eosb_saudi" type="checkbox" /> قواعد نهاية الخدمة السعودية <Icon name="info" :size="13" /></label>
        <label class="chk" title="يحدد هل يُسمح للموظف بتقديم طلب سلفة من النظام. عند إلغائه لا يظهر له نوع طلب «سلفة»."><input v-model="f.advances_enabled" type="checkbox" /> تفعيل السلف <Icon name="info" :size="13" /></label>
        <label v-if="!id" class="chk"><input v-model="f.create_user" type="checkbox" /> إنشاء حساب دخول</label>
      </div>
      <div class="notice info small mt"><b>تفعيل السلف:</b> يحدد هل يُسمح لهذا الموظف بطلب سلفة من النظام أم لا. عند إلغائه لا يستطيع تقديم طلب سلفة.</div>
      <div v-if="f.eosb_saudi" class="notice info small">
        <b>قواعد نهاية الخدمة السعودية (نظام العمل، المادتان 84 و85):</b>
        <ul style="margin:4px 0 0;padding-inline-start:18px">
          <li>المكافأة على أساس آخر أجر (الأساسي + السكن + النقل): نصف أجر شهر عن كل سنة من السنوات الخمس الأولى، وأجر شهر كامل عن كل سنة بعدها، وتُحسب كسور السنة بنسبتها.</li>
          <li>عند <b>إنهاء الشركة</b> للعقد أو انتهائه: المكافأة كاملة.</li>
          <li>عند <b>الاستقالة</b>: أقل من سنتين لا شيء · من 2 إلى أقل من 5 سنوات ثلث المكافأة · من 5 إلى أقل من 10 سنوات ثلثاها · 10 سنوات فأكثر كاملة.</li>
          <li>تُستحق كاملة للمرأة عند الاستقالة خلال ستة أشهر من الزواج أو ثلاثة أشهر من الولادة، وفي حالات القوة القاهرة.</li>
          <li>الفصل وفق المادة 80 يُسقط المكافأة.</li>
        </ul>
        <span class="tiny muted">ألغِ الخيار لمن لا يخضع لنظام العمل السعودي (مثل المتعاونين من خارج المملكة).</span>
      </div>
      <div v-if="Object.keys(errors).length" class="notice bad mt">{{ Object.values(errors).flat().join(' · ') }}</div>
    </template>
    <template #foot>
      <button class="btn sec" @click="$emit('close')">إلغاء</button>
      <button class="btn pri" :class="{ loading: busy }" :disabled="busy" @click="save">حفظ</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onMounted, provide, inject, reactive, ref, watch } from 'vue'
import api from '../api'
import { countryOptions } from '../countries'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { today } from '../utils'
import { check, ibanField, nameField, optionalEmail, phoneField, requiredString, z } from '../lib/validation'
import HoursTable from './HoursTable.vue'
import IbanInput from './IbanInput.vue'
import DateInput from './DateInput.vue'
import LocationPicker from './LocationPicker.vue'
import Modal from './Modal.vue'
import PhoneInput from './PhoneInput.vue'
import SearchSelect from './SearchSelect.vue'

const props = defineProps({ id: Number, initial: { type: Object, default: null } })
const emit = defineEmits(['close', 'saved'])
const auth = useAuth()
const ui = useUi()
const ready = ref(false)
const busy = ref(false)
const errors = ref({})
const f = reactive({ contract_type: 'full', punch_required: true, punch_anywhere: false, geo_tolerance: 0, email_enabled: true, eosb_saudi: true, advances_enabled: true, flex_enabled: false, daily_note_required: false, week_pattern: '5', gender: 'm', create_user: true, location_ids: [], country: 'SA', nationality: 'SA', phone: '', email: '', dept: null })
const opts = reactive({ companies: [], jobs: [], emps: [], depts: [], contracts: [], schedules: [], locations: [] })
// working hours: the schedule's hours are shown read-only; "customise" copies them into f.work_hours which then wins
const schedules = ref([])
const customHours = ref(false)
const loadSchedules = async () => { schedules.value = await api.get('/lookups/schedules') }
const hoursView = computed({
  get: () => (customHours.value ? f.work_hours || {} : schedules.value.find((s) => s.id === f.schedule_id)?.hours || {}),
  set: (v) => { if (customHours.value) f.work_hours = v },
})
function toggleCustom() { f.work_hours = customHours.value ? { ...(schedules.value.find((s) => s.id === f.schedule_id)?.hours || {}) } : null }
const countries = countryOptions(auth.user?.locale)
const canAdd = computed(() => auth.isAdmin)
const quick = ref(null)
const quickEl = ref(null)
const QUICK_TITLES = { company: 'شركة جديدة', job: 'وظيفة جديدة', manager: 'مدير جديد', schedule: 'جدول دوام جديد', location: 'موقع تحضير جديد' }

// Tiny input field component bound to the shared form object.
provide('form', { f, errors })
const F = defineComponent({
  props: { label: String, k: String, type: { type: String, default: 'text' }, req: Boolean },
  setup(p) {
    const { f: form, errors: errs } = inject('form')
    return () => h('div', { class: 'fg' }, [
      h('label', [p.label, p.req ? h('span', { class: 'req' }, ' *') : null]),
      p.type === 'date'
        ? h(DateInput, { modelValue: form[p.k] || null, error: !!errs.value[p.k], 'onUpdate:modelValue': (v) => { form[p.k] = v } })
        : h('input', { class: ['in', { err: errs.value[p.k] }], type: p.type, value: form[p.k] ?? '', step: p.type === 'number' ? 'any' : undefined, onInput: (e) => { form[p.k] = e.target.value } }),
      errs.value[p.k] ? h('span', { class: 'e' }, errs.value[p.k][0]) : null,
    ])
  },
})

function buildOpts() {
  const m = auth.meta
  opts.companies = m.companies.map((c) => ({ value: c.id, label: c.name, sub: c.code }))
  opts.jobs = m.job_titles.map((j) => ({ value: j.id, label: j.name, sub: j.code }))
  opts.depts = (m.departments || []).map((d) => ({ value: d, label: d }))
  opts.contracts = (m.lookups?.contract_types || []).map((c) => ({ value: c.code, label: c.name, sub: c.name_en }))
  opts.schedules = m.schedules.map((s) => ({ value: s.id, label: s.name }))
  opts.locations = m.locations.map((l) => ({ value: l.id, label: l.name, sub: { point: 'نقطة', city: 'مدينة', region: 'منطقة', open: 'مفتوح' }[l.scope] }))
}
const grossPreview = computed(() => (Number(f.basic) || 0) + (f.allowances || []).reduce((t, x) => t + (Number(x.amount) || 0), 0))
const locName = (id) => opts.locations.find((l) => l.value === id)?.label || id

onMounted(async () => {
  buildOpts()
  await loadSchedules()
  const emps = await api.get('/employees/options')
  opts.emps = emps.filter((e) => e.id !== props.id).map((e) => ({ value: e.id, label: e.name, sub: e.emp_no }))
  if (props.id) {
    const r = await api.get(`/employees/${props.id}`)
    Object.assign(f, r.employee, { location_ids: r.employee.locations.map((l) => l.id), phone: r.employee.phone || '', email: r.employee.email || '' })
    customHours.value = !!(r.employee.work_hours && Object.keys(r.employee.work_hours).length)
  } else {
    f.schedule_id = auth.meta.schedules[0]?.id
    f.hire_date = today()
    f.allowances = []
    if (!auth.isAdmin) f.company_id = auth.user?.employee?.company_id || auth.user?.company_id || null
    nextNo()
    if (props.initial) Object.assign(f, props.initial) // e.g. pre-filled from an accepted candidate
  }
  ready.value = true
})

function checkEmail() {
  if (!f.email && f.email_enabled) errors.value = { ...errors.value, email: ['البريد الرسمي مطلوب لإرسال دعوة الدخول — أو ألغِ تفعيل البريد للعمال بلا بريد'] }
  else if (f.email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.email)) errors.value = { ...errors.value, email: ['صيغة البريد غير صحيحة'] }
  else { const { email, ...rest } = errors.value; errors.value = rest }
}

// ---- inline creation -------------------------------------------------------
function openQuick(kind, text) {
  const d = { company: { name: text, name_en: '', code: '', website: '', legal_no: '', tax_no: '' }, job: { name: text, name_en: '', code: '' },
    manager: { name: text, emp_no: '', company_id: f.company_id, job_title_id: null, hire_date: today(), basic: '' },
    schedule: { name: text, hours: { 0: ['09:00', '17:00'], 1: ['09:00', '17:00'], 2: ['09:00', '17:00'], 3: ['09:00', '17:00'], 4: ['09:00', '17:00'] }, flex: true },
    location: { name: text, company_id: f.company_id || null, lat: null, lng: null, radius: 150 } }[kind]
  quick.value = { kind, d, busy: false, error: '' }
  nextTick(() => quickEl.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))
}
async function addDept(name) {
  if (!name) return
  try { await api.post('/lookup-groups/departments/items', { name }); await auth.loadMeta(); buildOpts(); f.dept = name; ui.toast('أُضيفت الإدارة', 'ok') } catch (e) { ui.toast(e.message, 'bad') }
}
async function addContract(name) {
  if (!name) return
  const code = 'c' + Date.now().toString(36)
  try { await api.post('/lookup-groups/contract_types/items', { code, name }); await auth.loadMeta(); buildOpts(); f.contract_type = code; ui.toast('أُضيف نوع التعاقد', 'ok') } catch (e) { ui.toast(e.message, 'bad') }
}
async function saveQuick() {
  const q = quick.value; q.busy = true; q.error = ''
  try {
    if (q.kind === 'company') { const r = await api.post('/companies', { name: q.d.name, name_en: q.d.name_en || null, code: q.d.code, website: q.d.website || null, legal_no: q.d.legal_no || null, tax_no: q.d.tax_no || null }); await auth.loadMeta(); buildOpts(); f.company_id = r.id }
    else if (q.kind === 'job') { const r = await api.post('/lookups/job-titles', { name: q.d.name, name_en: q.d.name_en || null, code: q.d.code }); await auth.loadMeta(); buildOpts(); f.job_title_id = r.id }
    else if (q.kind === 'manager') {
      const r = await api.post('/employees', { ...q.d, basic: q.d.basic || null, contract_type: 'full', punch_required: true, email_enabled: false, create_user: true, schedule_id: f.schedule_id })
      const e = r.employee || r; opts.emps.push({ value: e.id, label: e.name, sub: e.emp_no }); f.manager_id = e.id
    } else if (q.kind === 'schedule') {
      const days = Object.keys(q.d.hours).map(Number); if (!days.length) throw new Error('حدد يومًا واحدًا على الأقل')
      const r = await api.post('/lookups/schedules', { name: q.d.name, week_days: days, hours: q.d.hours, flex: q.d.flex }); await loadSchedules(); await auth.loadMeta(); buildOpts(); f.schedule_id = r.id
    } else if (q.kind === 'location') {
      if (!q.d.name?.trim()) throw new Error('اكتب اسم الموقع')
      if (!q.d.lat) throw new Error('حدد نقطة الموقع على الخريطة: ابحث بالاسم أو انقر على الخريطة أو استخدم «موقعي الحالي»')
      const r = await api.post('/lookups/locations', { name: q.d.name, company_id: q.d.company_id, scope: 'point', lat: q.d.lat, lng: q.d.lng, radius: q.d.radius }); await auth.loadMeta(); buildOpts(); f.location_ids.push(r.id)
    }
    ui.toast('تمت الإضافة والاختيار', 'ok'); quick.value = null
  } catch (e) { q.error = Object.values(e.errors || {}).flat().join(' · ') || e.message } finally { q.busy = false }
}

const empSchema = z.object({
  name: nameField, emp_no: z.string().trim().optional().nullable().or(z.literal('')), company_id: z.number({ required_error: 'الشركة القانونية مطلوبة', invalid_type_error: 'الشركة القانونية مطلوبة' }),
  hire_date: requiredString('تاريخ الالتحاق'), email: optionalEmail, phone: phoneField.optional().nullable().or(z.literal('')), bank_iban: ibanField.optional().nullable().or(z.literal('')),
}).superRefine((v, ctx) => {
  if (f.email_enabled && f.create_user && !v.email) ctx.addIssue({ code: 'custom', path: ['email'], message: 'البريد الرسمي مطلوب لإرسال دعوة الدخول — أو ألغِ تفعيل البريد' })
  if (f.national_id && f.nationality === 'SA' && !/^1\d{9}$/.test(f.national_id)) ctx.addIssue({ code: 'custom', path: ['national_id'], message: 'الهوية الوطنية السعودية 10 أرقام وتبدأ بـ 1' })
  else if (f.national_id && f.nationality && f.nationality !== 'SA' && !/^\d{5,20}$/.test(f.national_id)) ctx.addIssue({ code: 'custom', path: ['national_id'], message: 'رقم الإقامة أرقام فقط' })
})
async function nextNo() {
  try { f.emp_no = (await api.get('/employees/next-number', { params: { company_id: f.company_id || undefined } })).emp_no } catch { /* keep manual */ }
}
watch(() => f.company_id, (cid) => {
  if (props.id) return
  if (auth.isAdmin) nextNo()
  const def = auth.meta?.companies?.find((c) => c.id === cid)?.default_schedule_id
  if (def) f.schedule_id = def // the company's working hours by default (can be changed)
})
async function save() {
  const v = check(empSchema, { ...f, company_id: f.company_id ?? undefined, phone: f.phone || '', email: f.email || '', bank_iban: f.bank_iban || '' })
  if (Object.keys(v).length) { errors.value = v; ui.toast('راجع الحقول المظللة بالأحمر', 'bad'); return }
  busy.value = true
  errors.value = {}
  const keys = ['emp_no', 'name', 'name_en', 'company_id', 'job_title_id', 'manager_id', 'dept', 'hire_date', 'contract_type', 'part_ratio', 'punch_required', 'punch_anywhere', 'geo_tolerance', 'schedule_id', 'work_hours', 'week_pattern', 'country', 'nationality', 'national_id', 'birth_date', 'gender', 'phone', 'email', 'email_enabled', 'eosb_saudi', 'flex_enabled', 'daily_note_required', 'advances_enabled', 'bank_iban', 'location_ids', 'basic', 'allowances', 'create_user']
  const body = Object.fromEntries(keys.map((k) => [k, f[k] === '' ? null : f[k]]))
  body.allowances = (f.allowances || []).filter((a) => a.name?.trim() && Number(a.amount) > 0)
  body.work_hours = customHours.value && f.work_hours && Object.keys(f.work_hours).length ? f.work_hours : null
  try {
    const r = props.id ? await api.put(`/employees/${props.id}`, body) : await api.post('/employees', body)
    ui.toast(props.id ? 'تم الحفظ' : 'أُضيف الموظف', 'ok')
    emit('saved', r?.employee || r); emit('close')
  } catch (e) { errors.value = e.errors || {}; if (!e.errors) ui.toast(e.message, 'bad') } finally { busy.value = false }
}
</script>

<style scoped>
.quick { border: 1.5px dashed var(--accent, #1C84C6); background: var(--accentbg, rgba(28, 132, 198, .06)); border-radius: 12px; padding: 12px 14px; margin: 4px 0 12px; }
.num { direction: ltr; text-align: right; font-variant-numeric: tabular-nums; }
</style>
