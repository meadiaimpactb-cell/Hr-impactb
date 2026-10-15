<template>
  <div class="ib" :class="{ err: error || bad }">
    <span class="cc">{{ country }}</span>
    <input class="in" type="text" dir="ltr" inputmode="numeric" autocomplete="off" spellcheck="false" :placeholder="'00 0000 0000 0000 0000 0000'" :value="shown" maxlength="40" @input="onInput" @blur="validate" @paste.prevent="onPaste" />
  </div>
  <span v-if="bad" class="e">{{ bad }}</span>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

// Saudi IBAN: SA + 22 digits (2 check digits, 2 bank code, 18 account). v-model holds the compact value "SA0380000000608010167519".
// Pasting a full IBAN (with spaces or another country prefix) is accepted; the prefix stays fixed to the country.
const props = defineProps({ modelValue: { type: [String, null], default: '' }, country: { type: String, default: 'SA' }, error: Boolean })
const emit = defineEmits(['update:modelValue'])
const digits = ref('')
const bad = ref('')
const LEN = { SA: 22 }
const need = computed(() => LEN[props.country] ?? 30)
const shown = computed(() => digits.value.replace(/(.{4})/g, '$1 ').trim())

watch(() => props.modelValue, (v) => {
  const s = (v || '').replace(/\s/g, '').toUpperCase()
  if (s === (digits.value ? props.country + digits.value : '')) return // our own emit — keep the validation message
  digits.value = s.startsWith(props.country) ? s.slice(2) : s.replace(/^[A-Z]{2}/, ''); bad.value = ''
}, { immediate: true })

function accept(raw) {
  let s = raw.replace(/\s/g, '').toUpperCase()
  if (/^[A-Z]{2}/.test(s)) s = s.slice(2) // user typed / pasted the country prefix again
  digits.value = s.replace(/[^0-9A-Z]/g, '').slice(0, need.value)
  emit('update:modelValue', digits.value ? props.country + digits.value : '')
  if (digits.value.length === need.value) validate(); else bad.value = ''
}
function onInput(e) { accept(e.target.value); e.target.value = shown.value }
function onPaste(e) { accept(e.clipboardData.getData('text')) }

// ISO 7064 mod 97-10: move the first four characters to the end, letters → numbers, remainder must be 1
function mod97ok(iban) {
  const s = (iban.slice(4) + iban.slice(0, 4)).replace(/[A-Z]/g, (c) => c.charCodeAt(0) - 55)
  let r = 0
  for (const ch of s) r = (r * 10 + +ch) % 97
  return r === 1
}
function validate() {
  if (!digits.value) { bad.value = ''; return }
  const iban = props.country + digits.value
  if (digits.value.length !== need.value) bad.value = `الآيبان السعودي ${need.value} رقمًا بعد SA (أدخلت ${digits.value.length})`
  else if (props.country === 'SA' && !/^\d+$/.test(digits.value)) bad.value = 'الآيبان السعودي أرقام فقط بعد SA'
  else if (!mod97ok(iban)) bad.value = 'رقم الآيبان غير صحيح — تحقق من الأرقام'
  else bad.value = ''
}
</script>

<style scoped>
.ib { display: flex; direction: ltr; align-items: stretch; }
.ib .cc { display: flex; align-items: center; padding: 0 12px; border: 1px solid var(--line); border-right: 0; border-radius: var(--r10, 10px) 0 0 var(--r10, 10px); background: var(--soft); font-weight: 700; letter-spacing: .08em; color: var(--navy, #262E6B); }
.ib .in { border-radius: 0 var(--r10, 10px) var(--r10, 10px) 0; text-align: left; font-variant-numeric: tabular-nums; letter-spacing: .06em; font-family: ui-monospace, Consolas, monospace; }
.ib.err .in, .ib.err .cc { border-color: var(--bad); }
</style>
