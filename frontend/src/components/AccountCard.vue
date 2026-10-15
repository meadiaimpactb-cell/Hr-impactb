<template>
  <div class="card">
    <h3><Icon name="key" :size="18" /> حساب الدخول</h3>
    <template v-if="user">
      <div class="row">
        <span>اسم الدخول: <b>{{ user.login }}</b></span>
        <span class="pill" :class="user.active ? 'ok' : 'gray'">{{ user.active ? 'نشط' : 'موقوف' }}</span>
        <span v-for="r in user.roles" :key="r" class="pill info">{{ auth.meta.roles?.[r] }}</span>
        <span class="small muted">آخر دخول: {{ user.last_login_at ? fmtDT(user.last_login_at) : '—' }}</span>
      </div>
      <div class="row mt">
        <button class="btn sec sm" @click="reset"><Icon name="refresh" :size="15" /> إعادة تعيين كلمة المرور</button>
        <button class="btn sm" :class="user.active ? 'dan' : 'okb'" @click="toggle"><Icon :name="user.active ? 'ban' : 'check-circle'" :size="15" /> {{ user.active ? 'إيقاف الحساب' : 'تفعيل الحساب' }}</button>
        <RouterLink class="btn ghost sm" to="/users"><Icon name="shield" :size="15" /> الأدوار والصلاحيات</RouterLink>
      </div>
    </template>
    <template v-else>
      <p class="small muted">لا يملك الموظف حساب دخول بعد.</p>
      <div class="fr3">
        <div class="fg"><label>اسم الدخول</label><input v-model="login" class="in" /></div>
        <div class="fg"><label>الدور</label><select v-model="role" class="in"><option v-for="(l, k) in roles" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="fg"><label>&nbsp;</label><button class="btn pri" :class="{ loading: busy }" @click="create"><Icon name="plus" :size="16" /> إنشاء الحساب</button></div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { alertBox, confirmBox } from '../dialog'
import { computed, ref } from 'vue'
import api from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtDT } from '../utils'

const props = defineProps({ employee: Object })
const emit = defineEmits(['changed'])
const auth = useAuth()
const ui = useUi()
const user = computed(() => props.employee.user)
const login = ref(props.employee.email_enabled && props.employee.email ? props.employee.email : props.employee.emp_no)
const role = ref('emp')
const busy = ref(false)
const roles = computed(() => Object.fromEntries(Object.entries(auth.meta.roles || {}).filter(([k]) => auth.has('super') || !['super', 'hr_mgr'].includes(k))))

async function create() {
  busy.value = true
  try {
    const r = await api.post('/users', { login: login.value, employee_id: props.employee.id, email: props.employee.email_enabled ? props.employee.email : null, roles: [role.value] })
    await alertBox(`اسم الدخول: ${r.user.login} — كلمة المرور المؤقتة: ${r.temp_password} (يُطلب تغييرها عند أول دخول)`, { icon: 'success', title: 'أُنشئ الحساب' })
    emit('changed')
  } catch { /* toast shown */ } finally { busy.value = false }
}
async function reset() {
  if (!await confirmBox('إعادة تعيين كلمة المرور؟ سيُخرج الموظف من كل الأجهزة.')) return
  const r = await api.post(`/users/${user.value.id}/reset-password`)
  await alertBox(`كلمة المرور المؤقتة: ${r.temp_password}`, { icon: 'success', title: 'أُعيد تعيين كلمة المرور' })
}
async function toggle() {
  const u = user.value
  await api.put(`/users/${u.id}`, { login: u.login, roles: u.roles, active: !u.active, employee_id: props.employee.id, company_id: u.company_id ?? null })
  ui.toast(u.active ? 'أُوقف الحساب وأُخرج من كل الأجهزة' : 'فُعّل الحساب', 'ok')
  emit('changed')
}
</script>
