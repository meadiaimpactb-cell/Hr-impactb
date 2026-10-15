<template>
  <div class="ss">
    <input v-model="q" class="in" :class="{ err: error }" :placeholder="placeholder" autocomplete="off" :disabled="disabled"
      @focus="open = true; hl = 0" @input="open = true; hl = 0" @blur="onBlur" @keydown.down.prevent="hl = Math.min(hl + 1, list.length - 1)"
      @keydown.up.prevent="hl = Math.max(hl - 1, 0)" @keydown.enter.prevent="list[hl] ? pick(list[hl]) : canAdd && add()" />
    <div v-if="open" class="dd">
      <div v-for="(o, i) in list" :key="o.value" :class="{ hl: i === hl }" @mousedown.prevent="pick(o)">{{ o.label }}<small v-if="o.sub">{{ o.sub }}</small></div>
      <div v-if="!list.length && !canAdd" class="muted">لا توجد نتائج</div>
      <div v-if="canAdd" class="addrow" @mousedown.prevent="add"><Icon name="plus" :size="14" /> {{ addLabel }} «{{ q.trim() }}»</div>
      <div v-else-if="addLabel && !q.trim()" class="addrow muted" @mousedown.prevent="add"><Icon name="plus" :size="14" /> {{ addLabel }}</div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { norm } from '../utils'

// Searchable single select. With `addLabel`, the dropdown ends with "+ <addLabel> «typed text»" and emits `add` so the parent can create the item inline.
const props = defineProps({ modelValue: [String, Number, null], options: { type: Array, default: () => [] }, placeholder: { type: String, default: 'ابحث واختر…' }, error: Boolean, addLabel: { type: String, default: '' }, disabled: Boolean })
const emit = defineEmits(['update:modelValue', 'add'])
const q = ref('')
const open = ref(false)
const hl = ref(0)
const label = () => props.options.find((o) => o.value == props.modelValue)?.label || ''
watch(() => [props.modelValue, props.options], () => { q.value = label() }, { immediate: true })
const list = computed(() => {
  const s = norm(q.value)
  const cur = label()
  return props.options.filter((o) => !s || s === norm(cur) || norm(o.label).includes(s) || norm(o.sub).includes(s)).slice(0, 80)
})
const canAdd = computed(() => !!props.addLabel && !!q.value.trim() && q.value !== label() && !props.options.some((o) => norm(o.label) === norm(q.value)))
function pick(o) { emit('update:modelValue', o.value); q.value = o.label; open.value = false }
function add() { const text = q.value.trim(); open.value = false; emit('add', text) }
function onBlur() { setTimeout(() => { open.value = false; if (!q.value) emit('update:modelValue', null); else q.value = label() }, 150) }
</script>

<style scoped>
.addrow { border-top: 1px solid var(--line); color: var(--accent, #1C84C6); font-weight: 600; display: flex; align-items: center; gap: 6px; }
</style>
