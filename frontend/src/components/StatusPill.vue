<template>
  <span class="pill" :class="s[1]">{{ t(s[0]) }}</span>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { STATUS } from '../utils'
const props = defineProps({ status: String })
const { t } = useI18n()
const s = computed(() => STATUS[props.status] || [props.status, 'gray'])
</script>
