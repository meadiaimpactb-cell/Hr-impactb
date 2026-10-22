<template>
  <div class="login">
    <form class="box" novalidate @submit="onSubmit">
      <img src="/logo-hc.png" alt="HC — إدارة رأس المال البشري" />
      <h2>نسيت كلمة المرور؟</h2>
      <p class="sub">أدخل بريدك الرسمي أو اسم المستخدم أو رقمك الوظيفي وسنرسل رابط إعادة التعيين إلى بريدك المسجل.</p>
      <template v-if="!sent">
        <div class="fg">
          <label for="fl">البريد أو اسم المستخدم</label>
          <input id="fl" v-model="login" v-bind="lAttrs" class="in" :class="{ err: errors.login }" autocomplete="username" autofocus />
          <span v-if="errors.login" class="e">{{ errors.login }}</span>
        </div>
        <div v-if="serverError" class="notice bad">{{ serverError }}</div>
        <button class="btn pri block" :class="{ loading: isSubmitting }" :disabled="isSubmitting">إرسال رابط إعادة التعيين</button>
      </template>
      <div v-else class="notice ok">إن كان الحساب مسجلًا ببريد، فقد أُرسل إليه رابط صالح لمدة 72 ساعة. تحقق من صندوق الوارد والرسائل غير المرغوبة.<br><span class="tiny">إن لم يكن لحسابك بريد، تواصل مع الموارد البشرية لإصدار كلمة مرور مؤقتة.</span></div>
      <RouterLink class="btn ghost block mt" to="/login">العودة إلى تسجيل الدخول</RouterLink>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import api from '../api'
import { loginField, z } from '../lib/validation'

const sent = ref(false)
const serverError = ref('')
const { handleSubmit, errors, defineField, isSubmitting } = useForm({ validationSchema: toTypedSchema(z.object({ login: loginField })) })
const [login, lAttrs] = defineField('login')
const onSubmit = handleSubmit(async (v) => {
  serverError.value = ''
  try { await api.post('/auth/forgot', { login: v.login.trim() }); sent.value = true } catch (e) { serverError.value = e.message || 'تعذر الإرسال' }
})
</script>

<style scoped>.block.mt { margin-top: 8px; }</style>
