<template>
  <div class="di" :class="{ err: error || bad }">
    <input :id="id" ref="txt" class="in" type="text" inputmode="numeric" dir="ltr" :placeholder="placeholder" :value="text" :disabled="disabled" autocomplete="off" maxlength="10" @input="onInput" @blur="onBlur" @keydown.enter="onBlur" />
    <button type="button" class="cal" :disabled="disabled" tabindex="-1" aria-label="التقويم" @click="openPicker"><Icon name="calendar" :size="16" /></button>
    <input ref="native" class="native" type="date" :min="min" :max="max" :value="modelValue || ''" tabindex="-1" aria-hidden="true" @change="fromNative" />
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import Icon from './Icon.vue'

// Date field that always reads and writes day/month/year regardless of the browser's locale.
// v-model stays ISO (yyyy-mm-dd) so nothing else in the app changes; the calendar button opens the native picker.
const props = defineProps({ modelValue: { type: [String, null], default: null }, min: String, max: String, error: Boolean, disabled: Boolean, id: String, placeholder: { type: String, default: 'يوم/شهر/سنة' } })
const emit = defineEmits(['update:modelValue'])
const txt = ref(null)
const native = ref(null)
const text = ref('')
const bad = ref(false)

const toText = (iso) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? `${m[3]}/${m[2]}/${m[1]}` : '' }
watch(() => props.modelValue, (v) => { text.value = toText(v); bad.value = false }, { immediate: true })

function onInput(e) {
  const d = e.target.value.replace(/\D/g, '').slice(0, 8)
  text.value = d.length > 4 ? `${d.slice(0, 2)}/${d.slice(2, 4)}/${d.slice(4)}` : d.length > 2 ? `${d.slice(0, 2)}/${d.slice(2)}` : d
  e.target.value = text.value
  if (d.length === 8) commit()
}
function commit() {
  const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text.value)
  if (!m) { bad.value = !!text.value; if (!text.value) emit('update:modelValue', null); return }
  const [dd, mm, yyyy] = [+m[1], +m[2], +m[3]]
  const dt = new Date(yyyy, mm - 1, dd)
  const ok = dt.getFullYear() === yyyy && dt.getMonth() === mm - 1 && dt.getDate() === dd
  bad.value = !ok
  if (ok) { const iso = `${yyyy}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`; text.value = toText(iso); emit('update:modelValue', iso) }
}
function onBlur() { commit() }
function fromNative(e) { if (e.target.value) { emit('update:modelValue', e.target.value); bad.value = false } }
function openPicker() {
  const n = native.value
  try { n.showPicker() } catch { n.focus(); n.click() }
}
</script>

<style scoped>
.di { position: relative; }
.di .in { padding-right: 38px; padding-left: 12px; text-align: left; font-variant-numeric: tabular-nums; letter-spacing: .03em; }
.di.err .in { border-color: var(--bad); }
.cal { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; display: grid; place-items: center; border: 0; background: transparent; color: var(--muted); border-radius: 8px; cursor: pointer; }
.cal:hover { background: var(--soft); color: var(--accent, #1C84C6); }
.native { position: absolute; right: 6px; top: 50%; width: 28px; height: 28px; opacity: 0; pointer-events: none; }
</style>
