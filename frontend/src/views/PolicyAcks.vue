<template>
  <div class="page-h">
    <div><h1>إقرارات اللائحة</h1><p>من أقرّ ومن لم يقرّ بالإصدار الحالي من اللائحة (الملحق الرابع). يُطلب الإقرار عند التعيين وعند كل تعديل جوهري، ويُحفظ في الملف الوظيفي.</p></div>
    <div class="acts"><button v-if="rep?.rows?.length" class="btn ghost sm" @click="exportX"><Icon name="download" :size="16" /> Excel</button><button v-if="rep?.can_bump" class="btn pri sm" @click="bump"><Icon name="refresh" :size="16" /> إصدار جديد يتطلب إعادة الإقرار</button></div>
  </div>
  <div v-if="rep" class="grid g3 mb"><Kpi label="الإصدار الحالي" :value="rep.version" color="b" /><Kpi label="أقرّوا" :value="rep.signed" color="g" /><Kpi label="لم يقرّوا" :value="rep.total - rep.signed" color="y" /></div>
  <div class="card">
    <div class="row mb"><select v-model="filter" class="in" style="width:auto"><option value="">الكل</option><option value="no">لم يقرّوا</option><option value="yes">أقرّوا</option></select><input v-model="q" class="in" style="max-width:240px" placeholder="بحث بالاسم أو الرقم" /></div>
    <div v-if="!rows.length" class="empty">لا نتائج</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>الشركة</th><th>الإصدار</th><th>الحالة</th><th>تاريخ الإقرار</th><th>عنوان الشبكة</th><th>إصدارات سابقة</th></tr></thead>
      <tbody><tr v-for="r in rows" :key="r.employee.id"><td><RouterLink :to="`/employees/${r.employee.id}`"><b>{{ r.employee.name }}</b></RouterLink><span class="tiny muted">{{ r.employee.emp_no }}</span></td><td>{{ r.company }}</td><td class="num">{{ r.version }}</td>
        <td><span class="pill" :class="r.signed ? 'ok' : 'warn'">{{ r.signed ? 'أقرّ' : 'لم يقرّ' }}</span><span v-if="!r.has_account" class="tiny muted"> (لا حساب)</span></td><td class="num small">{{ r.signed_at ? fmtDT(r.signed_at) : '—' }}</td><td class="tiny muted">{{ r.ip || '—' }}</td><td class="small">{{ r.previous.join('، ') || '—' }}</td></tr></tbody></table></div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import { promptBox } from '../dialog'
import { exportXlsx } from '../excel'
import Kpi from '../components/Kpi.vue'
import { useUi } from '../stores/ui'
import { fmtDT, norm, today } from '../utils'

const ui = useUi()
const rep = ref(null); const filter = ref(''); const q = ref('')
const rows = computed(() => (rep.value?.rows || []).filter((r) => (!filter.value || (filter.value === 'yes') === r.signed) && (!q.value || norm(r.employee.name + ' ' + r.employee.emp_no).includes(norm(q.value)))))
async function load() { rep.value = await api.get('/policy-ack/report') }
async function bump() {
  const v = await promptBox(`الإصدار الجديد (الحالي ${rep.value.version}):`, { multiline: false, placeholder: '2.2' }); if (!v) return
  const reason = await promptBox('وصف التعديل الجوهري (يصل للموظفين):'); if (!reason) return
  try { rep.value = await api.post('/policy-ack/version', { version: v, reason }); ui.toast('صدر الإصدار الجديد — سيُطلب الإقرار من الجميع', 'ok') } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) }
}
function exportX() { exportXlsx(`policy-acks-${today()}`, [['الموظف', 'الرقم', 'الشركة', 'الإصدار', 'الحالة', 'تاريخ الإقرار'], ...rows.value.map((r) => [r.employee.name, r.employee.emp_no, r.company, r.version, r.signed ? 'أقرّ' : 'لم يقرّ', r.signed_at ? fmtDT(r.signed_at) : ''])], { title: 'إقرارات اللائحة' }) }
onMounted(load)
</script>
