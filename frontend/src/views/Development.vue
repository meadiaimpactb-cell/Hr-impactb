<template>
  <div class="page-h">
    <div><h1>{{ t('التطوير والتدريب') }}</h1><p>سجلات التطوير الذاتية والمُسندة، تحقق الموارد البشرية، وتُحتسب في محور التطوير من التقييم · الميزانية وعدد البرامج من باقة الشركة</p></div>
    <div class="acts"><button class="btn pri sm" @click="openForm()"><Icon name="plus" :size="16" /> سجل تطوير</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <template v-if="tab === 'mine'">
    <div v-if="mine" class="grid g4 mb">
      <Kpi label="ميزانية التطوير السنوية" :value="money(mine.budget || 0, 0)" :hint="`المصروف ${money(mine.spent, 0)} ر.س`" :color="mine.budget && mine.spent > mine.budget ? 'r' : 'g'" />
      <Kpi label="البرامج الإلزامية شهريًا" :value="mine.programs_per_month ?? '—'" color="b" />
      <Kpi label="موثَّق" :value="mine.records.filter((r) => r.status === 'verified').length" color="g" />
      <Kpi label="ساعات موثقة" :value="mine.records.filter((r) => r.status === 'verified').reduce((s, r) => s + Number(r.hours || 0), 0)" color="p" />
    </div>
    <div class="card">
      <div v-if="!mine?.records.length" class="empty"><div class="ic"><Icon name="training" :size="40" :stroke="1.4" /></div>لا سجلات تطوير بعد</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>البرنامج</th><th>الجهة</th><th>النوع</th><th>الفترة</th><th>الساعات</th><th>التكلفة</th><th>المصدر</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="r in mine.records" :key="r.id">
          <td><b>{{ r.program }}</b><div v-if="r.notes" class="tiny muted">{{ r.notes }}</div></td><td>{{ r.provider }}</td><td>{{ kindName(r.kind) }}</td><td class="num small">{{ r.from || '—' }} → {{ r.to || '—' }}</td><td>{{ r.hours }}</td><td class="num">{{ money(r.cost, 0) }}</td>
          <td>{{ r.source === 'assigned' ? 'مُسند' : 'ذاتي' }}</td><td><span class="pill" :class="COLOR[r.status]">{{ r.status_label }}</span></td>
          <td><button v-if="r.status === 'planned'" class="btn okb xs" @click="complete(r)">أكملته</button></td>
        </tr></tbody></table></div>
    </div>
  </template>

  <template v-else>
    <div class="card">
      <div class="row mb"><select v-model="status" class="in" style="width:auto" @change="load"><option value="">كل الحالات</option><option value="pending">بانتظار التحقق</option><option value="planned">مخطط</option><option value="verified">موثَّق</option><option value="rejected">مرفوض</option></select></div>
      <div v-if="!all.length" class="empty">لا سجلات</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>البرنامج</th><th>النوع</th><th>الفترة</th><th>الساعات</th><th>التكلفة</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="r in all" :key="r.id">
          <td><b>{{ r.employee?.name }}</b><span class="tiny muted">{{ r.company }}</span></td><td>{{ r.program }}<div class="tiny muted">{{ r.provider }}</div></td><td>{{ kindName(r.kind) }}</td><td class="num small">{{ r.from || '—' }} → {{ r.to || '—' }}</td><td>{{ r.hours }}</td><td class="num">{{ money(r.cost, 0) }}</td>
          <td><span class="pill" :class="COLOR[r.status]">{{ r.status_label }}</span></td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><template v-if="r.status === 'pending' && auth.isHr"><button class="btn okb xs" @click="verify(r, 'verified')">توثيق</button><button class="btn ghost xs bad" @click="verify(r, 'rejected')">رفض</button></template></td>
        </tr></tbody></table></div>
    </div>
  </template>

  <Modal v-if="f" :title="f.employee_id ? 'إسناد برنامج لموظف' : 'سجل تطوير'" @close="f = null">
    <div v-if="auth.isApprover" class="fg"><label>الموظف (اتركه فارغًا لتسجيل برنامج لنفسك)</label><SearchSelect v-model="f.employee_id" :options="empOptions" /></div>
    <div class="fr">
      <div class="fg" style="grid-column:span 2"><label>من الكتالوج</label><select class="in" @change="pickCatalog($event.target.value)"><option value="">— اختر برنامجًا أو اكتب يدويًا —</option><option v-for="c in auth.meta?.lookups?.dev_catalog || []" :key="c.code" :value="c.code">{{ c.name }} — {{ c.meta?.provider }} ({{ c.meta?.hours }} س)</option></select></div>
      <div class="fg" style="grid-column:span 2"><label>البرنامج *</label><input v-model="f.program" class="in" /></div>
      <div class="fg"><label>الجهة</label><input v-model="f.provider" class="in" /></div>
      <div class="fg"><label>النوع</label><select v-model="f.kind" class="in"><option v-for="k in auth.meta?.lookups?.dev_kinds || []" :key="k.code" :value="k.code">{{ k.name }}</option></select></div>
      <div class="fg"><label>من</label><DateInput v-model="f.from" /></div><div class="fg"><label>إلى</label><DateInput v-model="f.to" :min="f.from" /></div>
      <div class="fg"><label>الساعات</label><input v-model.number="f.hours" type="number" min="0" class="in" /></div><div class="fg"><label>التكلفة (ر.س)</label><input v-model.number="f.cost" type="number" min="0" class="in" /></div>
    </div>
    <div class="fg"><label>ملاحظات</label><textarea v-model="f.notes" class="in" rows="2" /></div>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">حفظ</button></template>
  </Modal>
</template>

<script setup>
import { promptBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { money } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const COLOR = { planned: 'info', pending: 'warn', verified: 'ok', rejected: 'bad' }
const tabs = computed(() => [auth.emp && ['mine', 'سجلي'], auth.isApprover && ['all', 'سجلات الفريق']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'all')
const mine = ref(null); const all = ref([]); const status = ref(''); const f = ref(null); const busy = ref(false); const emps = ref([])
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const kindName = (k) => (auth.meta?.lookups?.dev_kinds || []).find((x) => x.code === k)?.name || k || '—'
async function load() {
  if (tab.value === 'mine') mine.value = await api.get('/development/mine')
  else all.value = await api.get('/development', { params: { status: status.value || undefined } })
}
function pickCatalog(code) { const c = (auth.meta?.lookups?.dev_catalog || []).find((x) => x.code === code); if (!c) return; f.value.program = c.name; f.value.provider = c.meta?.provider || ''; f.value.hours = Number(c.meta?.hours || 0); f.value.cost = Number(c.meta?.cost || 0) }
async function openForm() { f.value = { employee_id: null, program: '', provider: '', kind: (auth.meta?.lookups?.dev_kinds || [])[0]?.code, hours: 0, cost: 0 }; if (auth.isApprover && !emps.value.length) emps.value = await api.get('/employees/options') }
async function save() {
  if (!f.value.program?.trim()) return ui.toast('اسم البرنامج إلزامي', 'warn')
  busy.value = true
  try { await api.post('/development', f.value); ui.toast('تم الحفظ', 'ok'); f.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function complete(r) { await api.post(`/development/${r.id}/complete`); ui.toast('أُرسل للتحقق', 'ok'); load() }
async function verify(r, decision) { const note = decision === 'rejected' ? await promptBox('سبب الرفض:') : null; if (decision === 'rejected' && !note) return; await api.post(`/development/${r.id}/verify`, { decision, note }); ui.toast('تم', 'ok'); load() }
onMounted(load)
</script>
