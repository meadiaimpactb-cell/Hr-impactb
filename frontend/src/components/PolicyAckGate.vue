<template>
  <!-- Appendix 4: the current policy version must be acknowledged before using the portal (blocking) -->
  <div v-if="st?.required" class="mo" style="z-index:300">
    <div class="box" style="max-width:720px">
      <div class="mh"><h3 class="row" style="gap:8px;align-items:center"><Icon name="shield" :size="18" /> نموذج إقرار الاطلاع والالتزام — الإصدار {{ st.version }}</h3></div>
      <div class="mb">
        <p style="line-height:1.9">{{ st.text }}</p>
        <div v-dt class="tw"><table><tbody>
          <tr><th>الاسم</th><td>{{ st.employee?.name }}</td></tr><tr><th>الرقم الوظيفي</th><td class="num">{{ st.employee?.emp_no }}</td></tr>
          <tr><th>الشركة</th><td>{{ st.employee?.company || '—' }}</td></tr><tr><th>المسمى الوظيفي</th><td>{{ st.employee?.job || '—' }}</td></tr>
          <tr><th>التاريخ</th><td class="num">{{ fmtD(today()) }}</td></tr>
        </tbody></table></div>
        <div class="fg mt"><label>اكتب اسمك الكامل توقيعًا *</label><input v-model="name" class="in" /></div>
        <div class="fg"><label>التوقيع (ارسمه أو اتركه ليُستخدم توقيعك المحفوظ)</label>
          <canvas ref="cv" width="520" height="130" style="width:100%;max-width:520px;height:130px;border:1px dashed var(--line);border-radius:10px;background:#fff;touch-action:none;direction:ltr" @pointerdown="start" @pointermove="move" @pointerup="drawing = false" @pointerleave="drawing = false" />
          <div><button type="button" class="btn ghost xs" @click="clear">مسح التوقيع</button></div></div>
        <label class="chk"><input v-model="agree" type="checkbox" /> اطلعت على اللائحة وأقرّ بما ورد أعلاه</label>
        <p class="tiny muted">يُوقَّع هذا الإقرار إلكترونيًا عند التعيين وعند كل تعديل جوهري للائحة، ويُحفظ في ملفك الوظيفي مع التاريخ وعنوان الشبكة. لا يُعد تنازلًا عن أي حق نظامي أو تعاقدي.</p>
      </div>
      <div class="mf"><button class="btn ghost" @click="logout"><Icon name="logout" :size="15" flip-rtl /> خروج</button><button class="btn okb" :class="{ loading: busy }" @click="sign"><Icon name="check-circle" :size="15" /> أقرّ وأوقّع</button></div>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import api from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtD, today } from '../utils'

const auth = useAuth(); const ui = useUi()
const st = ref(null); const name = ref(''); const agree = ref(false); const busy = ref(false); const cv = ref(null); const drawing = ref(false)
let ctx = null; let dirty = false
const pos = (e) => { const r = cv.value.getBoundingClientRect(); return [(e.clientX - r.left) * (cv.value.width / r.width), (e.clientY - r.top) * (cv.value.height / r.height)] }
function start(e) { if (!ctx) return; drawing.value = true; ctx.beginPath(); ctx.moveTo(...pos(e)) }
function move(e) { if (!drawing.value) return; ctx.lineTo(...pos(e)); ctx.stroke(); dirty = true }
function clear() { ctx?.clearRect(0, 0, cv.value.width, cv.value.height); dirty = false }
async function load() { try { st.value = await api.get('/policy-ack'); name.value = st.value?.employee?.name || '' } catch { st.value = null } }
watch(() => st.value?.required, async (r) => { if (!r) return; await nextTick(); if (cv.value) { ctx = cv.value.getContext('2d'); ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0B2545' } })
async function sign() {
  if (!agree.value) return ui.toast('أكّد الاطلاع والإقرار', 'warn')
  if ((name.value || '').trim().length < 3) return ui.toast('اكتب اسمك الكامل', 'warn')
  busy.value = true
  try { st.value = await api.post('/policy-ack', { typed_name: name.value.trim(), agree: true, signature: dirty ? cv.value.toDataURL('image/png') : null }); ui.toast('سُجّل إقرارك وحُفظ في ملفك الوظيفي', 'ok') } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function logout() { await auth.logout(); window.location.assign('/') }
onMounted(load)
</script>
