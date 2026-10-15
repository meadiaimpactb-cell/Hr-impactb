<template>
  <div ref="root" class="am" @click.stop>
    <button type="button" class="btn ghost xs am-btn" :aria-expanded="open" aria-label="إجراءات" @click="toggle"><Icon name="more" :size="16" /></button>
    <Teleport to="body">
      <div v-if="open" class="am-menu" :style="pos" role="menu">
        <template v-for="(it, i) in items.filter((x) => !x.hidden)" :key="i">
          <div v-if="it.divider" class="am-div" />
          <button v-else type="button" role="menuitem" class="am-item" :class="{ danger: it.danger }" :disabled="it.disabled" @click="run(it)"><Icon v-if="it.icon" :name="it.icon" :size="15" /> {{ it.label }}</button>
        </template>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { onBeforeUnmount, ref } from 'vue'

// Compact "⋯" row menu. items: [{ label, icon?, danger?, disabled?, hidden?, divider?, action() }]
defineProps({ items: { type: Array, default: () => [] } })
const open = ref(false)
const root = ref(null)
const pos = ref({})
function toggle() {
  if (open.value) return close()
  const r = root.value.getBoundingClientRect()
  const below = window.innerHeight - r.bottom > 260
  pos.value = { left: `${Math.max(8, r.left)}px`, ...(below ? { top: `${r.bottom + 4}px` } : { bottom: `${window.innerHeight - r.top + 4}px` }) }
  open.value = true
  setTimeout(() => document.addEventListener('click', close, { once: true }))
  window.addEventListener('scroll', close, { once: true, capture: true })
}
function close() { open.value = false }
function run(it) { close(); it.action?.() }
onBeforeUnmount(() => document.removeEventListener('click', close))
</script>

<style scoped>
.am-btn { padding: 4px 6px; }
.am-menu { position: fixed; z-index: var(--z-popover); background: var(--card); border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 14px 36px rgba(15, 20, 48, .2); padding: 6px; min-width: 200px; max-width: calc(100vw - 16px); }
.am-item { display: flex; align-items: center; gap: 8px; width: 100%; border: 0; background: transparent; padding: 10px 12px; min-height: 40px; border-radius: 8px; font-size: 13px; text-align: start; color: var(--ink); cursor: pointer; }
.am-item:hover:not(:disabled) { background: var(--soft); }
.am-item.danger { color: var(--bad); }
.am-item:disabled { opacity: .45; cursor: not-allowed; }
.am-div { height: 1px; background: var(--line); margin: 4px 2px; }
</style>
