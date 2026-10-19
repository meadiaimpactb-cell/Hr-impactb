<template>
  <div class="page-h">
    <div><h1>الحوافز والإشادات</h1><p>سجل الحوافز والإشادات بجانب سجل المخالفات (اللائحة §88): الإشادات وخطابات الشكر وشهادات التقدير والمكافآت الفورية والتكريمات · نقاط التميّز لا تدخل في درجة التقييم وتُعتمد في اختيار موظف الربع ومتميزي المجموعة</p></div>
    <div class="acts"><button v-if="auth.isApprover" class="btn pri sm" @click="openForm()"><Icon name="award" :size="16" /> تسجيل إشادة أو تقدير</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <template v-if="tab === 'mine'">
    <div v-if="mine" class="grid g4 mb">
      <Kpi :label="`نقاط التميّز — ${fmtYM(mine.excellence.period)}`" :value="mine.excellence.total" color="g" />
      <Kpi label="إشادات وتقديرات في سجلي" :value="mine.records.length" color="b" />
      <Kpi label="مكافآت فورية" :value="money(mine.records.filter((r) => r.kind === 'bonus').reduce((s, r) => s + Number(r.amount || 0), 0), 0)" color="p" />
      <Kpi label="نقاط موثقة" :value="mine.records.reduce((s, r) => s + Number(r.points || 0), 0)" color="y" />
    </div>
    <div v-if="mine" class="card">
      <h3>كيف تُحتسب نقاط التميّز هذا الشهر</h3>
      <div v-if="!mine.excellence.items.length" class="small muted">لا نقاط بعد — تُكتسب برفع التقارير في موعدها، والانضباط الكامل، وتحقيق مستهدف التطوير، وإشادات الزملاء والإدارة.</div>
      <div v-else class="row" style="gap:6px;flex-wrap:wrap"><span v-for="(it, i) in mine.excellence.items" :key="i" class="pill ok">{{ it.label }} <b>+{{ it.points }}</b></span></div>
      <div class="tiny muted mt">رفع التقرير في موعده 10 · انضباط كامل في الحضور 15 · مستهدف التطوير 3 أشهر متتالية 30 · إشادة زميل 5 · إشادة موثقة 20</div>
    </div>
    <div class="card">
      <div v-if="!mine?.records.length" class="empty"><div class="ic"><Icon name="award" :size="40" :stroke="1.4" /></div>لا إشادات بعد</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>النوع</th><th>العنوان</th><th>التفاصيل</th><th>الشهر</th><th>النقاط</th><th>المبلغ</th><th>بواسطة</th></tr></thead>
        <tbody><tr v-for="r in mine.records" :key="r.id"><td><span class="pill" :class="KC[r.kind] || 'info'">{{ r.kind_label }}</span></td><td><b>{{ r.title }}</b></td><td class="small">{{ r.detail || '—' }}</td><td class="num">{{ r.period }}</td><td class="num">{{ r.points || '—' }}</td><td class="num">{{ r.amount ? money(r.amount) : '—' }}</td><td class="small">{{ r.by || '—' }}</td></tr></tbody></table></div>
    </div>
  </template>

  <template v-else-if="tab === 'all'">
    <div class="card">
      <div class="row mb"><input v-model="period" type="month" class="in" style="width:auto" @change="load" /><span class="tiny muted">فارغ = كل الفترات</span></div>
      <div v-if="!all.length" class="empty">لا سجلات</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>النوع</th><th>العنوان</th><th>الشهر</th><th>النقاط</th><th>المبلغ</th><th>بواسطة</th><th /></tr></thead>
        <tbody><tr v-for="r in all" :key="r.id"><td><b>{{ r.employee?.name }}</b><span class="tiny muted">{{ r.company }}</span></td><td><span class="pill" :class="KC[r.kind] || 'info'">{{ r.kind_label }}</span></td><td>{{ r.title }}<div class="tiny muted">{{ r.detail }}</div></td><td class="num">{{ r.period }}</td><td class="num">{{ r.points || '—' }}</td><td class="num">{{ r.amount ? money(r.amount) : '—' }}</td><td class="small">{{ r.by }}</td>
          <td><button v-if="auth.isHr && !r.request_id" class="btn ghost xs bad" title="حذف" @click="remove(r)"><Icon name="trash" :size="14" /></button></td></tr></tbody></table></div>
    </div>
  </template>

  <template v-else>
    <div class="card">
      <h3>لوحة المتميزين <span class="sub">ترتيب نقاط التميّز للشهر — مرجع اختيار موظف الربع ونجم التطوير</span><span class="acts"><input v-model="period" type="month" class="in" style="width:auto" @change="load" /></span></h3>
      <div v-if="!board.length" class="empty">لا بيانات</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>#</th><th>الموظف</th><th>الشركة</th><th>النقاط</th><th>التفاصيل</th></tr></thead>
        <tbody><tr v-for="(r, i) in board" :key="r.employee.id"><td class="num"><b>{{ i + 1 }}</b></td><td><b>{{ r.employee.name }}</b></td><td class="small">{{ r.company }}</td><td class="num"><b>{{ r.points.total }}</b></td><td class="small">{{ r.points.items.map((x) => `${x.label} +${x.points}`).join(' · ') || '—' }}</td></tr></tbody></table></div>
    </div>
  </template>

  <Modal v-if="f" title="تسجيل إشادة أو تقدير" @close="f = null">
    <div class="fg"><label>الموظف *</label><SearchSelect v-model="f.employee_id" :options="empOptions" /></div>
    <div class="fr">
      <div class="fg"><label>النوع *</label><select v-model="f.kind" class="in"><option v-for="(l, k) in kinds" :key="k" :value="k" :disabled="k === 'bonus'">{{ l }}{{ k === 'bonus' ? ' — عبر طلب «مكافأة إنجاز فورية»' : '' }}</option></select></div>
      <div class="fg"><label>الشهر</label><input v-model="f.period" type="month" class="in" /></div>
    </div>
    <div class="fg"><label>العنوان *</label><input v-model="f.title" class="in" placeholder="مثال: إشادة من شريك خارجي على جودة التقرير" /></div>
    <div class="fg"><label>التفاصيل</label><textarea v-model="f.detail" class="in" rows="3" /></div>
    <div class="fg"><label>نقاط التميّز <span class="tiny muted">(الإشادة الموثقة 20 افتراضيًا)</span></label><input v-model.number="f.points" type="number" min="0" max="100" class="in" /></div>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">تسجيل وإشعار الموظف</button></template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtYM, money } from '../utils'

const auth = useAuth(); const ui = useUi()
const KC = { praise: 'ok', thanks_letter: 'info', certificate: 'purple', bonus: 'warn', honor: 'navy', achievement: 'ok', star: 'warn' }
const tabs = computed(() => [auth.emp && ['mine', 'سجلي'], auth.isApprover && ['all', 'سجل الفريق'], ['board', 'لوحة المتميزين']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'board')
const mine = ref(null); const all = ref([]); const board = ref([]); const kinds = ref({}); const period = ref(new Date().toISOString().slice(0, 7))
const f = ref(null); const emps = ref([]); const busy = ref(false)
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
async function load() {
  if (tab.value === 'mine') { mine.value = await api.get('/incentives/mine', { params: { period: period.value } }); kinds.value = mine.value.kinds }
  else if (tab.value === 'all') all.value = await api.get('/incentives', { params: { period: period.value || undefined } })
  else board.value = (await api.get('/incentives/board', { params: { period: period.value, company_id: ui.company || undefined } })).rows
  if (!Object.keys(kinds.value).length) kinds.value = { praise: 'إشادة', thanks_letter: 'خطاب شكر', certificate: 'شهادة تقدير', bonus: 'مكافأة فورية', honor: 'تكريم', achievement: 'إنجاز نوعي', star: 'نجم التطوير' }
}
async function openForm() { if (!emps.value.length) emps.value = await api.get('/employees/options'); f.value = { employee_id: null, kind: 'praise', title: '', detail: '', points: 20, period: period.value } }
async function save() {
  if (!f.value.employee_id || !f.value.title?.trim()) return ui.toast('الموظف والعنوان إلزاميان', 'warn')
  busy.value = true
  try { await api.post('/incentives', f.value); ui.toast('سُجّلت الإشادة وأُشعر الموظف', 'ok'); f.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function remove(r) { if (!await confirmBox(`حذف «${r.title}»؟`)) return; await api.delete(`/incentives/${r.id}`); load() }
onMounted(load)
</script>
