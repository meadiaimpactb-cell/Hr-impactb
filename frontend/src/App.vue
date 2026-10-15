<template>
  <RouterView />
  <div class="toasts">
    <div v-for="t in ui.toasts" :key="t.id" class="toast" :class="t.kind"><Icon :name="{ ok: 'check-circle', bad: 'x-circle', warn: 'warning' }[t.kind] || 'info'" :size="18" />{{ t.msg }}</div>
  </div>
</template>

<script setup>
import { useUi } from './stores/ui'
const ui = useUi()
</script>
