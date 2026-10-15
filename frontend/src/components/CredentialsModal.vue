<template>
  <Modal :title="title" @close="$emit('close')">
    <div v-if="emailed" class="notice ok">أُرسلت دعوة التفعيل إلى <b dir="ltr">{{ email }}</b>. يفتح الموظف الرابط، يختار كلمة مروره، ويدخل مباشرة.</div>
    <div v-else-if="inviteUrl" class="notice warn">لم يُرسل بريد{{ email ? ' (البريد غير مفعّل على الخادم بعد)' : ' لأن الموظف بلا بريد' }}. أرسل رابط التفعيل أدناه للموظف بنفسك (واتساب أو غيره)؛ صالح {{ hours }} ساعة ويُستخدم مرة واحدة.</div>
    <div class="cred">
      <div class="row"><span class="k">اسم المستخدم</span><b dir="ltr">{{ login }}</b><button class="btn ghost xs" @click="copy(login)"><Icon name="copy" :size="14" /></button></div>
      <div v-if="inviteUrl" class="row"><span class="k">رابط التفعيل</span><b dir="ltr" class="url">{{ inviteUrl }}</b><button class="btn ghost xs" @click="copy(inviteUrl)"><Icon name="copy" :size="14" /></button></div>
      <div v-if="password" class="row"><span class="k">كلمة مرور مؤقتة</span><b dir="ltr" class="pw">{{ password }}</b><button class="btn ghost xs" @click="copy(password)"><Icon name="copy" :size="14" /></button></div>
      <div class="row"><span class="k">رابط الدخول</span><b dir="ltr">{{ url }}</b><button class="btn ghost xs" @click="copy(url)"><Icon name="copy" :size="14" /></button></div>
    </div>
    <p class="tiny muted" style="margin:12px 0 0">{{ password ? 'كلمة المرور المؤقتة بديل عند تعذر الرابط، ويُطلب تغييرها عند أول دخول. ' : '' }}الدخول يكون باسم المستخدم أو رقم الموظف أو البريد.</p>
    <div class="row mt"><button class="btn sec sm" @click="copyAll">نسخ الكل كرسالة</button></div>
    <template #foot><button class="btn pri" @click="$emit('close')">تم</button></template>
  </Modal>
</template>

<script setup>
import Modal from './Modal.vue'
import { useUi } from '../stores/ui'

const props = defineProps({ login: String, password: { type: String, default: '' }, inviteUrl: { type: String, default: '' }, email: { type: String, default: '' }, emailed: Boolean, hours: { type: Number, default: 72 }, title: { type: String, default: 'بيانات الدخول' } })
defineEmits(['close'])
const ui = useUi()
const url = `${location.origin}/app/login`
async function copy(t) { try { await navigator.clipboard.writeText(t); ui.toast('نُسخ', 'ok') } catch { ui.toast('تعذر النسخ', 'bad') } }
function copyAll() {
  const lines = ['بيانات الدخول إلى منصة HC — وادي الأثر المالية', `اسم المستخدم: ${props.login}`]
  if (props.inviteUrl) lines.push(`رابط تفعيل الحساب واختيار كلمة المرور (صالح ${props.hours} ساعة): ${props.inviteUrl}`)
  if (props.password) lines.push(`كلمة المرور المؤقتة: ${props.password} (يُطلب تغييرها عند أول دخول)`)
  lines.push(`رابط الدخول: ${url}`)
  copy(lines.join('\n'))
}
</script>

<style scoped>
.cred { background: var(--soft); border-radius: 12px; padding: 10px 14px; display: grid; gap: 8px; }
.cred .row { justify-content: space-between; flex-wrap: nowrap; gap: 10px; }
.cred .k { color: var(--muted); font-size: 13px; min-width: 120px; flex: none; }
.cred b { flex: 1; text-align: left; font-variant-numeric: tabular-nums; min-width: 0; }
.cred .url { font-size: 12px; word-break: break-all; }
.cred .pw { font-family: ui-monospace, Consolas, monospace; letter-spacing: .08em; font-size: 16px; }
</style>
