<template>
  <ul class="pwc">
    <li v-for="(ok, k) in checks" :key="k" :class="{ ok }"><Icon :name="ok ? 'check-circle' : 'x-circle'" :size="14" /> {{ LABELS[k] }}</li>
  </ul>
</template>

<script setup>
import { computed } from 'vue'
import { PASSWORD_MIN, passwordChecks } from '../lib/validation'

const props = defineProps({ value: { type: String, default: '' } })
const checks = computed(() => passwordChecks(props.value))
const LABELS = { length: `${PASSWORD_MIN} أحرف على الأقل`, letter: 'حرف إنجليزي', number: 'رقم', symbol: 'رمز مثل @ أو #' }
</script>

<style scoped>
.pwc { list-style: none; padding: 0; margin: 6px 0 10px; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; font-size: 12.5px; color: var(--muted); }
.pwc li { display: flex; align-items: center; gap: 6px; }
.pwc li.ok { color: var(--ok); font-weight: 600; }
</style>
