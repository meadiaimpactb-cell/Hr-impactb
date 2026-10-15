<template>
  <div>
    <div class="row mb" style="justify-content:space-between">
      <div><b>التوقيع الإلكتروني</b><div class="tiny muted">يُستخدم لتوقيع الخطابات وإقرارات الاستلام — يُحفظ كصورة ويُثبَّت كل مستند ببصمة SHA-256</div></div>
      <img v-if="saved" :src="saved" alt="" style="height:48px;border:1px solid var(--line);border-radius:8px;background:#fff" />
    </div>
    <canvas ref="cv" width="520" height="160" style="width:100%;max-width:520px;height:160px;border:1px dashed var(--line);border-radius:10px;background:#fff;touch-action:none;direction:ltr" @pointerdown="start" @pointermove="move" @pointerup="end" @pointerleave="end" />
    <div class="row mt"><button class="btn sec sm" @click="clear">مسح</button><button class="btn pri sm" :class="{ loading: busy }" @click="save">حفظ التوقيع</button></div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../api'
import { useUi } from '../stores/ui'

const ui = useUi()
const cv = ref(null); const saved = ref(null); const busy = ref(false)
let drawing = false; let ctx; let dirty = false
onMounted(async () => { ctx = cv.value.getContext('2d'); ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0B2545'; saved.value = (await api.get('/signature')).signature })
const pos = (e) => { const r = cv.value.getBoundingClientRect(); return [(e.clientX - r.left) * (cv.value.width / r.width), (e.clientY - r.top) * (cv.value.height / r.height)] }
function start(e) { drawing = true; ctx.beginPath(); ctx.moveTo(...pos(e)) }
function move(e) { if (!drawing) return; ctx.lineTo(...pos(e)); ctx.stroke(); dirty = true }
function end() { drawing = false }
function clear() { ctx.clearRect(0, 0, cv.value.width, cv.value.height); dirty = false }
async function save() { if (!dirty) return ui.toast('ارسم توقيعك أولًا', 'warn'); busy.value = true; try { const data = cv.value.toDataURL('image/png'); await api.put('/signature', { signature: data }); saved.value = data; ui.toast('حُفظ توقيعك', 'ok') } finally { busy.value = false } }
</script>
