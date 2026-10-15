<template>
  <div class="page-h">
    <div><h1>{{ t('صندوق الموافقات') }}</h1><p>مرتبة بالأقدمية — الأحمر تجاوز {{ sla }} أيام</p></div>
    <div class="acts"><button class="btn pri sm" :disabled="!selected.length" @click="bulk"><Icon name="check-circle" :size="16" /> {{ t('اعتماد المحدد') }} ({{ selected.length }})</button></div>
  </div>
  <div class="tabs"><button :class="{ on: tab === 'inbox' }" @click="tab = 'inbox'">بانتظاري ({{ rows.length }})</button><button v-if="auth.isHr" :class="{ on: tab === 'all' }" @click="tab = 'all'; loadAll()">متابعة كل الطلبات</button><button :class="{ on: tab === 'deleg' }" @click="tab = 'deleg'">تفويض اعتماداتي</button></div>
  <DelegationPanel v-if="tab === 'deleg'" />
  <div v-else-if="tab === 'all'" class="card">
    <div class="row mb" style="gap:8px;flex-wrap:wrap;align-items:center">
      <select v-model="allStatus" class="in" style="width:auto" @change="allPage = 1; loadAll()"><option value="pending">بانتظار الاعتماد</option><option value="approved">معتمدة</option><option value="returned">مُرجعة</option><option value="">كل الحالات</option></select>
      <span class="tiny muted">كل طلبات الموظفين في نطاقك ومن ينتظرها الآن. للتصفية حسب الشركة استخدم القائمة العلوية.</span>
    </div>
    <div v-if="allLoading"><div class="skel" v-for="i in 4" :key="i" /></div>
    <div v-else-if="!all.data?.length" class="empty">لا طلبات بهذه الحالة</div>
    <div v-else class="tw"><table>
      <thead><tr><th>الطلب</th><th>مقدّم الطلب</th><th>الشركة</th><th>المرحلة</th><th>بانتظار</th><th>التاريخ</th><th /></tr></thead>
      <tbody><tr v-for="r in all.data" :key="r.id">
        <td><b>{{ r.type_label }}</b><span class="tiny muted">{{ r.summary }}</span></td>
        <td><RouterLink :to="`/employees/${r.employee?.id}`">{{ r.employee?.name }}</RouterLink></td>
        <td>{{ r.company }}</td>
        <td>{{ r.status === 'pending' ? `${r.stage + 1}/${r.flow.length} — ${r.stage_label}` : '—' }}</td>
        <td>{{ r.waiting_for?.length ? r.waiting_for.join('، ') : '—' }}</td>
        <td class="num">{{ fmtD(r.created_at) }}<span v-if="r.status === 'pending'" class="tiny" :class="{ bad: r.age_days >= sla }"> · {{ r.age_days }} يوم</span></td>
        <td><button class="btn ghost xs" @click="openId = r.id">تفاصيل</button></td>
      </tr></tbody>
    </table></div>
    <div v-if="all.last_page > 1" class="row mt" style="justify-content:center;gap:8px"><button class="btn ghost sm" :disabled="allPage <= 1" @click="allPage--; loadAll()">السابق</button><span class="small">صفحة {{ allPage }} من {{ all.last_page }}</span><button class="btn ghost sm" :disabled="allPage >= all.last_page" @click="allPage++; loadAll()">التالي</button></div>
  </div>
  <div v-else class="card">
    <div v-if="loading"><div class="skel" v-for="i in 4" :key="i" /></div>
    <div v-else-if="!rows.length" class="empty"><div class="ic"><Icon name="inbox" :size="44" :stroke="1.4" /></div>لا يوجد ما ينتظر اعتمادك</div>
    <div v-dt v-else class="tw"><table>
      <thead><tr><th><input type="checkbox" :checked="selected.length === rows.length" @change="selected = $event.target.checked ? rows.map((r) => r.id) : []" /></th>
        <th>{{ t('الطلب') }}</th><th>{{ t('مقدّم الطلب') }}</th><th>{{ t('الشركة') }}</th><th>{{ t('المرحلة') }}</th><th>{{ t('التاريخ') }}</th><th>{{ t('الانتظار') }}</th><th /></tr></thead>
      <tbody><tr v-for="r in rows" :key="r.id">
        <td><input v-model="selected" type="checkbox" :value="r.id" /></td>
        <td><b>{{ r.type_label }}</b><span class="tiny muted">{{ r.summary }}</span></td>
        <td><RouterLink :to="`/employees/${r.employee?.id}`">{{ r.employee?.name }}</RouterLink></td>
        <td>{{ r.company }}</td>
        <td>{{ r.stage + 1 }}/{{ r.flow.length }} — {{ r.stage_label }}</td>
        <td>{{ fmtD(r.created_at) }}</td>
        <td><span :class="{ 'pill bad': r.age_days >= sla }">{{ r.age_days }} يوم</span></td>
        <td><button class="btn okb xs" @click="openId = r.id">{{ t('تفاصيل') }} / {{ t('اعتماد') }}</button></td>
      </tr></tbody>
    </table></div>
  </div>
  <RequestDetail v-if="openId" :id="openId" @close="openId = null" @changed="load(); tab === 'all' && loadAll()" />
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import DelegationPanel from '../components/DelegationPanel.vue'
import RequestDetail from '../components/RequestDetail.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtD } from '../utils'

const { t } = useI18n()
const ui = useUi()
const auth = useAuth()
const tab = ref('inbox')
const sla = computed(() => auth.meta?.policy?.sla_days || 3)
const rows = ref([])
const loading = ref(true)
const selected = ref([])
const openId = ref(null)
async function load() { loading.value = true; rows.value = await api.get('/requests/inbox'); selected.value = []; loading.value = false }
// HR follow-up of every request in scope (who it is waiting for)
const all = ref({}); const allStatus = ref('pending'); const allPage = ref(1); const allLoading = ref(false)
async function loadAll() { allLoading.value = true; try { all.value = await api.get('/requests', { params: { status: allStatus.value || undefined, page: allPage.value, company_id: ui.company || undefined } }) } finally { allLoading.value = false } }
async function bulk() {
  if (!await confirmBox(`اعتماد ${selected.value.length} طلب؟ يُسجَّل كل قرار مستقلًا في سجل التدقيق.`)) return
  const r = await api.post('/requests/bulk-approve', { ids: selected.value })
  ui.toast(`اعتُمد ${r.approved} طلب`, 'ok')
  load()
}
onMounted(load)
</script>
