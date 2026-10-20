<template>
  <div class="page-h">
    <div><h1>الرواتب والقسائم</h1><p>تجميع آلي ← مراجعة أخصائي الموارد ← اعتماد مدير الموارد ← اعتماد المدير المالي ← اعتماد الرئيس التنفيذي للمجموعة ← صرف بنكي على حساب الموظف الموثّق · لا يجمع شخص واحد مرحلتين · تُنشر القسيمة للموظف بعد الاعتماد ويُبلَّغ المدير المباشر للعلم</p></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <!-- mine: my payslips + my advances -->
  <template v-if="tab === 'mine'">
    <div class="card">
      <h3>قسائمي <span class="sub">تظهر بعد مراجعة المسير واعتماده</span></h3>
      <div v-if="!mySlips.length" class="empty"><div class="ic"><Icon name="money" :size="40" :stroke="1.4" /></div>لا قسائم منشورة بعد</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>الشهر</th><th>الإجمالي</th><th>الاستقطاعات</th><th>الصافي</th><th /></tr></thead>
        <tbody><tr v-for="s in mySlips" :key="s.id">
          <td>{{ fmtYM(s.period) }}</td><td class="num">{{ money(s.gross) }}</td><td class="num">{{ money(s.ded_total) }}</td>
          <td class="num"><b>{{ money(s.net) }}</b></td>
          <td><button class="btn ghost xs" @click="openSlip = s.id">عرض / طباعة</button></td>
        </tr></tbody>
      </table></div>
    </div>
    <div class="card">
      <h3>سلَفي</h3>
      <div v-if="!myAdv.length" class="empty">لا سلف قائمة</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>المبلغ</th><th>الغرض</th><th>الأقساط</th><th>المتبقي</th><th>التقدم</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="a in myAdv" :key="a.id">
          <td class="num">{{ money(a.amount) }}</td><td>{{ a.purpose || '—' }}</td><td>{{ a.paid }}/{{ a.installments }}</td>
          <td class="num">{{ money(a.remaining) }}</td>
          <td style="min-width:100px"><div class="bar g"><i :style="{ width: (a.paid / a.installments) * 100 + '%' }" /></div></td>
          <td><span class="pill" :class="a.status === 'settled' ? 'ok' : 'info'">{{ a.status === 'settled' ? 'مسددة' : 'قيد السداد' }}</span></td>
          <td><button class="btn ghost xs" @click="openAdv = a.id">الجدول</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <!-- runs: per-company payroll generation & staged approval -->
  <template v-else-if="tab === 'runs'">
    <div class="row mb"><input v-model="period" type="month" class="in" style="width:auto" @change="load" /></div>
    <div v-if="!runs.length" class="card">
      <div v-if="companiesLoaded" class="empty"><div class="ic"><Icon name="building" :size="40" :stroke="1.4" /></div>لا شركات ضمن نطاق صلاحيتك</div>
      <div v-else><div class="skel" v-for="i in 3" :key="i" /></div>
    </div>
    <div v-for="r in runs" :key="r.company.id" class="card">
      <h3>{{ r.company.name }} <span class="sub">{{ fmtYM(r.period) }} · {{ r.employees_count }} موظف</span>
        <span class="acts"><span class="pill" :class="statusColor(r.status)">{{ r.status_label }}</span><span v-if="r.version" class="tiny muted">v{{ r.version }}</span></span>
      </h3>
      <div class="grid g3 mb">
        <Kpi label="إجمالي المستحقات" color="n"><span class="num">{{ money(r.gross) }}</span></Kpi>
        <Kpi label="صافي المسير" color="g"><span class="num">{{ money(r.net) }}</span></Kpi>
        <Kpi label="الحالة" :value="r.status_label" :hint="r.updated_at ? fmtDT(r.updated_at) : ''" color="b" />
      </div>
      <div class="row">
        <button v-if="r.can_generate" class="btn pri sm" @click="generate(r.company.id)"><Icon name="workflow" :size="15" /> تجميع مسودة المسير</button>
        <button v-if="r.run_id" class="btn sec sm" @click="openRun = { id: r.run_id, company: r.company, period: r.period }">فتح المسير</button>
      </div>
    </div>
  </template>

  <!-- slips: browse & approve all payslips for a period -->
  <template v-else-if="tab === 'slips'">
    <div class="row mb"><input v-model="period" type="month" class="in" style="width:auto" @change="load" /></div>
    <div class="card">
      <h3>قسائم {{ fmtYM(period) }} <span class="sub">{{ slips.filter((s) => s.published).length }} منشورة من {{ slips.length }}</span></h3>
      <div v-if="!slips.length" class="empty">لا قسائم — تُولَّد بعد اعتماد المسير نهائيًا</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>الموظف</th><th>الصافي</th><th>مراجعة الموارد</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="s in slips" :key="s.id">
          <td>{{ s.employee.name }}<span class="tiny muted">{{ s.employee.emp_no }}</span></td>
          <td class="num">{{ money(s.net) }}</td>
          <td><span class="pill" :class="s.hr_approved ? 'ok' : 'warn'">{{ s.hr_approved ? 'معتمد' : 'بانتظار' }}</span></td>
          <td><span class="pill" :class="s.published ? 'ok' : 'gray'">{{ s.published ? 'منشورة' : 'مسودة' }}</span></td>
          <td><button class="btn ghost xs" @click="openSlip = s.id">فتح</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <!-- advances: overview across scope -->
  <template v-else-if="tab === 'advances'">
    <div class="card">
      <h3>السلف <span class="sub">{{ advances.filter((a) => a.status === 'repaying').length }} قيد السداد</span></h3>
      <div v-if="!advances.length" class="empty">لا سلف</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>الموظف</th><th>المبلغ</th><th>الأقساط</th><th>المتبقي</th><th>التقدم</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="a in advances" :key="a.id">
          <td>{{ a.employee.name }}<span class="tiny muted">{{ a.employee.emp_no }}</span></td>
          <td class="num">{{ money(a.amount) }}</td><td>{{ a.paid }}/{{ a.installments }}</td>
          <td class="num">{{ money(a.remaining) }}</td>
          <td style="min-width:100px"><div class="bar g"><i :style="{ width: (a.paid / a.installments) * 100 + '%' }" /></div></td>
          <td><span class="pill" :class="a.status === 'settled' ? 'ok' : 'info'">{{ a.status === 'settled' ? 'مسددة' : 'قيد السداد' }}</span></td>
          <td><button class="btn ghost xs" @click="openAdv = a.id">الجدول</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <PayslipModal v-if="openSlip" :id="openSlip" @close="openSlip = null" @changed="load" />
  <AdvanceModal v-if="openAdv" :id="openAdv" @close="openAdv = null" />
  <PayrollRunModal v-if="openRun" :run-id="openRun.id" :company="openRun.company" :period="openRun.period" @close="openRun = null" @changed="load" />
</template>

<script setup>
import Kpi from '../components/Kpi.vue'
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import AdvanceModal from '../components/AdvanceModal.vue'
import PayrollRunModal from '../components/PayrollRunModal.vue'
import PayslipModal from '../components/PayslipModal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { curYM, fmtDT, fmtYM, money } from '../utils'

const auth = useAuth()
const ui = useUi()
const period = ref(curYM())
const runs = ref([])
const slips = ref([])
const advances = ref([])
const mySlips = ref([])
const myAdv = ref([])
const companiesLoaded = ref(false)
const canManage = ref(false)
const openSlip = ref(null)
const openAdv = ref(null)
const openRun = ref(null)

const tabs = computed(() => [auth.emp && ['mine', 'قسائمي وسلَفي'], canManage.value && ['runs', 'مسير الرواتب'], canManage.value && ['slips', 'القسائم'], canManage.value && ['advances', 'السلف']].filter(Boolean))
const tab = ref('')

const statusColor = (s) => ({ draft: 'gray', hr_review: 'warn', hr_approved: 'info', fin_approved: 'info', approved: 'ok' }[s] || 'gray')

async function checkScope() {
  const companies = await api.get('/payroll/companies')
  canManage.value = companies.length > 0
  companiesLoaded.value = true
  tab.value = auth.emp ? 'mine' : (canManage.value ? 'runs' : '')
}

async function load() {
  if (tab.value === 'mine') {
    ;[mySlips.value, myAdv.value] = await Promise.all([api.get('/payroll/payslips/mine'), api.get('/payroll/advances/mine')])
  } else if (tab.value === 'runs') {
    runs.value = await api.get('/payroll/runs', { params: { period: period.value } })
  } else if (tab.value === 'slips') {
    slips.value = await api.get('/payroll/payslips', { params: { period: period.value } })
  } else if (tab.value === 'advances') {
    advances.value = await api.get('/payroll/advances')
  }
}

async function generate(companyId) {
  try {
    await api.post('/payroll/runs/generate', { company_id: companyId, period: period.value })
    ui.toast('جُمّعت مسودة المسير', 'ok')
    load()
  } catch { /* toast shown by api */ }
}

onMounted(async () => { await checkScope(); await load() })
</script>
