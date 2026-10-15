<template>
  <div class="kpi" :class="{ click: !!to }" :style="{ '--c': colors[color] }" @click="to && $router.push(to)">
    <div class="head">
      <div><div class="lab">{{ label }}</div><div class="val"><slot>{{ value }}</slot></div></div>
      <span v-if="icon" class="kico"><Icon :name="icon" :size="18" /></span>
    </div>
    <div v-if="hint" class="hint">{{ hint }}</div>
  </div>
</template>

<script setup>
defineProps({ icon: String, label: String, value: [String, Number], hint: String, color: { type: String, default: 'c' }, to: String })
const colors = { c: 'var(--cyan)', n: 'var(--navy)', g: 'var(--ok)', r: 'var(--bad)', y: 'var(--warn)', p: 'var(--purple)', b: 'var(--blue)' }
</script>
