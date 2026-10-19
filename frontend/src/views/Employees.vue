<template>
  <div class="page-h">
    <div><h1>{{ t('الموظفون') }}</h1><p>{{ rows.length }} موظف ضمن نطاقك</p></div>
    <div class="acts">
      <button v-if="auth.isHr" class="btn pri sm" @click="formId = 0"><Icon name="plus" :size="16" /> {{ t('إضافة موظف') }}</button>
    </div>
  </div>
  <div class="card">
    <DataTable :rows="filtered" :columns="columns" :selectable="auth.isAdmin" store-key="employees" export-name="employees" :loading="loading" search-placeholder="ابحث بالاسم أو الرقم الوظيفي أو البريد أو الوظيفة أو الإدارة…" @row-click="open">
      <template #filters>
        <select v-model="status" class="in" style="width:auto" aria-label="الحالة" @change="load"><option value="active">النشطون</option><option value="exiting">قيد الخروج</option><option value="terminated">منتهية خدماتهم</option><option value="all">الكل</option></select>
        <select v-model="fCompany" class="in" style="width:auto" aria-label="الشركة"><option value="">كل الشركات</option><option v-for="c in companies" :key="c" :value="c">{{ c }}</option></select>
        <select v-model="fContract" class="in" style="width:auto" aria-label="التعاقد"><option value="">كل أنواع التعاقد</option><option v-for="c in auth.meta?.lookups?.contract_types || []" :key="c.code" :value="c.code">{{ c.name }}</option></select>
        <select v-model="fAccount" class="in" style="width:auto" aria-label="الحساب"><option value="">كل الحسابات</option><option value="active">دخل المنصة</option><option value="invited">دُعي ولم يفعّل</option><option value="none">لم يُدعَ</option><option value="noemail">بلا بريد</option></select>
      </template>
      <template #bulk="{ selected, clear }">
        <button class="btn sec xs" @click="bulkInvite(selected, clear)"><Icon name="mail-plus" :size="14" /> إرسال دعوات الدخول</button>
      </template>
      <template #cell-name="{ row: e }"><div class="row" style="gap:8px;flex-wrap:nowrap"><Avatar :name="e.name" :url="e.user?.photo_url" /><div><b>{{ e.name }}</b><span class="tiny muted">{{ e.emp_no }} · <span dir="ltr">{{ e.email_enabled ? e.email || 'بلا بريد' : 'بلا بريد' }}</span></span></div></div></template>
      <template #cell-job="{ row: e }">{{ e.job_title?.name || '—' }}<span class="tiny muted">{{ e.dept }}</span></template>
      <template #cell-contract="{ row: e }">{{ contractLabel(e.contract_type, auth.meta) }}<span class="tiny muted">{{ e.punch_required ? 'تحضير مطلوب' : 'معفى من التحضير' }}</span></template>
      <template #cell-account="{ row: e }"><span class="pill" :class="accountState(e).cls">{{ accountState(e).label }}</span></template>
      <template #cell-status="{ row: e }"><StatusPill :status="e.status" /></template>
      <template #cell-actions="{ row: e }">
        <div class="row" style="gap:2px;flex-wrap:nowrap;justify-content:flex-end" @click.stop>
          <button class="btn ghost xs" title="عرض الملف" @click="open(e)"><Icon name="eye" :size="15" /></button>
          <button v-if="auth.isHr" class="btn ghost xs" title="تعديل" @click="formId = e.id"><Icon name="edit" :size="15" /></button>
          <ActionMenu :items="menu(e)" />
        </div>
      </template>
    </DataTable>
  </div>
  <EmployeeForm v-if="formId !== null" :id="formId" @close="formId = null" @saved="onSaved" />
  <CredentialsModal v-if="creds" v-bind="creds" @close="creds = null" />
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import api from '../api'
import ActionMenu from '../components/ActionMenu.vue'
import CredentialsModal from '../components/CredentialsModal.vue'
import DataTable from '../components/DataTable.vue'
import EmployeeForm from '../components/EmployeeForm.vue'
import StatusPill from '../components/StatusPill.vue'
import { alertBox, confirmBox } from '../dialog'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { contractLabel } from '../utils'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const router = useRouter()
const rows = ref([])
const loading = ref(true)
const status = ref('active')
const fCompany = ref('')
const fContract = ref('')
const fAccount = ref('')
const formId = ref(null)
const creds = ref(null)

const accountState = (e) => !e.user ? { label: 'بلا حساب', cls: 'gray' } : !e.user.active ? { label: 'موقوف', cls: 'gray' } : e.user.last_login_at ? { label: 'دخل المنصة', cls: 'ok' } : e.user.invited_at ? { label: 'دُعي', cls: 'info' } : { label: 'لم يُدعَ', cls: 'warn' }
const columns = [
  { key: 'name', label: t('الموظف'), value: (e) => `${e.name} ${e.emp_no} ${e.email || ''}`, exportValue: (e) => e.name },
  { key: 'emp_no', label: 'الرقم الوظيفي', hidden: true },
  { key: 'email', label: 'البريد', hidden: true },
  { key: 'phone', label: 'الجوال', hidden: true },
  { key: 'company', label: t('الشركة'), value: (e) => e.company?.name },
  { key: 'job', label: t('الوظيفة'), value: (e) => `${e.job_title?.name || ''} ${e.dept || ''}`, exportValue: (e) => e.job_title?.name },
  { key: 'dept', label: 'الإدارة', hidden: true },
  { key: 'manager', label: t('المدير المباشر'), value: (e) => e.manager?.name },
  { key: 'contract', label: t('التعاقد'), value: (e) => contractLabel(e.contract_type, auth.meta) },
  { key: 'hire_date', label: t('الالتحاق') },
  { key: 'account', label: 'حساب الدخول', value: (e) => accountState(e).label },
  { key: 'status', label: t('الحالة'), value: (e) => ({ active: 'نشط', exiting: 'قيد الخروج', terminated: 'منتهية خدمته' })[e.status] || e.status },
  { key: 'actions', label: '', sortable: false, search: false, export: false, class: 'dt-actions' },
]
const companies = computed(() => [...new Set(rows.value.map((e) => e.company?.name).filter(Boolean))].sort())
const filtered = computed(() => rows.value.filter((e) => (!fCompany.value || e.company?.name === fCompany.value) && (!fContract.value || e.contract_type === fContract.value)
  && (!fAccount.value || (fAccount.value === 'active' ? e.user?.last_login_at : fAccount.value === 'invited' ? e.user && !e.user.last_login_at && e.user.invited_at : fAccount.value === 'none' ? e.user && !e.user.invited_at && !e.user.last_login_at : !e.email_enabled || !e.email))))

async function load() {
  loading.value = true
  try { rows.value = (await api.get('/employees', { params: { status: status.value, per_page: 5000, company_id: ui.company || undefined } })).data } finally { loading.value = false }
}
watch(() => ui.company, load)
const open = (e) => router.push(`/employees/${e.id}`)
function onSaved(e) { load(); if (e?.temp_password || e?.invite_url) creds.value = { login: e.login || e.emp_no, password: e.temp_password || '', inviteUrl: e.invite_url || '', email: e.email_enabled ? e.email : '', emailed: !!e.emailed } }

function menu(e) {
  return [
    { label: 'عرض الملف', icon: 'eye', action: () => open(e) },
    { label: 'تعديل', icon: 'edit', hidden: !auth.isHr, action: () => (formId.value = e.id) },
    { divider: true, hidden: !auth.isAdmin || !e.user },
    { label: e.user?.last_login_at ? 'إرسال رابط إعادة تعيين' : 'إرسال دعوة الدخول', icon: 'mail-plus', hidden: !auth.isAdmin || !e.user, disabled: !e.user?.active || !e.email, action: () => invite(e) },
  ]
}
async function invite(e) {
  try { const r = await api.post(`/users/${e.user.id}/invite`); creds.value = { login: r.login, inviteUrl: r.invite_url, email: r.email || '', emailed: !!r.emailed, title: 'دعوة الدخول' }; load() } catch (x) { alertBox(x.message, { icon: 'error' }) }
}
async function bulkInvite(selected, clear) {
  const ids = selected.map((e) => e.user?.id).filter(Boolean)
  if (!ids.length) return alertBox('لا يوجد حساب دخول للموظفين المحددين', { icon: 'warning' })
  if (!await confirmBox(`إرسال دعوة الدخول إلى ${ids.length} موظف على بريدهم الرسمي؟ من لا بريد له يُتخطّى.`, { title: 'إرسال دعوات الدخول', confirmText: 'إرسال' })) return
  try {
    const r = await api.post('/users/bulk', { ids, action: 'invite' })
    await alertBox(`أُرسلت ${r.emailed} دعوة` + (r.skipped.length ? ` — تُخطّي ${r.skipped.length}: ` + r.skipped.slice(0, 6).map((x) => `${x.login} (${x.reason})`).join('، ') : ''), { icon: r.skipped.length ? 'warning' : 'success', title: 'دعوات الدخول' })
    clear(); load()
  } catch (x) { alertBox(x.message, { icon: 'error' }) }
}
onMounted(load)
</script>
