<template>
  <Modal :title="`طلب نيابةً عن ${employee.name}`" @close="$emit('close')">
    <div class="fg"><label>نوع الطلب</label>
      <select v-model="type" class="in"><option v-for="d in types" :key="d.code" :value="d.code">{{ ui.lang === 'en' && d.name_en ? d.name_en : d.name }}</option></select>
    </div>
    <template v-if="handler === 'salary_change'">
      <div class="notice info">الأجر الأساسي الحالي: <b class="num">{{ money(current('basic')) }}</b></div>
      <div class="fr"><div class="fg"><label>الأجر الأساسي الجديد *</label><input v-model.number="f.after" type="number" min="0" class="in" /></div><div class="fg"><label>ساري من</label><DateInput v-model="f.effective" /></div></div>
    </template>
    <template v-else-if="handler === 'allowance'">
      <div class="fr">
        <div class="fg"><label>البدل *</label><select v-model="f.component" class="in" @change="f.name = comps.find((c) => c.key === f.component)?.name"><option v-for="c in comps" :key="c.key" :value="c.key">{{ c.name }} — {{ money(c.amount) }}</option><option value="__new">بدل جديد…</option></select></div>
        <div v-if="f.component === '__new'" class="fg"><label>رمز البدل (لاتيني) *</label><input v-model="f.newKey" class="in" style="direction:ltr" placeholder="fuel" /></div>
        <div v-if="f.component === '__new'" class="fg"><label>اسم البدل *</label><input v-model="f.name" class="in" /></div>
        <div class="fg"><label>القيمة الجديدة *</label><input v-model.number="f.after" type="number" min="0" class="in" /></div>
        <div class="fg"><label>ساري من</label><DateInput v-model="f.effective" /></div>
      </div>
    </template>
    <template v-else-if="handler === 'benefit'">
      <div class="fr">
        <div class="fg"><label>البند *</label><select v-model="f.benefit" class="in"><option v-for="(l, k) in auth.meta.benefit_labels" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="fg"><label>القيمة الجديدة *</label><input v-model="f.after" class="in" /></div>
      </div>
      <p class="tiny muted">القيمة الحالية من التصنيف أو الاستثناء السابق: <b>{{ employee.custom_benefits?.[f.benefit] ?? tierBenefit(f.benefit) ?? '—' }}</b></p>
    </template>
    <template v-else-if="handler === 'balance_adj'">
      <div class="fr">
        <div class="fg"><label>نوع الإجازة *</label><select v-model="f.leave_type" class="in"><option v-for="lt in leaveTypes" :key="lt.code" :value="lt.code">{{ lt.name }}</option></select></div>
        <div class="fg"><label>الرصيد المتاح بعد التعديل *</label><input v-model.number="f.after" type="number" class="in" /></div>
      </div>
      <p class="tiny muted">الرصيد الحالي: <b>{{ balances.find((b) => b.type === f.leave_type)?.available ?? '—' }}</b> يوم — يُسجَّل الفرق كحركة تعديل معتمدة.</p>
    </template>
    <template v-else-if="handler === 'leave'">
      <div class="fr">
        <div class="fg"><label>نوع الإجازة *</label><select v-model="f.leave_type" class="in"><option v-for="lt in (auth.meta?.leave_types || []).filter((x) => x.active !== false)" :key="lt.code" :value="lt.code">{{ lt.name }}</option></select></div>
        <div class="fg"><label>من *</label><DateInput v-model="f.from" /></div>
        <div class="fg"><label>إلى *</label><DateInput v-model="f.to" /></div>
      </div>
      <p class="tiny muted">الرصيد المتاح: <b>{{ balances.find((b) => b.type === f.leave_type)?.available ?? '—' }}</b> يوم — تُقدَّم الإجازة باسم الموظف وتمر بمسار اعتماد الإجازات، ويُبلَّغ الموظف بها (§57).</p>
    </template>
    <div v-else class="empty">لا أنواع طلبات نيابية مفعّلة</div>
    <div v-if="handler !== 'leave'" class="fg"><label>السبب *</label><textarea v-model="f.reason" class="in" rows="2" /></div>
    <div v-if="Object.keys(errors).length" class="notice bad">{{ Object.values(errors).flat().join(' · ') }}</div>
    <template #foot><button class="btn sec" @click="$emit('close')">إلغاء</button><button class="btn pri" :class="{ loading: busy }" :disabled="!type" @click="submit">إرسال للاعتماد</button></template>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import api from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { money } from '../utils'
import Modal from './Modal.vue'

const props = defineProps({ employee: { type: Object, required: true }, components: { type: Array, default: () => [] }, balances: { type: Array, default: () => [] }, tier: { type: Object, default: null } })
const emit = defineEmits(['close', 'done'])
const auth = useAuth(); const ui = useUi()
// §57: HR may also schedule a leave for the employee (e.g. an accumulated annual balance)
const types = computed(() => [...(auth.meta?.request_type_defs || []).filter((d) => d.on_behalf),
  ...(auth.has('hr_mgr', 'hr_staff', 'super') ? [{ code: 'leave', handler: 'leave', name: 'إجازة نيابةً عن الموظف', name_en: 'Leave on behalf' }] : [])])
const type = ref(types.value[0]?.code || null)
const handler = computed(() => types.value.find((d) => d.code === type.value)?.handler)
const comps = computed(() => props.components.filter((c) => c.key !== 'basic'))
const leaveTypes = computed(() => (auth.meta?.leave_types || []).filter((lt) => lt.accrual !== 'none' && lt.accrual !== 'case'))
const f = reactive({ after: null, effective: '', component: comps.value[0]?.key || '__new', newKey: '', name: comps.value[0]?.name || '', benefit: Object.keys(auth.meta?.benefit_labels || {})[0], leave_type: 'annual', reason: '', from: '', to: '' })
const errors = ref({}); const busy = ref(false)
const current = (k) => props.components.find((c) => c.key === k)?.amount ?? 0
const tierBenefit = (k) => props.tier?.benefits?.[k]
async function submit() {
  if (handler.value !== 'leave' && !f.reason?.trim()) return ui.toast('السبب إلزامي', 'warn')
  busy.value = true; errors.value = {}
  const body = handler.value === 'leave' ? { type: 'leave', employee_id: props.employee.id, leave_type: f.leave_type, from: f.from, to: f.to }
    : { type: type.value, employee_id: props.employee.id, after: f.after, reason: f.reason, effective: f.effective || undefined }
  if (handler.value === 'allowance') { body.component = f.component === '__new' ? f.newKey : f.component; body.name = f.name }
  if (handler.value === 'benefit') body.benefit = f.benefit
  if (handler.value === 'balance_adj') body.leave_type = f.leave_type
  try { await api.post('/requests', body); ui.toast('أُرسل الطلب إلى مسار الاعتماد', 'ok'); emit('done'); emit('close') } catch (e) { errors.value = e.errors || { e: [e.message] } } finally { busy.value = false }
}
</script>
