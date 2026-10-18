<template>
  <div class="page-h">
    <div><h1>{{ t('ملفي') }} — {{ auth.user.name }}</h1><p>{{ auth.user.role_label }} · {{ auth.emp?.company?.name }}</p></div>
    <div class="acts"><RouterLink v-if="auth.emp" class="btn sec sm" :to="`/employees/${auth.emp.id}`">الملف الكامل</RouterLink></div>
  </div>
  <div v-if="auth.user.must_change_password" class="notice warn">كلمة مرورك مؤقتة — يجب تغييرها قبل استخدام المنصة.</div>
  <div class="grid g2">
    <div class="card">
      <h3>{{ t('تغيير كلمة المرور') }}</h3>
      <div class="fg"><label>كلمة المرور الحالية <span class="tiny muted">(اختياري)</span></label><div class="pw"><input v-model="f.current" :type="show ? 'text' : 'password'" class="in" :class="{ err: fe.current }" autocomplete="current-password" /><button type="button" class="eye" :aria-label="show ? 'إخفاء' : 'إظهار'" @click="show = !show"><Icon :name="show ? 'eye-off' : 'eye'" :size="16" /></button></div><span v-if="fe.current" class="e">{{ fe.current[0] }}</span></div>
      <div class="fg"><label>كلمة المرور الجديدة</label><div class="pw"><input v-model="f.password" :type="show ? 'text' : 'password'" class="in" :class="{ err: fe.password }" autocomplete="new-password" /><button type="button" class="eye" :aria-label="show ? 'إخفاء' : 'إظهار'" @click="show = !show"><Icon :name="show ? 'eye-off' : 'eye'" :size="16" /></button></div><PasswordChecklist :value="f.password" /></div>
      <div class="fg"><label>تأكيد الجديدة</label><div class="pw"><input v-model="f.password_confirmation" :type="show ? 'text' : 'password'" class="in" :class="{ err: fe.password_confirmation }" autocomplete="new-password" /><button type="button" class="eye" :aria-label="show ? 'إخفاء' : 'إظهار'" @click="show = !show"><Icon :name="show ? 'eye-off' : 'eye'" :size="16" /></button></div><span v-if="fe.password_confirmation" class="e">{{ fe.password_confirmation[0] }}</span></div>
      <div v-if="err" class="notice bad">{{ err }}</div>
      <button class="btn pri sm" :class="{ loading: saving }" :disabled="saving" @click="save">{{ t('حفظ') }}</button>
    </div>
    <div class="card">
      <h3>تفضيلات</h3>
      <div class="row"><button class="btn sec sm" @click="ui.setLang(ui.lang === 'ar' ? 'en' : 'ar'); saveLocale()">{{ ui.lang === 'ar' ? 'English' : 'العربية' }}</button><button class="btn sec sm" @click="ui.toggleTheme()"><Icon :name="ui.dark ? 'sun' : 'moon'" :size="16" /> {{ t('الوضع الليلي') }}</button></div>
      <p class="small muted mt">اسم الدخول: <b>{{ auth.user.login }}</b></p>
    </div>
  </div>
  <div class="card"><SignaturePad /></div>
</template>

<script setup>
import SignaturePad from '../components/SignaturePad.vue'
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import PasswordChecklist from '../components/PasswordChecklist.vue'
import { check, passwordConfirmField, passwordField, withConfirmation, z } from '../lib/validation'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const f = reactive({ current: '', password: '', password_confirmation: '' })
const err = ref('')
const fe = ref({})
const saving = ref(false)
const show = ref(false)
const pwSchema = withConfirmation({ current: z.string().optional(), password: passwordField, password_confirmation: passwordConfirmField })
async function save() {
  err.value = ''
  fe.value = check(pwSchema, f)
  if (Object.keys(fe.value).length) { if (fe.value.password) err.value = fe.value.password[0]; return }
  saving.value = true
  try { await api.post('/auth/password', f); ui.toast('غُيّرت كلمة المرور', 'ok'); auth.user.must_change_password = false; Object.assign(f, { current: '', password: '', password_confirmation: '' }) } catch (e) { err.value = Object.values(e.errors || {}).flat().join(' · ') } finally { saving.value = false }
}
const saveLocale = () => api.post('/auth/locale', { locale: ui.lang }).catch(() => {})
</script>

<style scoped>
.pw { position: relative; }
.pw .in { padding-inline-end: 42px; }
.eye { position: absolute; inset-inline-end: 6px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; display: grid; place-items: center; border: 0; background: transparent; color: var(--muted); border-radius: 8px; cursor: pointer; }
</style>
