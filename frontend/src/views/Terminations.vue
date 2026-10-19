<template>
  <div class="page-h">
    <div><h1>{{ t('إنهاء الخدمة والتصفية') }}</h1><p>حالة إنهاء ← إخلاء طرف بمهام لكل جهة ← مكافأة نهاية الخدمة تُحسب وفق النظام (وتُعدَّل بسبب) ← تصفية نهائية باعتماد المدير ثم الموارد البشرية ثم الرئيس ثم المالية ← الصرف وإرسال القيد إلى أودو</p></div>
    <div class="acts">
      <button v-if="auth.isApprover" class="btn sec sm" @click="simOpen = true"><Icon name="chart" :size="16" /> محاكاة المكافأة</button>
      <button v-if="auth.isHr" class="btn pri sm" @click="openNew"><Icon name="plus" :size="16" /> حالة إنهاء</button>
    </div>
  </div>
  <div class="grid g4 mb">
    <Kpi label="قائمة" :value="rows.filter((r) => r.status === 'open').length" color="y" /><Kpi label="بانتظار الصرف" :value="rows.filter((r) => r.status === 'settled').length" color="b" />
    <Kpi label="مصروفة" :value="rows.filter((r) => r.status === 'paid').length" color="g" /><Kpi label="إجمالي التصفيات القائمة" :value="money(rows.filter((r) => r.status !== 'cancelled' && r.status !== 'paid').reduce((s, r) => s + Number(r.total), 0), 0)" color="p" />
  </div>
  <div class="card">
    <div v-if="!rows.length" class="empty"><div class="ic"><Icon name="exit" :size="40" :stroke="1.4" /></div>لا حالات إنهاء خدمة</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>السبب</th><th>آخر يوم عمل</th><th>إخلاء الطرف</th><th>المكافأة</th><th>صافي التصفية</th><th>الاعتماد</th><th>الحالة</th><th /></tr></thead>
      <tbody><tr v-for="r in rows" :key="r.id">
        <td><b>{{ r.employee?.name }}</b><span class="tiny muted">{{ r.company }}</span></td><td>{{ r.reason_name }}</td><td class="num">{{ r.last_working_day }}</td>
        <td><span class="pill" :class="r.clearance_done === r.clearance_total ? 'ok' : 'warn'">{{ r.clearance_done }}/{{ r.clearance_total }}</span></td>
        <td class="num">{{ money(r.eosb_amount) }}</td><td class="num"><b>{{ money(r.total) }}</b></td><td>{{ r.stage }}/4</td>
        <td><span class="pill" :class="{ open: 'warn', settled: 'info', paid: 'ok', cancelled: 'gray' }[r.status]">{{ r.status_label }}</span></td>
        <td><button class="btn ghost xs" @click="open(r.id)">فتح</button></td>
      </tr></tbody></table></div>
  </div>

  <Modal v-if="d" :title="`إنهاء خدمة — ${d.employee?.name}`" size="wide" @close="d = null">
    <div class="row mb"><span class="pill" :class="{ open: 'warn', settled: 'info', paid: 'ok', cancelled: 'gray' }[d.status]">{{ d.status_label }}</span><span class="small muted">{{ d.reason_name }} · آخر يوم عمل {{ d.last_working_day }} · مدة الإشعار {{ d.notice_days }} يوم</span><span v-if="d.note" class="tiny muted">{{ d.note }}</span></div>
    <div class="steps mb">
      <template v-for="(s, i) in STAGES" :key="s[0]"><span class="step" :class="i < d.stage ? 'done' : i === d.stage && d.status === 'open' ? 'cur' : ''"><i><Icon v-if="i < d.stage" name="check" :size="13" :stroke="3" /><template v-else>{{ i + 1 }}</template></i>{{ s[1] }}</span><Icon v-if="i < 3" name="chevron-left" :size="16" class="muted" flip-rtl /></template>
    </div>
    <div class="grid g2">
      <div class="card" style="margin:0"><h3>إخلاء الطرف</h3>
        <div v-for="tsk in d.clearance" :key="tsk.key" class="row mb" style="justify-content:space-between">
          <div><Icon :name="tsk.done ? 'check-circle' : 'hourglass'" :size="15" :class="tsk.done ? 'ok' : 'muted'" /> {{ tsk.label }} <span class="tiny muted">({{ OWNER[tsk.owner] || tsk.owner }})</span><div v-if="tsk.done" class="tiny muted">{{ tsk.evidence }} — {{ tsk.by }} · {{ fmtDT(tsk.at) }}</div></div>
          <button v-if="!tsk.done && d.status === 'open'" class="btn sec xs" @click="doTask(tsk)">إتمام</button>
        </div>
      </div>
      <div class="card" style="margin:0"><h3>مكافأة نهاية الخدمة</h3>
        <div class="small">مدة الخدمة: <b>{{ d.eosb.pretty }}</b> ({{ d.eosb.years }} سنة) · الأجر المعتبر <b class="num">{{ money(d.eosb.wage) }}</b></div>
        <div class="small">نصف أجر لأول {{ d.eosb.half_years }} سنوات: <b class="num">{{ money(d.eosb.t1) }}</b> · أجر كامل لما بعدها: <b class="num">{{ money(d.eosb.t2) }}</b></div>
        <div class="small">الإجمالي <b class="num">{{ money(d.eosb.gross) }}</b> × معامل {{ d.eosb.factor }} = <b class="num">{{ money(d.eosb.amount) }}</b> <span class="tiny muted">({{ d.eosb.why }})</span></div>
        <div v-if="d.eosb_adjusted !== null" class="notice warn tiny">مُعدَّلة إلى <b class="num">{{ money(d.eosb_adjusted) }}</b> — {{ d.eosb_adjust_reason }}</div>
        <div v-if="d.can_manage && d.status === 'open'" class="row mt"><input v-model.number="adj.amount" type="number" class="in" placeholder="مبلغ معدّل" style="max-width:140px" /><input v-model="adj.reason" class="in" placeholder="السبب *" /><button class="btn ghost xs" @click="adjust">تعديل</button></div>
        <div v-if="d.settlement_due" class="notice info small">موعد تسوية المستحقات: <b>{{ d.settlement_due }}</b> — {{ d.settlement_due_rule }} · لا يُعلَّق صرف المستحقات غير المتنازع عليها على اكتمال إخلاء الطرف (§111)</div>
        <h3 style="margin-top:14px">التصفية النهائية <span class="sub">v{{ d.settlement?.version }}</span><span v-if="d.can_manage && d.status === 'open' && d.stage < 4" class="acts"><button class="btn ghost xs" @click="rebuild">إعادة بناء البنود</button></span></h3>
        <div v-dt class="tw"><table><tbody><tr v-for="(l, i) in d.settlement?.lines || []" :key="i"><td>{{ l.name }}</td><td class="num" :class="l.amount < 0 ? 'bad' : ''">{{ money(l.amount) }}</td></tr><tr><th>الصافي</th><th class="num">{{ money(d.settlement?.total) }}</th></tr></tbody></table></div>
      </div>
    </div>
    <div v-if="d.approvals?.length" class="mt"><b class="small">الاعتمادات:</b> <span v-for="a in d.approvals" :key="a.role" class="pill ok">{{ a.label }} — {{ a.by }}</span></div>
    <div v-if="d.can_approve" class="fg mt"><label>ملاحظة الاعتماد (اختياري)</label><input v-model="note" class="in" /></div>
    <template #foot>
      <button class="btn sec" @click="d = null">إغلاق</button>
      <RouterLink class="btn ghost" :to="`/print/settlement/${d.id}`"><Icon name="external" :size="14" /> مستند التصفية</RouterLink>
      <button v-if="d.status === 'open' && auth.has('super', 'hr_mgr')" class="btn ghost bad" @click="cancel">إلغاء الحالة</button>
      <button v-if="d.can_approve" class="btn okb" :class="{ loading: busy }" @click="approve">اعتماد — {{ d.next_stage }}</button>
      <template v-if="d.can_pay"><button v-if="d.payment === 'none'" class="btn sec" @click="pay('sent')">إرسال إلى أودو</button><button class="btn pri" @click="pay('paid')">تأكيد الصرف</button></template>
    </template>
  </Modal>

  <Modal v-if="nf" title="فتح حالة إنهاء خدمة" @close="nf = null">
    <div class="fg"><label>الموظف *</label><SearchSelect v-model="nf.employee_id" :options="empOptions" /></div>
    <div class="fr"><div class="fg"><label>السبب *</label><select v-model="nf.reason" class="in"><option v-for="r in reasons" :key="r.code" :value="r.code">{{ r.name }}</option></select></div><div class="fg"><label>آخر يوم عمل *</label><DateInput v-model="nf.last_working_day" /></div></div>
    <div class="fg"><label>ملاحظة *</label><input v-model="nf.note" class="in" /></div>
    <p class="tiny muted">الاستقالة تُفتح تلقائيًا من طلب الاستقالة بعد اعتماده، والفصل لمخالفة جسيمة من قرار المخالفة.</p>
    <template #foot><button class="btn sec" @click="nf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="createCase">فتح الحالة</button></template>
  </Modal>

  <Modal v-if="simOpen" title="محاكاة مكافأة نهاية الخدمة" @close="simOpen = false">
    <div class="fg"><label>الموظف</label><SearchSelect v-model="sim.employee_id" :options="empOptions" /></div>
    <div class="fr"><div class="fg"><label>تاريخ الانتهاء</label><DateInput v-model="sim.end" /></div><div class="fg"><label>السبب</label><select v-model="sim.reason" class="in"><option v-for="r in reasons" :key="r.code" :value="r.code">{{ r.name }}</option></select></div></div>
    <button class="btn sec sm" @click="runSim">احسب</button>
    <div v-if="simRes" class="notice info mt">{{ simRes.pretty }} · الأجر المعتبر {{ money(simRes.wage) }} · الإجمالي {{ money(simRes.gross) }} × {{ simRes.factor }} = <b class="num">{{ money(simRes.amount) }}</b><div class="tiny">{{ simRes.why }}</div></div>
    <template #foot><button class="btn sec" @click="simOpen = false">إغلاق</button></template>
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
import { fmtDT, money, today } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const STAGES = [['mgr', 'المدير المباشر'], ['hr', 'الموارد البشرية'], ['ceo', 'رئيس الشركة'], ['fin', 'المدير المالي']]
const OWNER = { it: 'التقنية/العهد', fin: 'المالية', hr: 'الموارد البشرية', mgr: 'المدير المباشر' }
const rows = ref([]); const d = ref(null); const nf = ref(null); const emps = ref([]); const busy = ref(false); const note = ref(''); const adj = ref({ amount: null, reason: '' })
const simOpen = ref(false); const sim = ref({ employee_id: null, end: today(), reason: 'resign' }); const simRes = ref(null)
const reasons = computed(() => auth.meta?.lookups?.termination_reasons || [])
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
async function load() { rows.value = await api.get('/terminations'); if (!emps.value.length && auth.isApprover) emps.value = await api.get('/employees/options') }
async function open(id) { d.value = await api.get(`/terminations/${id}`); note.value = ''; adj.value = { amount: null, reason: '' } }
function openNew() { nf.value = { employee_id: null, reason: reasons.value.find((r) => r.code !== 'resign')?.code, last_working_day: today(), note: '' } }
async function createCase() {
  if (!nf.value.employee_id || !nf.value.note?.trim()) return ui.toast('الموظف والملاحظة إلزاميان', 'warn')
  busy.value = true
  try { const r = await api.post('/terminations', nf.value); ui.toast('فُتحت الحالة', 'ok'); nf.value = null; load(); open(r.id) } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function doTask(tsk) { const evidence = await promptBox(`إثبات إتمام «${tsk.label}»:`); if (!evidence) return; try { await api.post(`/terminations/${d.value.id}/task`, { key: tsk.key, evidence }); open(d.value.id); load() } catch (e) { ui.toast(e.message, 'bad') } }
async function adjust() { if (!adj.value.reason?.trim()) return ui.toast('السبب إلزامي', 'warn'); d.value = await api.post(`/terminations/${d.value.id}/eosb`, adj.value); ui.toast('عُدّلت المكافأة وأُعيد بناء التصفية', 'ok'); load() }
async function rebuild() { d.value = await api.post(`/terminations/${d.value.id}/rebuild`); ui.toast('أُعيد بناء البنود — تبدأ الاعتمادات من جديد', 'ok'); load() }
async function approve() { busy.value = true; try { d.value = await api.post(`/terminations/${d.value.id}/approve`, { note: note.value || null }); ui.toast('تم الاعتماد', 'ok'); load() } catch (e) { ui.toast(e.message, 'bad', 6000) } finally { busy.value = false } }
async function pay(action) { const ref_ = action === 'paid' ? await promptBox('مرجع الصرف / الحوالة', { multiline: false, required: false }) : null; d.value = await api.post(`/terminations/${d.value.id}/pay`, { action, ref: ref_ }); ui.toast(action === 'paid' ? 'تم تأكيد الصرف' : 'أُرسل القيد إلى صادر أودو', 'ok'); load() }
async function cancel() { const n = await promptBox('سبب الإلغاء:'); if (!n) return; await api.post(`/terminations/${d.value.id}/cancel`, { note: n }); d.value = null; ui.toast('أُلغيت الحالة وعاد الموظف نشطًا'); load() }
async function runSim() { if (!sim.value.employee_id) return; simRes.value = await api.get(`/terminations/simulate/${sim.value.employee_id}`, { params: { end: sim.value.end, reason: sim.value.reason } }) }
onMounted(load)
</script>
