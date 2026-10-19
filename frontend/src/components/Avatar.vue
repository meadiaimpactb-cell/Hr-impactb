<template>
  <span class="avatar av" :style="{ width: px, height: px, fontSize: Math.round(size * 0.38) + 'px' }" :title="editable ? 'تغيير الصورة' : name">
    <img v-if="current" :src="current" :alt="name" />
    <template v-else>{{ (name || '?')[0] }}</template>
    <template v-if="editable && userId">
      <button type="button" class="cam" :class="{ busy }" title="تغيير الصورة" @click.stop="pick"><Icon name="camera" :size="Math.max(12, Math.round(size * 0.3))" /></button>
      <button v-if="current" type="button" class="rm" title="حذف الصورة" @click.stop="remove"><Icon name="x" :size="11" /></button>
      <input ref="inp" type="file" accept="image/png,image/jpeg,image/webp" hidden @change="upload" />
    </template>
  </span>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import api from '../api'
import { alertBox, confirmBox } from '../dialog'
import { useUi } from '../stores/ui'

// A round profile photo with the first letter as a fallback. `editable` adds a camera button that uploads a new photo.
const props = defineProps({ name: { type: String, default: '' }, url: { type: String, default: null }, size: { type: Number, default: 30 }, userId: { type: Number, default: null }, editable: Boolean })
const emit = defineEmits(['changed'])
const ui = useUi()
const current = ref(props.url)
watch(() => props.url, (v) => { current.value = v })
const px = computed(() => props.size + 'px')
const inp = ref(null); const busy = ref(false)
const pick = () => inp.value?.click()
async function upload(ev) {
  const f = ev.target.files?.[0]; ev.target.value = ''
  if (!f) return
  if (f.size > 4 * 1024 * 1024) return alertBox('حجم الصورة أكبر من 4 ميجابايت', { icon: 'warning' })
  const fd = new FormData(); fd.append('photo', f)
  busy.value = true
  try { const r = await api.post(`/users/${props.userId}/photo`, fd); current.value = r.photo_url; emit('changed', r.photo_url); ui.toast('حُدّثت الصورة', 'ok') } catch (e) { alertBox(e.message, { icon: 'error' }) } finally { busy.value = false }
}
async function remove() {
  if (!(await confirmBox('حذف الصورة الشخصية؟', { confirmText: 'حذف' }))) return
  await api.delete(`/users/${props.userId}/photo`); current.value = null; emit('changed', null); ui.toast('حُذفت الصورة', 'ok')
}
</script>

<style scoped>
.av { position: relative; overflow: visible; }
.av img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block; }
.cam { position: absolute; bottom: -2px; inset-inline-end: -4px; border: 2px solid var(--card, #fff); background: var(--brand, var(--pri)); color: #fff; border-radius: 50%; width: 45%; height: 45%; min-width: 20px; min-height: 20px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
.cam.busy { opacity: .5; pointer-events: none; }
.rm { position: absolute; top: -2px; inset-inline-end: -4px; border: 0; background: var(--bad, #c0392b); color: #fff; border-radius: 50%; width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
</style>
