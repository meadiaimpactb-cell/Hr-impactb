<template>
  <div class="page-h"><div><h1>{{ t('سجل التدقيق') }}</h1><p>لا يُحذف ولا يُعدَّل — من غيّر ماذا ومتى ولماذا، والقيمة قبل وبعد</p></div></div>
  <div class="card">
    <div class="row mb"><div class="search"><Icon name="search" :size="16" /><input v-model="q" class="in" placeholder="بحث… (Enter)" @keyup.enter="load(1)" /></div>
      <select v-model="entity" class="in" style="width:auto" @change="load(1)"><option value="">كل الكيانات</option><option v-for="e in entities" :key="e">{{ e }}</option></select></div>
    <div v-dt v-if="page" class="tw"><table>
      <thead><tr><th>الوقت</th><th>المستخدم</th><th>الكيان</th><th>السجل</th><th>الإجراء</th><th>قبل</th><th>بعد</th><th>السبب</th><th>القناة</th></tr></thead>
      <tbody><tr v-for="a in page.data" :key="a.id">
        <td class="num small">{{ fmtDT(a.created_at) }}</td><td>{{ a.user?.name || 'النظام' }}</td><td>{{ a.entity }}</td><td class="small">{{ a.entity_id }}</td><td>{{ a.action }}</td>
        <td class="tiny" style="max-width:200px">{{ a.before ? JSON.stringify(a.before).slice(0, 90) : '—' }}</td>
        <td class="tiny" style="max-width:200px">{{ a.after ? JSON.stringify(a.after).slice(0, 90) : '—' }}</td>
        <td class="small">{{ a.reason }}</td><td>{{ a.channel === 'mobile' ? 'الجوال' : 'الويب' }}</td>
      </tr></tbody>
    </table></div>
    <div v-if="page && page.last_page > 1" class="row mt" style="justify-content:center">
      <button class="btn sec sm" :disabled="page.current_page === 1" @click="load(page.current_page - 1)">السابق</button>
      <span class="small">{{ page.current_page }} / {{ page.last_page }}</span>
      <button class="btn sec sm" :disabled="page.current_page === page.last_page" @click="load(page.current_page + 1)">التالي</button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { fmtDT } from '../utils'

const { t } = useI18n()
const page = ref(null)
const q = ref('')
const entity = ref('')
const entities = ref([])
api.get('/audit/entities').then((r) => (entities.value = r))
async function load(p = 1) { page.value = await api.get('/audit', { params: { page: p, q: q.value || undefined, entity: entity.value || undefined } }) }
onMounted(() => load(1))
</script>
