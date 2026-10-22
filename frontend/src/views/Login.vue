<template>
  <div class="login">
    <form class="box" novalidate @submit="onSubmit">
      <img src="/logo-hc.png" alt="HC — إدارة رأس المال البشري" />
      <h2>HC — {{ t('إدارة رأس المال البشري') }}</h2>
      <p class="sub">منصة واحدة لكل موظفي المجموعة</p>
      <div class="fg">
        <label for="login">{{ t('اسم المستخدم أو البريد أو رقم الموظف') }}</label>
        <input id="login" v-model="login" v-bind="loginAttrs" class="in" :class="{ err: errors.login }" autocomplete="username" autofocus />
        <span v-if="errors.login" class="e">{{ errors.login }}</span>
      </div>
      <div class="fg">
        <label for="password">{{ t('كلمة المرور') }}</label>
        <div class="pw">
          <input id="password" v-model="password" v-bind="passwordAttrs" :type="show ? 'text' : 'password'" class="in" :class="{ err: errors.password }" autocomplete="current-password" />
          <button type="button" class="eye" :aria-label="show ? 'إخفاء' : 'إظهار'" @click="show = !show"><Icon :name="show ? 'eye-off' : 'eye'" :size="16" /></button>
        </div>
        <span v-if="errors.password" class="e">{{ errors.password }}</span>
      </div>
      <div v-if="serverError" class="notice bad">{{ serverError }}</div>
      <button class="btn pri block" :class="{ loading: isSubmitting }" :disabled="isSubmitting">{{ t('دخول') }}</button>
      <RouterLink class="tiny" to="/forgot" style="display:block;text-align:center;margin-top:10px">نسيت كلمة المرور؟</RouterLink>
      <div class="row mt" style="justify-content:center">
        <button type="button" class="btn ghost xs" @click="ui.toggleTheme()"><Icon :name="ui.dark ? 'sun' : 'moon'" :size="16" /></button>
        <button type="button" class="btn ghost xs" @click="ui.setLang(ui.lang === 'ar' ? 'en' : 'ar')"><Icon name="languages" :size="16" /> {{ ui.lang === 'ar' ? 'English' : 'العربية' }}</button>
      </div>
      <a href="/" class="tiny muted mt" style="display:block;text-align:center">{{ t('العودة إلى صفحة التعريف وتحميل التطبيق') }}</a>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { loginField, z } from '../lib/validation'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const router = useRouter()
const show = ref(false)
const serverError = ref('')

const { handleSubmit, errors, defineField, isSubmitting } = useForm({
  validationSchema: toTypedSchema(z.object({ login: loginField, password: z.string({ required_error: 'كلمة المرور مطلوبة' }).min(1, 'كلمة المرور مطلوبة') })),
})
const [login, loginAttrs] = defineField('login')
const [password, passwordAttrs] = defineField('password')

const onSubmit = handleSubmit(async (v) => {
  serverError.value = ''
  try {
    await auth.login(v.login.trim(), v.password)
    router.push(auth.user.must_change_password ? '/me' : '/')
  } catch (e) {
    serverError.value = e.errors?.login?.[0] || e.message || 'تعذر تسجيل الدخول'
  }
})
</script>

<style scoped>
.pw { position: relative; }
.pw .in { padding-inline-end: 38px; }
.eye { position: absolute; inset-inline-end: 6px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; display: grid; place-items: center; border: 0; background: transparent; color: var(--muted); border-radius: 8px; cursor: pointer; }
</style>
