<template>
  <div class="page-h">
    <div><h1>{{ t('المستخدمون والصلاحيات') }}</h1><p>الأدوار · الصلاحيات المفوّضة (الشركات × العمليات × الإجراءات × الحد × المدة) · التفويض المؤقت</p></div>
    <div class="acts">
      <button v-if="tab === 'users'" class="btn pri sm" @click="editUser({})"><Icon name="plus" :size="16" /> حساب جديد</button>
      <button v-if="tab === 'deleg'" class="btn pri sm" @click="delegForm = { valid_from: today(), ops: [] }"><Icon name="repeat" :size="16" /> تفويض جديد</button>
    </div>
  </div>
  <div class="tabs">
    <button :class="{ on: tab === 'users' }" @click="tab = 'users'">الحسابات والأدوار</button>
    <button :class="{ on: tab === 'perms' }" @click="tab = 'perms'">الصلاحيات المفوّضة</button>
    <button :class="{ on: tab === 'deleg' }" @click="tab = 'deleg'; loadDeleg()">التفويضات</button>
  </div>

  <div v-if="tab === 'users'" class="card">
    <DataTable ref="table" :rows="users" :columns="columns" selectable store-key="users" export-name="users" :loading="loading" search-placeholder="ابحث بالاسم أو اسم الدخول أو البريد أو الرقم الوظيفي…" @row-click="view = $event">
      <template #filters>
        <select v-model="fRole" class="in" style="width:auto" aria-label="الدور"><option value="">كل الأدوار</option><option v-for="(l, k) in meta.roles" :key="k" :value="k">{{ l }}</option></select>
        <select v-model="fStatus" class="in" style="width:auto" aria-label="الحالة"><option value="">كل الحالات</option><option value="active">نشط</option><option value="inactive">موقوف</option><option value="pending">لم يفعّل حسابه</option><option value="never">لم يدخل بعد</option><option value="noinvite">الدعوة لم تُرسل</option></select>
        <select v-model="fCompany" class="in" style="width:auto" aria-label="الشركة"><option value="">كل الشركات</option><option v-for="c in companiesInUse" :key="c" :value="c">{{ c }}</option></select>
      </template>
      <template #bulk="{ selected, clear }">
        <button class="btn sec xs" @click="bulk('invite', selected, clear)"><Icon name="mail-plus" :size="14" /> إرسال دعوات</button>
        <button class="btn sec xs" @click="bulk('activate', selected, clear)"><Icon name="user-on" :size="14" /> تفعيل</button>
        <button class="btn sec xs" @click="bulk('deactivate', selected, clear)"><Icon name="user-off" :size="14" /> إيقاف</button>
        <button class="btn dan xs" @click="bulk('delete', selected, clear)"><Icon name="trash" :size="14" /> حذف</button>
      </template>
      <template #cell-login="{ row: u }"><b>{{ u.login }}</b><span class="tiny muted" dir="ltr">{{ u.email || 'بلا بريد' }}</span></template>
      <template #cell-employee="{ row: u }">{{ u.employee?.name || u.name }}<span class="tiny muted">{{ u.employee?.emp_no }}{{ u.employee?.company ? ' · ' + u.employee.company : '' }}</span></template>
      <template #cell-role="{ row: u }"><span v-for="r in u.roles" :key="r" class="pill" :class="r === 'super' ? 'navy' : r.startsWith('hr') ? 'purple' : 'info'">{{ meta.roles?.[r] }}</span></template>
      <template #cell-last="{ row: u }"><span class="small num">{{ u.last_login_at ? fmtDT(u.last_login_at) : 'لم يدخل بعد' }}</span></template>
      <template #cell-status="{ row: u }">
        <span class="pill" :class="u.active ? 'ok' : 'gray'">{{ u.active ? 'نشط' : 'موقوف' }}</span>
        <span v-if="u.active && !u.last_login_at" class="pill" :class="u.invited_at ? 'info' : 'warn'" :title="u.invited_at ? 'أُرسلت الدعوة ' + fmtDT(u.invited_at) : 'أرسل الدعوة من قائمة الإجراءات'">{{ u.invited_at ? 'دُعي ولم يدخل بعد' : 'الدعوة لم تُرسل' }}</span>
      </template>
      <template #cell-actions="{ row: u }">
        <div class="row" style="gap:2px;flex-wrap:nowrap;justify-content:flex-end" @click.stop>
          <button class="btn ghost xs" title="عرض" @click="view = u"><Icon name="eye" :size="15" /></button>
          <button class="btn ghost xs" title="تعديل" @click="editUser(u)"><Icon name="edit" :size="15" /></button>
          <ActionMenu :items="menu(u)" />
        </div>
      </template>
    </DataTable>
  </div>

  <div v-else-if="tab === 'perms'" class="card">
    <div v-if="!staff.length" class="empty">لا حسابات بدور «موظف موارد بشرية» — أنشئ حسابًا بهذا الدور أولًا</div>
    <template v-else>
      <div class="row mb"><label class="small b">الحساب:</label>
        <select v-model="permUserId" class="in" style="width:auto"><option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }} ({{ u.login }})</option></select>
      </div>
      <div v-if="perm" class="grid g3">
        <div class="card" style="margin:0"><h3>1 · الشركات</h3>
          <label v-for="c in meta.companies" :key="c.id" class="chk"><input v-model="perm.companies" type="checkbox" :value="c.id" /> {{ c.name }}</label>
        </div>
        <div class="card" style="margin:0;grid-column:span 2"><h3>2 · العمليات والإجراءات <span class="sub">SEC و ADM غير قابلة للتفويض</span></h3>
          <div v-dt class="tw"><table>
            <thead><tr><th>العملية</th><th v-for="(al, a) in ACTIONS" :key="a" class="center">{{ al }}</th><th>حد الاعتماد</th></tr></thead>
            <tbody><tr v-for="(label, op) in delegableOps" :key="op">
              <td><b>{{ op }}</b> <span class="tiny muted">{{ label }}</span></td>
              <td v-for="(al, a) in ACTIONS" :key="a" class="center"><input type="checkbox" :checked="(perm.ops[op] || []).includes(a)" @change="toggleOp(op, a, $event.target.checked)" /></td>
              <td><input v-model="perm.limits[op]" class="in" style="min-height:30px;width:80px" placeholder="—" /></td>
            </tr></tbody>
          </table></div>
          <div class="fr mt">
            <div class="fg"><label>تنتهي الصلاحيات في (فارغ = دائمة)</label><DateInput v-model="perm.valid_to" /></div>
            <div class="fg"><label>سبب المنح أو التعديل</label><input v-model="perm.reason" class="in" /></div>
          </div>
          <div class="notice info">{{ permPreview }}</div>
          <button class="btn pri sm" @click="savePerms">حفظ الصلاحيات</button>
        </div>
      </div>
    </template>
  </div>

  <div v-else class="card">
    <div v-if="!delegs.length" class="empty">لا تفويضات</div>
    <div v-dt v-else class="tw"><table>
      <thead><tr><th>المفوِّض</th><th>المفوَّض إليه</th><th>العمليات</th><th>من</th><th>إلى</th><th>السبب</th><th>الحالة</th><th /></tr></thead>
      <tbody><tr v-for="d in delegs" :key="d.id">
        <td>{{ d.from_user?.name }}</td><td>{{ d.to_user?.name }}</td><td class="small">{{ d.ops?.length ? d.ops.join('، ') : 'كل الاعتمادات القابلة للتفويض' }}</td>
        <td>{{ d.valid_from }}</td><td>{{ d.valid_to }}</td><td class="small">{{ d.reason }}</td>
        <td><span class="pill" :class="delegActive(d) ? 'ok' : d.status === 'revoked' ? 'bad' : 'gray'">{{ delegActive(d) ? 'سارٍ' : d.status === 'revoked' ? 'مسحوب' : 'منتهٍ' }}</span></td>
        <td><button v-if="delegActive(d)" class="btn dan xs" @click="revoke(d)">سحب</button></td>
      </tr></tbody>
    </table></div>
    <p class="tiny muted mt">التفويض مؤقت وينتهي تلقائيًا، ويظهر في سجل القرار صفة المعتمد الأصلي والمفوَّض معًا.</p>
  </div>

  <Modal v-if="form" :title="form.id ? 'تعديل حساب' : 'حساب جديد'" @close="form = null">
    <div class="fg"><label>الموظف المرتبط</label><SearchSelect v-model="form.employee_id" :options="empOptions" placeholder="اختياري — ابحث عن الموظف" /></div>
    <div class="fr">
      <div class="fg"><label>اسم الدخول *</label><input v-model="form.login" class="in" :class="{ err: errors.login }" /><span class="e">{{ errors.login?.[0] }}</span></div>
      <div class="fg"><label>البريد</label><input v-model="form.email" type="email" class="in" /></div>
    </div>
    <div class="fg"><label>الدور *</label>
      <select v-model="form.role" class="in"><option v-for="r in assignable" :key="r" :value="r">{{ meta.roles?.[r] }}</option></select>
      <span class="e">{{ errors.roles?.[0] }}</span>
    </div>
    <div v-if="form.role === 'ceo'" class="fg"><label>الشركة *</label><select v-model="form.company_id" class="in"><option v-for="c in meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select><span class="e">{{ errors.company_id?.[0] }}</span></div>
    <div v-if="!form.id" class="fg"><label>كلمة المرور (فارغ = كلمة مؤقتة تُولَّد تلقائيًا)</label><input v-model="form.password" class="in" /></div>
    <label class="chk"><input v-model="form.active" type="checkbox" /> الحساب نشط (الإيقاف يُخرج المستخدم من كل الأجهزة)</label>
    <template #foot><button class="btn sec" @click="form = null">إلغاء</button><button class="btn pri" @click="saveUser">حفظ</button></template>
  </Modal>

  <Modal v-if="delegForm" title="تفويض مؤقت للاعتمادات" @close="delegForm = null">
    <div v-if="auth.isAdmin" class="fg"><label>المفوِّض (فارغ = أنا)</label><SearchSelect v-model="delegForm.from_user_id" :options="userOptions" /></div>
    <div class="fg"><label>المفوَّض إليه *</label><SearchSelect v-model="delegForm.to_user_id" :options="userOptions" /></div>
    <div class="fr">
      <div class="fg"><label>من</label><DateInput v-model="delegForm.valid_from" /></div>
      <div class="fg"><label>إلى *</label><DateInput v-model="delegForm.valid_to" :min="delegForm.valid_from" /></div>
    </div>
    <div class="fg"><label>العمليات (لا شيء = كل الاعتمادات القابلة للتفويض)</label>
      <div class="row"><label v-for="(l, op) in delegableOps" :key="op" class="chk small"><input v-model="delegForm.ops" type="checkbox" :value="op" /> {{ l }}</label></div>
    </div>
    <div class="fg"><label>السبب *</label><input v-model="delegForm.reason" class="in" /></div>
    <template #foot><button class="btn sec" @click="delegForm = null">إلغاء</button><button class="btn pri" @click="saveDeleg">تفويض</button></template>
  </Modal>
  <Modal v-if="view" title="بيانات الحساب" @close="view = null">
    <div class="row mb" style="gap:12px;align-items:center"><span class="avatar" style="width:46px;height:46px;font-size:18px">{{ (view.employee?.name || view.name || '?')[0] }}</span><div><b style="font-size:16px">{{ view.employee?.name || view.name }}</b><div class="tiny muted">{{ view.employee?.company || '—' }}</div></div></div>
    <div class="tw"><table class="kv">
      <tr><th>اسم الدخول</th><td dir="ltr" style="text-align:right">{{ view.login }}</td></tr>
      <tr><th>البريد</th><td dir="ltr" style="text-align:right">{{ view.email || '—' }}</td></tr>
      <tr><th>الرقم الوظيفي</th><td>{{ view.employee?.emp_no || '—' }}</td></tr>
      <tr><th>الدور</th><td>{{ view.roles.map((r) => meta.roles?.[r]).join('، ') }}</td></tr>
      <tr><th>الحالة</th><td>{{ view.active ? 'نشط' : 'موقوف' }}{{ view.must_change_password ? ' · لم يختر كلمة مروره بعد' : '' }}</td></tr>
      <tr><th>الدعوة</th><td>{{ view.invite_accepted_at ? 'فعّل حسابه ' + fmtDT(view.invite_accepted_at) : view.invited_at ? 'أُرسلت ' + fmtDT(view.invited_at) : 'لم تُرسل' }}</td></tr>
      <tr><th>آخر دخول</th><td>{{ view.last_login_at ? fmtDT(view.last_login_at) : 'لم يدخل بعد' }}</td></tr>
      <tr><th>تاريخ الإنشاء</th><td>{{ view.created_at ? fmtDT(view.created_at) : '—' }}</td></tr>
    </table></div>
    <template #foot>
      <RouterLink v-if="view.employee" class="btn ghost" :to="`/employees/${view.employee.id}`">ملف الموظف</RouterLink>
      <button class="btn sec" @click="view = null">إغلاق</button>
      <button class="btn pri" @click="editUser(view); view = null"><Icon name="edit" :size="15" /> تعديل</button>
    </template>
  </Modal>
  <CredentialsModal v-if="creds" v-bind="creds" @close="creds = null" />
</template>

<script setup>
import { alertBox, confirmBox } from '../dialog'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import api from '../api'
import ActionMenu from '../components/ActionMenu.vue'
import CredentialsModal from '../components/CredentialsModal.vue'
import DataTable from '../components/DataTable.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtDT, today } from '../utils'

const { t } = useI18n()
const router = useRouter()
const creds = ref(null)
const auth = useAuth()
const ui = useUi()
const tab = ref('users')
const users = ref([])
const allUsers = ref([])
const loading = ref(true)
const view = ref(null)
const table = ref(null)
const fRole = ref('')
const fStatus = ref('')
const fCompany = ref('')
const columns = [
  { key: 'login', label: 'اسم الدخول', value: (u) => `${u.login} ${u.email || ''}`, exportValue: (u) => u.login },
  { key: 'email', label: 'البريد', hidden: true },
  { key: 'employee', label: 'الموظف', value: (u) => `${u.employee?.name || u.name} ${u.employee?.emp_no || ''}`, exportValue: (u) => u.employee?.name || u.name },
  { key: 'emp_no', label: 'الرقم الوظيفي', value: (u) => u.employee?.emp_no, hidden: true },
  { key: 'company', label: 'الشركة', value: (u) => u.employee?.company, hidden: true },
  { key: 'role', label: 'الدور', value: (u) => u.roles.map((r) => roleInfo.value.roles?.[r]).join('، ') },
  { key: 'last', label: 'آخر دخول', value: (u) => u.last_login_at || '', exportValue: (u) => (u.last_login_at ? fmtDT(u.last_login_at) : '') },
  { key: 'status', label: 'الحالة', value: (u) => (u.active ? 'نشط' : 'موقوف'), exportValue: (u) => (!u.active ? 'موقوف' : u.last_login_at ? 'نشط' : u.invited_at ? 'نشط، دُعي ولم يدخل بعد' : 'نشط، الدعوة لم تُرسل') },
  { key: 'actions', label: '', sortable: false, search: false, export: false, class: 'dt-actions' },
]
const roleInfo = ref({ roles: {}, assignable: [], ops: {}, actions: [], non_delegable: [] })
const form = ref(null)
const errors = ref({})
const emps = ref([])
const permUserId = ref(null)
const perm = ref(null)
const delegs = ref([])
const delegForm = ref(null)
const ACTIONS = { view: 'عرض', add: 'إضافة', edit: 'تعديل', approve: 'اعتماد', export: 'تصدير', publish: 'نشر', close: 'إقفال' }

const meta = computed(() => ({ ...auth.meta, roles: roleInfo.value.roles }))
const assignable = computed(() => roleInfo.value.assignable)
const delegableOps = computed(() => Object.fromEntries(Object.entries(roleInfo.value.ops).filter(([k]) => !roleInfo.value.non_delegable.includes(k))))
const companiesInUse = computed(() => [...new Set(allUsers.value.map((u) => u.employee?.company).filter(Boolean))].sort())
watch([fRole, fStatus, fCompany, allUsers], () => {
  users.value = allUsers.value.filter((u) => (!fRole.value || u.roles.includes(fRole.value))
    && (!fCompany.value || u.employee?.company === fCompany.value)
    && (!fStatus.value || (fStatus.value === 'active' ? u.active : fStatus.value === 'inactive' ? !u.active : fStatus.value === 'pending' ? u.active && !u.invite_accepted_at && u.must_change_password : fStatus.value === 'noinvite' ? u.active && !u.invited_at && !u.last_login_at : !u.last_login_at)))
}, { deep: true })
const staff = computed(() => allUsers.value.filter((u) => u.roles.includes('hr_staff')))
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const userOptions = computed(() => allUsers.value.filter((u) => u.active).map((u) => ({ value: u.id, label: u.name, sub: u.login })))
const companyName = (id) => auth.meta.companies.find((c) => c.id === +id)?.name || id
const delegActive = (d) => d.status === 'active' && d.valid_to >= today()

const permPreview = computed(() => {
  const u = allUsers.value.find((x) => x.id === permUserId.value)
  if (!u || !perm.value) return ''
  const ops = Object.entries(perm.value.ops).filter(([, v]) => v.length)
  if (!ops.length) return `${u.name} لا يملك صلاحيات مفوّضة بعد.`
  return `يستطيع ${u.name} على ${perm.value.companies.length} شركة: ` + ops.map(([k, v]) => `${delegableOps.value[k]} (${v.map((a) => ACTIONS[a]).join('، ')}${perm.value.limits[k] ? ` حتى ${perm.value.limits[k]}` : ''})`).join('؛ ') +
    (perm.value.ops.PAY?.length ? '' : '؛ ولا يرى الرواتب') + (perm.value.valid_to ? ` — حتى ${perm.value.valid_to}` : '')
})

watch(permUserId, (id) => {
  const u = allUsers.value.find((x) => x.id === id)
  const p = u?.perms || {}
  perm.value = { companies: (p.companies || []).map(Number), ops: JSON.parse(JSON.stringify(p.ops || {})), limits: { ...(p.limits || {}) }, valid_to: p.valid_to || '', reason: '' }
})

async function load() {
  loading.value = true
  try { ;[allUsers.value, roleInfo.value] = await Promise.all([api.get('/users'), api.get('/users/roles')]) } finally { loading.value = false }
  if (!permUserId.value && staff.value.length) permUserId.value = staff.value[0].id
}
async function loadDeleg() { delegs.value = await api.get('/delegations') }
async function editUser(u) {
  errors.value = {}
  if (!emps.value.length) emps.value = await api.get('/employees/options')
  form.value = { active: true, ...u, role: u.roles?.[0] || 'emp', employee_id: u.employee?.id || null }
}
async function saveUser() {
  const f = form.value
  errors.value = {}
  const body = { login: f.login, email: f.email || null, employee_id: f.employee_id || null, roles: [f.role], company_id: f.role === 'ceo' ? f.company_id : null, active: !!f.active, password: f.password || undefined }
  try {
    if (f.id) { await api.put(`/users/${f.id}`, body); ui.toast('تم الحفظ', 'ok') } else {
      const r = await api.post('/users', body)
      creds.value = { login: r.user.login, password: r.temp_password, inviteUrl: r.invite_url || '', email: r.user.email || '', emailed: !!r.emailed, title: 'بيانات الحساب الجديد' }
    }
    form.value = null
    load()
  } catch (e) { errors.value = e.errors || {} }
}
let inviting = false
async function invite(u) {
  if (inviting) return // one click = one email
  inviting = true
  try { const r = await api.post(`/users/${u.id}/invite`); creds.value = { login: r.login, inviteUrl: r.invite_url, email: r.email || '', emailed: !!r.emailed, title: 'دعوة الدخول' } }
  catch (e) { ui.toast(e.message || 'تعذر الإرسال', 'bad') } finally { inviting = false }
}
async function resetPass(u) {
  if (!await confirmBox(`إعادة تعيين كلمة مرور ${u.name}؟ سيُخرج من كل الأجهزة.`)) return
  const r = await api.post(`/users/${u.id}/reset-password`)
  creds.value = { login: r.login || u.login, password: r.temp_password, inviteUrl: r.invite_url || '', email: r.email || '', emailed: !!r.emailed }
  load()
}
function toggleOp(op, a, on) {
  const cur = new Set(perm.value.ops[op] || [])
  on ? cur.add(a) : cur.delete(a)
  perm.value.ops[op] = [...cur]
}
async function savePerms() {
  await api.put(`/users/${permUserId.value}/perms`, { ...perm.value, valid_to: perm.value.valid_to || null })
  ui.toast('حُفظت الصلاحيات وسُجّلت في التدقيق', 'ok')
  load()
}
async function saveDeleg() {
  const f = delegForm.value
  if (!f.to_user_id || !f.valid_to || !f.reason) return ui.toast('أكمل الحقول الإلزامية', 'warn')
  await api.post('/delegations', { ...f, from_user_id: f.from_user_id || undefined })
  ui.toast('تم التفويض', 'ok'); delegForm.value = null; loadDeleg()
}
const me = computed(() => auth.user?.id)
function menu(u) {
  return [
    { label: 'عرض البيانات', icon: 'eye', action: () => (view.value = u) },
    { label: 'تعديل', icon: 'edit', action: () => editUser(u) },
    { label: 'الصلاحيات المفوّضة', icon: 'key', hidden: !u.roles.includes('hr_staff'), action: () => { permUserId.value = u.id; tab.value = 'perms' } },
    { label: 'ملف الموظف', icon: 'user-check', hidden: !u.employee, action: () => router.push(`/employees/${u.employee.id}`) },
    { divider: true },
    { label: u.invite_accepted_at ? 'إرسال رابط إعادة تعيين' : 'إرسال دعوة بالبريد', icon: 'mail-plus', disabled: !u.active, action: () => invite(u) },
    { label: 'كلمة مرور مؤقتة', icon: 'key', disabled: !u.active, action: () => resetPass(u) },
    { divider: true },
    { label: u.active ? 'إيقاف الحساب' : 'تفعيل الحساب', icon: u.active ? 'user-off' : 'user-on', disabled: u.id === me.value, action: () => setActive(u, !u.active) },
    { label: 'حذف الحساب', icon: 'trash', danger: true, disabled: u.id === me.value, action: () => removeUser(u) },
  ]
}
async function setActive(u, on) {
  if (!on && !await confirmBox(`إيقاف حساب ${u.employee?.name || u.name}؟ سيُخرج من كل الأجهزة ولن يستطيع الدخول حتى يُعاد تفعيله.`)) return
  try { await api.post('/users/bulk', { ids: [u.id], action: on ? 'activate' : 'deactivate' }).then((r) => { if (r.skipped.length) throw new Error(r.skipped[0].reason) }); ui.toast(on ? 'فُعّل الحساب' : 'أُوقف الحساب', 'ok'); load() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
async function removeUser(u) {
  if (!await confirmBox(`حذف حساب الدخول «${u.login}»؟ يبقى ملف الموظف وسجلاته كما هي، ويُحذف الحساب فقط.`, { title: 'حذف الحساب', confirmText: 'حذف' })) return
  try { const r = await api.delete(`/users/${u.id}`); ui.toast(r.message, r.result === 'deleted' ? 'ok' : 'warn', 6000); load() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
const BULK = { invite: ['إرسال دعوات الدخول', 'إرسال'], activate: ['تفعيل الحسابات', 'تفعيل'], deactivate: ['إيقاف الحسابات', 'إيقاف'], delete: ['حذف الحسابات', 'حذف'] }
async function bulk(action, rows, clear) {
  const n = rows.length
  const note = { invite: 'تصل كل موظف رسالة على بريده الرسمي لتفعيل حسابه. من لا بريد له يُتخطّى.', activate: '', deactivate: 'سيُخرجون من كل الأجهزة.', delete: 'تبقى ملفات الموظفين، ويُوقف بدل الحذف من له سجلات سابقة.' }[action]
  if (!await confirmBox(`${BULK[action][0]} لـ ${n} حساب؟ ${note}`, { title: BULK[action][0], confirmText: BULK[action][1], danger: action === 'delete' || action === 'deactivate' })) return
  try {
    const r = await api.post('/users/bulk', { ids: rows.map((u) => u.id), action })
    const lines = [action === 'invite' ? `أُرسلت ${r.emailed} دعوة بالبريد` : `تم ${BULK[action][1]} ${r.done} حساب`]
    if (r.deactivated) lines.push(`أُوقف ${r.deactivated} حساب بدل الحذف لوجود سجلات سابقة`)
    if (r.skipped.length) lines.push(`تُخطّي ${r.skipped.length}: ` + r.skipped.slice(0, 6).map((x) => `${x.login} (${x.reason})`).join('، ') + (r.skipped.length > 6 ? '…' : ''))
    await alertBox(lines.join(' — '), { icon: r.skipped.length ? 'warning' : 'success', title: BULK[action][0] })
    clear(); load()
  } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
async function revoke(d) { if (!await confirmBox('سحب التفويض؟')) return; await api.post(`/delegations/${d.id}/revoke`); loadDeleg() }
onMounted(load)
</script>
