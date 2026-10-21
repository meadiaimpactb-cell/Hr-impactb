<template>
  <div class="page-h">
    <div><h1>{{ t('مركز الإشعارات') }}</h1><p>إشعارات النظام بإجراء سريع</p></div>
    <div class="acts"><button class="btn sec sm" @click="readAll"><Icon name="check" :size="16" /> {{ t('تعليم الكل كمقروء') }}</button></div>
  </div>
  <div class="card">
    <div v-if="!items.length" class="empty"><div class="ic"><Icon name="bell" :size="44" :stroke="1.4" /></div>{{ t('لا إشعارات') }}</div>
    <div class="tabs" style="margin-top:0"><button :class="{ on: !onlyUnread }" @click="onlyUnread = false">الكل</button><button :class="{ on: onlyUnread }" @click="onlyUnread = true">غير المقروءة<span v-if="ui.unread" class="cnt">{{ ui.unread }}</span></button></div>
    <div v-for="n in shown" :key="n.id" class="row" style="padding:9px 0;border-bottom:1px solid var(--line2);cursor:pointer" :style="{ opacity: n.read_at ? 0.65 : 1 }" @click="read(n)">
      <span class="nicon" :class="n.kind"><Icon :name="icons[n.kind] || 'bell'" :size="18" /></span>
      <div class="grow"><b><span v-if="!n.read_at" class="udot" title="غير مقروء" />{{ n.title }}</b><div class="small muted">{{ n.body }}</div><div class="tiny muted">{{ fmtDT(n.created_at) }} · {{ n.read_at ? 'مقروء' : 'غير مقروء' }}</div></div>
      <button v-if="n.request_id" class="btn ghost xs" @click.stop="read(n); openId = n.request_id">فتح الطلب</button>
      <RouterLink v-else-if="n.link?.screen" class="btn ghost xs" :to="'/' + (n.link.screen === 'dash' ? '' : n.link.screen)" @click="read(n)">فتح</RouterLink>
    </div>
  </div>
  <RequestDetail v-if="openId" :id="openId" @close="openId = null" @changed="load" />
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
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
const onlyUnread = ref(false)
const shown = computed(() => items.value.filter((n) => !onlyUnread.value || !n.read_at))
async function readAll() { await api.post('/notifications/read'); ui.unread = 0; load() }
// a notification becomes read when it is opened, like a mail message
async function read(n) {
  if (n.read_at) return
  n.read_at = new Date().toISOString()
  ui.unread = Math.max(0, ui.unread - 1)
  await api.post('/notifications/read', { id: n.id }).catch(() => {})
}
onMounted(load)
</script>

<style scoped>
.udot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--brand);margin-inline-end:6px;vertical-align:middle}
</style>
