<template>
  <div class="page-h">
    <div><h1>{{ t('طلباتي') }}</h1><p>جميع طلباتك وحالتها ومسار اعتمادها</p></div>
    <div class="acts"><button class="btn pri sm" @click="newReq = true"><Icon name="plus" :size="16" /> {{ t('طلب جديد') }}</button></div>
  </div>
  <div class="card">
    <div v-if="loading"><div class="skel" v-for="i in 4" :key="i" /></div>
    <div v-else-if="!rows.length" class="empty"><div class="ic"><Icon name="folder" :size="44" :stroke="1.4" /></div>لم تقدّم أي طلب بعد</div>
    <div v-dt v-else class="tw"><table>
      <thead><tr><th>{{ t('الطلب') }}</th><th>التفاصيل</th><th>{{ t('المرحلة') }}</th><th>{{ t('الحالة') }}</th><th>{{ t('التاريخ') }}</th><th /></tr></thead>
      <tbody><tr v-for="r in rows" :key="r.id">
        <td><b>{{ r.type_label }}</b></td><td>{{ r.summary }}</td>
        <td>{{ r.status === 'pending' ? `${r.stage + 1}/${r.flow.length} — ${r.stage_label}` : '—' }}<div v-if="r.waiting_for?.length" class="tiny muted">بانتظار: {{ r.waiting_for.join('، ') }}</div></td>
        <td><StatusPill :status="r.status" /></td><td>{{ fmtD(r.created_at) }}</td>
        <td><button class="btn ghost xs" @click="openId = r.id">{{ t('تفاصيل') }}</button></td>
      </tr></tbody>
    </table></div>
  </div>
  <NewRequest v-if="newReq" @close="newReq = false" @done="load" />
  <RequestDetail v-if="openId" :id="openId" @close="openId = null" @changed="load" />
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import NewRequest from '../components/NewRequest.vue'
import RequestDetail from '../components/RequestDetail.vue'
import StatusPill from '../components/StatusPill.vue'
import { fmtD } from '../utils'

const { t } = useI18n()
const rows = ref([])
const loading = ref(true)
const newReq = ref(false)
const openId = ref(null)
async function load() { loading.value = true; rows.value = await api.get('/requests/mine'); loading.value = false }
onMounted(load)
</script>
