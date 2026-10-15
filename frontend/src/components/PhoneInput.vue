<template>
  <div class="pi" :class="{ err: error || (touched && !valid && national) }">
    <select v-model="country" class="in cc" :title="countryName(country)" @change="emitValue">
      <option v-for="c in countries" :key="c.value" :value="c.value">{{ c.value }} {{ c.sub }}</option>
    </select>
    <input v-model="national" class="in num" type="tel" inputmode="numeric" autocomplete="tel-national" :placeholder="placeholder" @input="onInput" @blur="touched = true" />
  </div>
  <span v-if="touched && national && !valid" class="e">رقم الجوال غير صحيح لهذه الدولة</span>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { AsYouType, getCountryCallingCode, isValidPhoneNumber, parsePhoneNumberFromString } from 'libphonenumber-js'
import { countryName, countryOptions } from '../countries'

// v-model holds the international number without spaces, e.g. +966551234567; the UI shows country code + national digits.
const props = defineProps({ modelValue: { type: String, default: '' }, defaultCountry: { type: String, default: 'SA' }, error: Boolean })
const emit = defineEmits(['update:modelValue'])
const countries = countryOptions()
const country = ref(props.defaultCountry)
const national = ref('')
const touched = ref(false)
const placeholder = computed(() => (country.value === 'SA' ? '5xxxxxxxx' : 'رقم الجوال'))
const valid = computed(() => !national.value || isValidPhoneNumber(national.value, country.value))

watch(() => props.modelValue, (v) => {
  const p = v ? parsePhoneNumberFromString(v.startsWith('+') ? v : '+' + v) : null
  if (p) { country.value = p.country || country.value; national.value = p.nationalNumber }
  else if (!v) national.value = ''
}, { immediate: true })

function onInput() {
  // digits only; a pasted +966… switches the country automatically
  const raw = national.value.replace(/[^\d+]/g, '')
  if (raw.startsWith('+')) { const p = parsePhoneNumberFromString(raw); if (p?.country) { country.value = p.country; national.value = p.nationalNumber; return emitValue() } }
  national.value = new AsYouType(country.value).input(raw).replace(/\D/g, '')
  if (national.value.startsWith('0') && country.value === 'SA') national.value = national.value.replace(/^0+/, '') // 05xx → 5xx
  emitValue()
}
function emitValue() {
  if (!national.value) return emit('update:modelValue', '')
  const p = parsePhoneNumberFromString(national.value, country.value)
  emit('update:modelValue', p ? p.number : '+' + getCountryCallingCode(country.value) + national.value)
}
</script>

<style scoped>
.pi { display: flex; gap: 6px; direction: ltr; }
.pi .cc { width: 118px; flex: none; font-variant-numeric: tabular-nums; }
.pi .num { flex: 1; min-width: 0; letter-spacing: .04em; }
.pi.err .in { border-color: var(--bad); }
</style>
