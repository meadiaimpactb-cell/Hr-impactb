<template>
  <Teleport to="body">
    <!-- clicking the backdrop or pressing Escape never closes a dialog: typed data is only discarded through the X / cancel buttons -->
    <div class="mo" role="dialog" aria-modal="true" :aria-label="title">
      <div ref="box" class="box" :class="size">
        <div class="mh"><h3>{{ title }}</h3><button type="button" class="x" aria-label="إغلاق" @click="$emit('close')"><Icon name="x" :size="18" /></button></div>
        <div class="mb"><slot /></div>
        <div v-if="$slots.foot" class="mf"><slot name="foot" /></div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { lockScroll, trapFocus } from '../lib/scrollLock'
defineProps({ title: String, size: { type: String, default: '' } })
defineEmits(['close'])
const box = ref(null)
let release = null
onMounted(() => { lockScroll(true); release = trapFocus(box.value) }) // iOS-safe scroll lock; focus stays inside and returns to the opener on close
onUnmounted(() => { lockScroll(false); release?.() })
</script>
