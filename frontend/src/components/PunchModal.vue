<template>
  <Modal :title="isOut ? t('تسجيل انصراف') : t('تسجيل حضور')" size="narrow" @close="$emit('close')">
    <div class="center">
      <div class="punch" :class="{ out: isOut }"><Icon name="fingerprint" :size="40" :stroke="1.5" /><span>{{ isOut ? 'انصراف' : 'حضور' }}</span><small>{{ clock }}</small></div>
      <div class="small row" style="justify-content:center;gap:6px" :class="pos ? 'ok' : locating ? 'muted' : 'bad'">
        <Icon :name="pos ? 'map-pin' : locating ? 'hourglass' : 'alert'" :size="16" />
        {{ pos ? `تم تحديد الموقع · دقة ±${pos.accuracy} م` : locating ? 'جارٍ تحديد الموقع…' : 'تعذر تحديد الموقع — سيُرسل للمراجعة' }}
      </div>
    </div>
    <div v-if="auth.meta?.policy?.selfie_enabled" class="center mt">
      <video v-show="!shot" ref="video" autoplay playsinline muted style="width:min(200px,100%);aspect-ratio:4/3;height:auto;border-radius:12px;background:#000;object-fit:cover" />
      <img v-if="shot" :src="shot" alt="" style="width:min(200px,100%);aspect-ratio:4/3;height:auto;border-radius:12px;object-fit:cover" />
      <div class="row mt" style="justify-content:center"><button class="btn sec xs" @click="shot ? (shot = null, startCam()) : snap()"><Icon name="user" :size="14" /> {{ shot ? 'إعادة الالتقاط' : 'التقاط صورة' }}</button></div>
      <div class="tiny muted">الصورة الذاتية مطلوبة وفق سياسة الشركة</div>
    </div>
    <div v-if="isOut" class="fg mt">
      <label>المنجز اليومي <span v-if="noteReq" class="req">* مطلوب لاعتماد تحضير اليوم</span></label>
      <textarea v-model="note" class="in" rows="4" placeholder="ماذا أنجزت اليوم؟" />
      <span v-if="noteReq" class="tiny" :class="noteShort ? 'warn' : 'ok'">{{ noteShort ? `اكتب ${noteMin} حرفًا على الأقل (${note.trim().length}/${noteMin}). إن انصرفت دونه يبقى تحضير اليوم معلّقًا حتى تكتبه.` : 'المنجز مكتمل — سيُعتمد تحضير اليوم' }}</span>
    </div>
    <template #foot>
      <button class="btn sec" @click="$emit('close')">{{ t('إلغاء') }}</button>
      <button class="btn" :class="[isOut ? 'dan' : 'okb', { loading: busy }]" :disabled="busy || locating" @click="submit">{{ isOut ? 'تأكيد الانصراف' : 'تأكيد الحضور' }}</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtT, getPos } from '../utils'
import Modal from './Modal.vue'

const props = defineProps({ today: Object })
const emit = defineEmits(['close', 'done'])
const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const isOut = computed(() => !!props.today?.check_in && !props.today?.check_out)
const clock = ref(fmtT(new Date()))
const pos = ref(null)
const locating = ref(true)
const note = ref(props.today?.note || '')
const busy = ref(false)
// required by HR for this employee or the company (sent by /attendance/me), with the policy's minimum length
const noteReq = computed(() => !!(props.today?.note_required || auth.emp?.daily_note_required))
const noteMin = computed(() => auth.meta?.policy?.daily_note_min || 20)
const noteShort = computed(() => note.value.trim().length < noteMin.value)
const video = ref(null); const shot = ref(null); let stream = null
async function startCam() { try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }); if (video.value) video.value.srcObject = stream } catch { ui.toast('تعذر فتح الكاميرا', 'warn') } }
function snap() { const v = video.value; if (!v) return; const c = document.createElement('canvas'); c.width = 320; c.height = 240; c.getContext('2d').drawImage(v, 0, 0, 320, 240); shot.value = c.toDataURL('image/jpeg', 0.7); stream?.getTracks().forEach((t) => t.stop()) }
let timer
onMounted(async () => {
  timer = setInterval(() => (clock.value = fmtT(new Date())), 1000)
  if (auth.meta?.policy?.selfie_enabled) startCam()
  pos.value = await getPos()
  locating.value = false
})
onUnmounted(() => { clearInterval(timer); stream?.getTracks().forEach((t) => t.stop()) })

async function submit() {
  busy.value = true
  try {
    if (auth.meta?.policy?.selfie_enabled && !shot.value) { busy.value = false; return ui.toast('التقط صورتك أولًا', 'warn') }
    const fd = new FormData(); Object.entries(pos.value || {}).forEach(([k, v]) => fd.append(k, v)); if (note.value) fd.append('note', note.value); fd.append('device', navigator.userAgent.slice(0, 120))
    if (shot.value) fd.append('selfie', await (await fetch(shot.value)).blob(), 'selfie.jpg')
    const r = await api.post('/attendance/punch', fd)
    const late = r.record.late_minutes
    if (isOut.value && r.record.note_pending) ui.toast('سُجّل انصرافك — تحضير اليوم معلّق حتى تكتب المنجز اليومي من «الحضور والانصراف»', 'warn', 8000)
    else ui.toast(isOut.value ? 'سُجّل انصرافك' : `سُجّل حضورك${late ? ` — تأخير ${late} دقيقة` : ''}${r.record.pending ? ' (معلّق للمراجعة: ' + r.geo.msg + ')' : ''}`, r.record.pending ? 'warn' : 'ok', 5000)
    emit('done'); emit('close')
  } catch { /* toast shown by api */ } finally { busy.value = false }
}
</script>
