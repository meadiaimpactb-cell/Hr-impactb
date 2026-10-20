<template>
  <div class="page-h">
    <div><h1>سجل الغرامات</h1><p>الغرامات النافذة مقيدة في سجل مستقل بحسب الشركة والفترة، وتُصرف فيما يعود بالنفع على عمال المنشأة وفق المادة (73) من نظام العمل، ولا تُعامل إيرادًا تشغيليًا (§106)</p></div>
    <div class="acts"><button v-if="res?.rows?.length" class="btn ghost sm" @click="exportX"><Icon name="download" :size="16" /> Excel</button></div>
  </div>
  <div class="card">
    <div class="row mb">
      <div class="fg" style="margin:0"><label>من شهر</label><input v-model="from" type="month" class="in" style="width:auto" /></div>
      <div class="fg" style="margin:0"><label>إلى شهر</label><input v-model="to" type="month" class="in" style="width:auto" /></div>
      <div class="fg" style="margin:0"><label>الشركة</label><select v-model="company" class="in" style="width:auto"><option :value="null">كل الشركات</option><option v-for="c in auth.meta?.companies || []" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <button class="btn pri sm" :class="{ loading: busy }" style="align-self:flex-end" @click="load"><Icon name="refresh" :size="14" /> عرض</button>
    </div>
    <template v-if="res">
      <div class="grid g3 mb"><Kpi label="عدد الغرامات" :value="res.count" color="b" /><Kpi label="إجمالي الغرامات" :value="money(res.total)" color="y" /><Kpi label="الشركات" :value="res.by_company.length" color="p" /></div>
      <div class="grid g2 mb">
        <div class="card" style="margin:0"><h3>حسب الشركة</h3><div v-dt class="tw"><table><thead><tr><th>الشركة</th><th>العدد</th><th>الإجمالي</th></tr></thead><tbody><tr v-for="c in res.by_company" :key="c.company"><td>{{ c.company }}</td><td class="num">{{ c.count }}</td><td class="num"><b>{{ money(c.total) }}</b></td></tr></tbody></table></div></div>
        <div class="card" style="margin:0"><h3>حسب الفترة</h3><div v-dt class="tw"><table><thead><tr><th>الشهر</th><th>العدد</th><th>الإجمالي</th></tr></thead><tbody><tr v-for="p in res.by_period" :key="p.period"><td>{{ fmtYM(p.period) }}</td><td class="num">{{ p.count }}</td><td class="num"><b>{{ money(p.total) }}</b></td></tr></tbody></table></div></div>
      </div>
      <div v-if="!res.rows.length" class="empty">لا غرامات نافذة في الفترة</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>#</th><th>الموظف</th><th>الشركة</th><th>المخالفة</th><th>تاريخ الواقعة</th><th>شهر الحسم</th><th>الأجر الأساسي اليومي</th><th>الغرامة</th></tr></thead>
        <tbody><tr v-for="r in res.rows" :key="r.id"><td class="num">{{ r.id }}</td><td><b>{{ r.employee?.name }}</b><span class="tiny muted">{{ r.employee?.emp_no }}</span></td><td>{{ r.company }}</td><td class="small">{{ r.type_name }}</td><td class="num">{{ fmtD(r.occurred_on) }}</td><td>{{ fmtYM(r.period) }}</td><td class="num">{{ money(r.basis) }}</td><td class="num"><b>{{ money(r.amount) }}</b></td></tr></tbody>
        <tfoot><tr><th colspan="7">الإجمالي</th><th class="num">{{ money(res.total) }}</th></tr></tfoot></table></div>
      <p class="tiny muted mt">{{ res.note }}</p>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import api from '../api'
import { exportXlsx } from '../excel'
import Kpi from '../components/Kpi.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { curYM, fmtD, fmtYM, money, today } from '../utils'

const auth = useAuth(); const ui = useUi()
const from = ref(`${new Date().getFullYear()}-01`); const to = ref(curYM()); const company = ref(ui.company || null); const res = ref(null); const busy = ref(false)
async function load() { busy.value = true; try { res.value = await api.get('/violation-fines', { params: { from: from.value, to: to.value, company_id: company.value || undefined } }) } catch (e) { ui.toast(e.message, 'bad') } finally { busy.value = false } }
function exportX() {
  const rows = res.value.rows.map((r) => [r.id, r.employee?.name, r.company, r.type_name, fmtD(r.occurred_on), r.period, r.basis, r.amount])
  exportXlsx(`fines-${today()}`, [['#', 'الموظف', 'الشركة', 'المخالفة', 'تاريخ الواقعة', 'شهر الحسم', 'الأجر الأساسي اليومي', 'الغرامة'], ...rows, ['', '', '', '', '', '', 'الإجمالي', res.value.total]], { title: 'سجل الغرامات (§106)', period: `${from.value} — ${to.value}` })
  api.post('/reports-center/fines/exported', { kind: 'excel', from: from.value, to: to.value, company_id: company.value || null }).catch(() => {})
}
watch(() => ui.company, (v) => { company.value = v || null; load() })
onMounted(load)
</script>
