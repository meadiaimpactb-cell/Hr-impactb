<template>
  <Modal :title="type ? typeName(type) : t('طلب جديد')" @close="$emit('close')">
    <div v-if="!type" class="grid g2">
      <button v-for="d in selfTypes" :key="d.code" class="btn sec" @click="type = d.code">{{ typeName(d.code) }}</button>
      <div v-if="!selfTypes.length" class="empty">لا أنواع طلبات مفعّلة</div>
    </div>

    <template v-else-if="handler === 'leave'">
      <div class="fg"><label>نوع الإجازة <span class="req">*</span></label>
        <select v-model="f.leave_type" class="in">
          <option v-for="b in leaveOptions" :key="b.code" :value="b.code">{{ b.label }}</option>
        </select>
      </div>
      <div class="fr">
        <div class="fg"><label>من <span class="req">*</span></label><DateInput v-model="f.from" :class="{ err: errors.from }" /><span class="e">{{ errors.from?.[0] }}</span></div>
        <div class="fg"><label>إلى <span class="req">*</span></label><DateInput v-model="f.to" :min="f.from" :class="{ err: errors.to }" /><span class="e">{{ errors.to?.[0] }}</span></div>
      </div>
      <div v-if="days" class="notice info">عدد الأيام: <b>{{ days }}</b> <span v-if="selBalance"> · المتاح {{ selBalance.available }} يوم</span></div>
      <div class="fg"><label>البديل أثناء الإجازة <span class="tiny muted">(اختياري: يستلم المهام الطارئة ويُشعَر عند الاعتماد)</span></label><SearchSelect v-model="f.substitute_id" :options="empOptions" /></div>
      <label class="chk"><input v-model="f.delegate" type="checkbox" /> تفويض البديل باعتماداتي خلال الإجازة</label>
      <div class="fg"><label>المهام المسلَّمة للبديل</label><textarea v-model="f.handover" class="in" /></div>
      <div class="fg"><label>مرفق <span v-if="attRequired" class="req">* (إلزامي لهذا النوع)</span></label><input type="file" class="in" @change="file = $event.target.files[0]" /><span class="e">{{ errors.attachment?.[0] }}</span></div>
    </template>

    <template v-else-if="handler === 'correction'">
      <div class="fr">
        <div class="fg"><label>اليوم <span class="req">*</span></label><DateInput v-model="f.date" :max="today()" /></div>
        <div class="fg"><label>الوقت الصحيح</label><input v-model="f.time" type="time" class="in" /></div>
      </div>
      <div class="fg"><label>نوع الطلب</label><select v-model="f.kind" class="in"><option v-for="k in corrKinds" :key="k">{{ k }}</option></select></div>
      <div class="fg"><label>السبب <span class="req">*</span></label><textarea v-model="f.reason" class="in" :class="{ err: errors.reason }" /></div>
    </template>

    <template v-else-if="handler === 'advance'">
      <div class="fr">
        <div class="fg"><label>المبلغ (ر.س) <span class="req">*</span></label><input v-model.number="f.amount" type="number" min="100" class="in" :class="{ err: errors.amount }" /><span class="e">{{ errors.amount?.[0] }}</span></div>
        <div class="fg"><label>عدد الأقساط <span class="req">*</span></label><input v-model.number="f.installments" type="number" min="1" max="24" class="in" /></div>
      </div>
      <div v-if="f.amount && f.installments" class="notice info">القسط الشهري: <b class="num">{{ money(f.amount / f.installments) }}</b> ر.س</div>
      <div class="fg"><label>الغرض <span class="req">*</span></label><input v-model="f.purpose" class="in" /></div>
    </template>

    <template v-else-if="handler === 'letter'">
      <div class="fg"><label>نوع الخطاب</label><select v-model="f.kind" class="in"><option v-for="k in letterKinds" :key="k.code" :value="k.code">{{ ui.lang === 'en' && k.name_en ? k.name_en : k.name }}</option></select></div>
      <div class="fg"><label>الجهة الموجّه إليها</label><input v-model="f.to" class="in" /></div>
      <div class="fg"><label>شرح الخطاب والغرض منه <span class="req">*</span></label><textarea v-model="f.purpose" class="in" rows="2" placeholder="مثال: تعريف بالراتب لبنك الراجحي لطلب تمويل" /><span class="e">{{ errors.purpose?.[0] }}</span></div>
      <div class="fg"><label>المبررات</label><input v-model="f.justification" class="in" /></div>
      <div class="fg"><label>اللغة</label><select v-model="f.lang" class="in"><option value="ar">العربية</option><option value="en">English</option></select></div>
    </template>

    <template v-else-if="handler === 'trip'">
      <div class="notice info small">المسار (اللائحة §65): المشرف المباشر ← الموارد البشرية ← المدير المالي. الرحلة خارج المملكة تضيف الرئيس التنفيذي للشركة ثم الرئيس التنفيذي للمجموعة. يحسب النظام عدد الأيام والبدل اليومي وسقف الفندق تلقائيًا بحسب فئة الشركة.</div>
      <div class="fr">
        <div class="fg"><label>نوع الرحلة <span class="req">*</span></label><select v-model="f.scope" class="in"><option value="domestic">داخل المملكة</option><option value="intl">خارج المملكة</option></select></div>
        <div class="fg"><label>الوجهة <span class="req">*</span></label><input v-model="f.destination" class="in" /></div>
      </div>
      <div class="fg"><label>الغرض <span class="req">*</span></label><textarea v-model="f.purpose" class="in" /></div>
      <div class="fr">
        <div class="fg"><label>تاريخ البداية <span class="req">*</span></label><DateInput v-model="f.from" /><span class="e">{{ errors.from?.[0] }}</span></div>
        <div class="fg"><label>تاريخ النهاية <span class="req">*</span></label><DateInput v-model="f.to" :min="f.from" /><span class="e">{{ errors.to?.[0] }}</span></div>
      </div>
      <div v-if="tripDays" class="tiny muted">مدة الرحلة: <b class="num">{{ tripDays }}</b> يوم</div>
      <h4 style="margin:10px 0 4px">التكلفة التقديرية <span class="tiny muted">(أدخل التفاصيل أو الإجمالي)</span></h4>
      <div class="fr3">
        <div class="fg"><label>الطيران</label><input v-model.number="f.cost_flight" type="number" min="0" class="in" /></div>
        <div class="fg"><label>السكن</label><input v-model.number="f.cost_lodging" type="number" min="0" class="in" /></div>
        <div class="fg"><label>البدل اليومي</label><input v-model.number="f.cost_allowance" type="number" min="0" class="in" /></div>
        <div class="fg"><label>التنقل</label><input v-model.number="f.cost_transport" type="number" min="0" class="in" /></div>
        <div class="fg"><label>الرسوم</label><input v-model.number="f.cost_fees" type="number" min="0" class="in" /></div>
        <div class="fg"><label>الإجمالي <span class="req">*</span></label><input :value="tripTotal || f.estimate" type="number" class="in" :class="{ err: errors.estimate }" :readonly="!!tripTotal" @input="f.estimate = +$event.target.value" /><span class="e">{{ errors.estimate?.[0] }}</span></div>
      </div>
      <div class="fg"><label>عهدة نقدية مطلوبة للرحلة <span class="tiny muted">(تُسوّى خلال 10 أيام عمل من العودة — §44/§68)</span></label><input v-model.number="f.advance" type="number" min="0" class="in" /></div>
    </template>

    <template v-else-if="handler === 'resignation'">
      <div class="notice warn">تُحسب آخر أيام العمل وفق مدة الإشعار في السياسة، ويمر الطلب بمديرك ثم الموارد البشرية.</div>
      <div class="fg"><label>التاريخ المطلوب لآخر يوم عمل</label><DateInput v-model="f.requested_last" /></div>
      <div class="fg"><label>السبب <span class="req">*</span></label><textarea v-model="f.reason" class="in" /></div>
    </template>

    <!-- Custom (form-defined) request types: rendered from the schema stored in the request type -->
    <template v-else>
      <div v-for="fd in def?.form || []" :key="fd.key" class="fg">
        <label>{{ fieldLabel(fd) }} <span v-if="fd.required" class="req">*</span></label>
        <textarea v-if="fd.type === 'textarea'" v-model="f[fd.key]" class="in" :class="{ err: errors[fd.key] }" />
        <input v-else-if="fd.type === 'number'" v-model.number="f[fd.key]" type="number" step="any" class="in" :class="{ err: errors[fd.key] }" />
        <DateInput v-else-if="fd.type === 'date'" v-model="f[fd.key]" :class="{ err: errors[fd.key] }" />
        <select v-else-if="fd.type === 'select'" v-model="f[fd.key]" class="in" :class="{ err: errors[fd.key] }"><option value="">—</option><option v-for="o in fd.options || []" :key="o">{{ o }}</option></select>
        <select v-else-if="fd.type === 'lookup'" v-model="f[fd.key]" class="in" :class="{ err: errors[fd.key] }"><option value="">—</option><option v-for="o in auth.meta?.lookups?.[fd.group] || []" :key="o.code" :value="o.name">{{ ui.lang === 'en' && o.name_en ? o.name_en : o.name }}</option></select>
        <label v-else-if="fd.type === 'bool'" class="chk"><input v-model="f[fd.key]" type="checkbox" /> نعم</label>
        <SearchSelect v-else-if="fd.type === 'employee'" v-model="f[fd.key]" :options="empOptions" />
        <input v-else v-model="f[fd.key]" class="in" :class="{ err: errors[fd.key] }" />
        <span class="e">{{ errors[fd.key]?.[0] }}</span>
      </div>
      <div v-if="!def?.form?.length" class="notice info">هذا النوع بلا حقول — يُرسل الطلب مباشرة إلى مسار الاعتماد.</div>
    </template>

    <template #foot>
      <button v-if="type" class="btn sec" @click="type = null; errors = {}">رجوع</button>
      <button class="btn sec" @click="$emit('close')">{{ t('إلغاء') }}</button>
      <button v-if="type" class="btn pri" :class="{ loading: busy }" :disabled="busy" @click="submit">{{ t('إرسال') }}</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed as _computed } from 'vue'

import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { money, today } from '../utils'
import Modal from './Modal.vue'
import SearchSelect from './SearchSelect.vue'

const props = defineProps({ initial: { type: String, default: null } })
const emit = defineEmits(['close', 'done'])
const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const defs = computed(() => auth.meta?.request_type_defs || [])
const selfTypes = computed(() => defs.value.filter((d) => d.self_service && (!d.roles?.length || auth.has(...d.roles))))
const typeName = (code) => { const d = defs.value.find((x) => x.code === code); return d ? (ui.lang === 'en' && d.name_en ? d.name_en : d.name) : code }
const fieldLabel = (fd) => (ui.lang === 'en' && fd.label_en ? fd.label_en : fd.label)
const corrKinds = computed(() => auth.meta?.correction_kinds || [])
const letterKinds = computed(() => auth.meta?.lookups?.letter_kinds || [])
const type = ref(null)
const def = computed(() => defs.value.find((d) => d.code === type.value))
const handler = computed(() => def.value?.handler)
const f = reactive({ leave_type: 'annual', delegate: false, kind: '', lang: 'ar', installments: 6, scope: 'domestic' })
const file = ref(null)
const errors = ref({})
const busy = ref(false)
const balances = ref([])
const emps = ref([])

watch(type, () => { f.kind = handler.value === 'correction' ? corrKinds.value[1] || corrKinds.value[0] : handler.value === 'letter' ? letterKinds.value[0]?.code : '' })
onMounted(async () => {
  type.value = props.initial
  if (!auth.emp) return
  const [b, e] = await Promise.all([api.get('/leaves/balances'), api.get('/employees/options', { params: { colleagues: 1 } }).catch(() => [])])
  balances.value = b.balances
  if (b.work_days?.length) workDays.value = b.work_days
  emps.value = e.filter((x) => x.id !== auth.emp.id)
})
const leaveTypes = computed(() => auth.meta?.leave_types || [])
const leaveOptions = computed(() => leaveTypes.value.map((lt) => {
  const b = balances.value.find((x) => x.type === lt.code)
  return { code: lt.code, label: lt.name + (b && lt.accrual !== 'case' ? ` — المتاح ${b.available}` : '') }
}))
const selBalance = computed(() => balances.value.find((b) => b.type === f.leave_type))
const attRequired = computed(() => leaveTypes.value.find((x) => x.code === f.leave_type)?.attachment_required)
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
// same rule as the server: calendar days, weekend days at the start or end of the request are not counted
const workDays = ref([0, 1, 2, 3, 4])
const days = computed(() => {
  if (!f.from || !f.to || f.to < f.from) return 0
  const a = new Date(f.from + 'T00:00:00'); const b = new Date(f.to + 'T00:00:00')
  while (a <= b && !workDays.value.includes(a.getDay())) a.setDate(a.getDate() + 1)
  while (b >= a && !workDays.value.includes(b.getDay())) b.setDate(b.getDate() - 1)
  return a > b ? 0 : Math.round((b - a) / 86400000) + 1
})

async function submit() {
  busy.value = true
  errors.value = {}
  try {
    const fd = new FormData()
    fd.append('type', type.value)
    const keys = handler.value ? Object.keys(f) : (def.value?.form || []).map((x) => x.key)
    keys.forEach((k) => { const v = f[k]; if (v !== null && v !== undefined && v !== '') fd.append(k, typeof v === 'boolean' ? (v ? 1 : 0) : v) })
    if (file.value) fd.append('attachment', file.value)
    await api.post('/requests', fd)
    ui.toast('أُرسل الطلب', 'ok')
    emit('done')
    emit('close')
  } catch (e) {
    errors.value = e.errors || {}
  } finally {
    busy.value = false
  }
}
const tripDays = _computed(() => (f.from && f.to && f.to >= f.from) ? Math.round((new Date(f.to) - new Date(f.from)) / 864e5) + 1 : 0)
const tripTotal = _computed(() => ['cost_flight', 'cost_lodging', 'cost_allowance', 'cost_transport', 'cost_fees'].reduce((a, k) => a + (Number(f[k]) || 0), 0))
</script>
