<template>
  <div class="page-h">
    <div><h1>{{ t('مركز الإشعارات') }}</h1><p>إشعارات النظام بإجراء سريع</p></div>
    <div class="acts"><button class="btn sec sm" @click="readAll"><Icon name="check" :size="16" /> {{ t('تعليم الكل كمقروء') }}</button></div>
  </div>
  <div class="card">
    <div v-if="!items.length" class="empty"><div class="ic"><Icon name="bell" :size="44" :stroke="1.4" /></div>{{ t('لا إشعارات') }}</div>
    <div v-for="n in items" :key="n.id" class="row" style="padding:9px 0;border-bottom:1px solid var(--line2)" :style="{ opacity: n.read_at ? 0.65 : 1 }">
      <span class="nicon" :class="n.kind"><Icon :name="icons[n.kind] || 'bell'" :size="18" /></span>
      <div class="grow"><b>{{ n.title }}</b><div class="small muted">{{ n.body }}</div><div class="tiny muted">{{ fmtDT(n.created_at) }}</div></div>
      <button v-if="n.request_id" class="btn ghost xs" @click="openId = n.request_id">فتح الطلب</button>
      <RouterLink v-else-if="n.link?.screen" class="btn ghost xs" :to="'/' + (n.link.screen === 'dash' ? '' : n.link.screen)">فتح</RouterLink>
    </div>
  </div>
  <RequestDetail v-if="openId" :id="openId" @close="openId = null" @changed="load" />
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import RequestDetail from '../components/RequestDetail.vue'
import { useUi } from '../stores/ui'
import { fmtDT } from '../utils'

const { t } = useI18n()
const ui = useUi()
const items = ref([])
const openId = ref(null)
const icons = { approval: 'approvals', ok: 'check-circle', bad: 'x-circle', warn: 'warning', info: 'info' }
async function load() { items.value = await api.get('/notifications') }
async function readAll() { await api.post('/notifications/read'); ui.unread = 0; load() }
onMounted(async () => { await load(); await api.post('/notifications/read'); ui.unread = 0 })
</script>
