<template>
  <div class="login">
    <div class="box">
      <img src="/logo-full.png" alt="Impact Valley Capital" />
      <template v-if="state === 'checking'"><div class="skel" v-for="i in 3" :key="i" /></template>

      <template v-else-if="state === 'invalid'">
        <h2>الرابط غير صالح</h2>
        <p class="sub">انتهت صلاحية رابط {{ reset ? 'إعادة التعيين' : 'الدعوة' }} أو سبق استخدامه.</p>
        <RouterLink class="btn pri block" to="/forgot">طلب رابط جديد</RouterLink>
        <RouterLink class="btn ghost block mt" to="/login">الذهاب إلى تسجيل الدخول</RouterLink>
      </template>

      <template v-else-if="state === 'done'">
        <h2>تم التفعيل</h2>
        <p class="sub">جارٍ الدخول إلى المنصة…</p>
      </template>

      <template v-else>
        <h2>{{ reset ? 'كلمة مرور جديدة' : `مرحبًا ${info.name}` }}</h2>
        <p class="sub">{{ reset ? 'اختر كلمة مرور جديدة لحسابك' : 'خطوة واحدة لتفعيل حسابك: اختر كلمة المرور' }} <b dir="ltr">{{ info.login }}</b></p>
        <form novalidate @submit="onSubmit">
          <div class="fg">
            <label for="np">كلمة المرور</label>
            <div class="pw"><input id="np" v-model="password" v-bind="pAttrs" :type="show ? 'text' : 'password'" class="in" :class="{ err: errors.password }" autocomplete="new-password" autofocus /><button type="button" class="eye" :aria-label="show ? 'إخفاء' : 'إظهار'" @click="show = !show"><Icon :name="show ? 'eye-off' : 'eye'" :size="16" /></button></div>
            <PasswordChecklist :value="password || ''" />
          </div>
          <div class="fg">
            <label for="npc">تأكيد كلمة المرور</label>
            <div class="pw"><input id="npc" v-model="confirm" v-bind="cAttrs" :type="show ? 'text' : 'password'" class="in" :class="{ err: errors.password_confirmation }" autocomplete="new-password" /><button type="button" class="eye" :aria-label="show ? 'إخفاء' : 'إظهار'" @click="show = !show"><Icon :name="show ? 'eye-off' : 'eye'" :size="16" /></button></div>
            <span v-if="errors.password_confirmation" class="e">{{ errors.password_confirmation }}</span>
          </div>
          <div v-if="serverError" class="notice bad">{{ serverError }}</div>
          <button class="btn pri block" :class="{ loading: isSubmitting }" :disabled="isSubmitting">{{ reset ? 'حفظ والدخول' : 'تفعيل الحساب والدخول' }}</button>
        </form>
        <p class="tiny muted mt" style="text-align:center">بعد الدخول يمكنك تثبيت تطبيق الجوال من الصفحة الرئيسية.</p>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref as _ref } from 'vue'
const show = _ref(false)
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import api from '../api'
import PasswordChecklist from '../components/PasswordChecklist.vue'
import { passwordConfirmField, passwordField, withConfirmation } from '../lib/validation'
import { useAuth } from '../stores/auth'

// Invitation / password-reset landing page: /app/welcome?token=…[&reset=1]
const route = useRoute()
const router = useRouter()
const auth = useAuth()
const token = String(route.query.token || '')
const reset = route.query.reset === '1'
const state = ref('checking')
const info = ref({})
const serverError = ref('')

const { handleSubmit, errors, defineField, isSubmitting } = useForm({ validationSchema: toTypedSchema(withConfirmation({ password: passwordField, password_confirmation: passwordConfirmField })) })
const [password, pAttrs] = defineField('password')
const [confirm, cAttrs] = defineField('password_confirmation')

onMounted(async () => {
  if (!token) { state.value = 'invalid'; return }
  try { info.value = await api.post('/auth/invite/check', { token }); state.value = 'form' } catch { state.value = 'invalid' }
})
const onSubmit = handleSubmit(async (v) => {
  serverError.value = ''
  try {
    const r = await api.post('/auth/invite/accept', { token, ...v })
    localStorage.setItem('hc_token', r.token)
    auth.user = r.user; await auth.loadMeta(); auth.loaded = true
    state.value = 'done'
    setTimeout(() => router.replace('/'), 600)
  } catch (e) { serverError.value = Object.values(e.errors || {}).flat().join(' · ') || e.message || 'تعذر التفعيل' }
})
</script>

<style scoped>
.pw { position: relative; }
.pw .in { padding-inline-end: 42px; }
.eye { position: absolute; inset-inline-end: 6px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; display: grid; place-items: center; border: 0; background: transparent; color: var(--muted); border-radius: 8px; cursor: pointer; }
</style>

<style scoped>.block.mt { margin-top: 8px; }</style>
