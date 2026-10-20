<template>
  <div class="page-h">
    <div><h1>{{ t('إنهاء الخدمة والتصفية') }}</h1><p>الاستقالة تقيّدها الموارد البشرية بتاريخ تقديمها وتُقبل بالمسار أو نظامًا بمضي 30 يومًا (§108) · إنهاء صاحب العمل بقائمة فحص ثم اعتماد المشرف فالمالية فالرئيس التنفيذي للشركة فالرئيس التنفيذي للمجموعة (§109) · إخلاء طرف بمهام متوازية واعتماد متسلسل (§111) · تصفية يعتمدها المشرف ثم تعدّها الموارد البشرية ثم الرئيس التنفيذي للشركة ثم المالية وتصرفها، دون تعليقها على الإخلاء، خلال أسبوع أو أسبوعين (§112)</p></div>
    <div class="acts">
      <button v-if="auth.isApprover" class="btn sec sm" @click="simOpen = true"><Icon name="chart" :size="16" /> محاكاة المكافأة</button>
      <button v-if="auth.isHr" class="btn sec sm" @click="openResign"><Icon name="exit" :size="16" /> قيد استقالة</button>
      <button v-if="auth.isHr" class="btn pri sm" @click="openNew"><Icon name="plus" :size="16" /> حالة إنهاء</button>
    </div>
  </div>
  <div class="grid g4 mb">
    <Kpi label="استقالات وقرارات بانتظار القبول" :value="rows.filter((r) => ['resign_pending', 'resign_postponed', 'pending_decision'].includes(r.status)).length" color="y" />
    <Kpi label="قائمة / بانتظار الصرف" :value="rows.filter((r) => ['open', 'settled'].includes(r.status)).length" color="b" />
    <Kpi label="مصروفة" :value="rows.filter((r) => r.status === 'paid').length" color="g" /><Kpi label="إجمالي التصفيات القائمة" :value="money(rows.filter((r) => ['open', 'settled'].includes(r.status)).reduce((s, r) => s + Number(r.total), 0), 0)" color="p" />
  </div>
  <div class="card">
    <div v-if="!rows.length" class="empty"><div class="ic"><Icon name="exit" :size="40" :stroke="1.4" /></div>لا حالات إنهاء خدمة</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>السبب</th><th>آخر يوم عمل</th><th>إخلاء الطرف</th><th>المكافأة</th><th>صافي التصفية</th><th>موعد التسوية</th><th>الحالة</th><th /></tr></thead>
      <tbody><tr v-for="r in rows" :key="r.id">
        <td><b>{{ r.employee?.name }}</b><span class="tiny muted">{{ r.company }}</span></td><td>{{ r.reason_name }}<div v-if="r.filed_on" class="tiny muted">قُدّمت {{ fmtD(r.filed_on) }}</div></td><td class="num">{{ fmtD(r.last_working_day) }}</td>
        <td><span class="pill" :class="r.clearance_signed ? 'ok' : r.clearance_done === r.clearance_total ? 'info' : 'warn'">{{ r.clearance_done }}/{{ r.clearance_total }}{{ r.clearance_signed ? ' · معتمد' : '' }}</span></td>
        <td class="num">{{ money(r.eosb_amount) }}</td><td class="num"><b>{{ money(r.total) }}</b></td>
        <td class="num small" :class="['open', 'settled'].includes(r.status) && r.settlement_due < today() ? 'bad' : ''">{{ ['open', 'settled'].includes(r.status) ? fmtD(r.settlement_due) : '—' }}</td>
        <td><span class="pill" :class="SCOLOR[r.status]">{{ r.status_label }}</span></td>
        <td><button class="btn ghost xs" @click="open(r.id)">فتح</button></td>
      </tr></tbody></table></div>
  </div>

  <Modal v-if="d" :title="`إنهاء خدمة — ${d.employee?.name}`" size="wide" @close="d = null">
    <div class="row mb"><span class="pill" :class="SCOLOR[d.status]">{{ d.status_label }}</span><span class="small muted">{{ d.reason_name }} · آخر يوم عمل {{ fmtD(d.last_working_day) }} · مدة الإشعار {{ d.notice_days }} يومًا ({{ d.initiated_by === 'employee' ? 'إشعار الموظف' : 'إشعار صاحب العمل' }})</span><span v-if="d.note" class="tiny muted" style="white-space:pre-wrap">{{ d.note }}</span></div>

    <div v-if="d.filed_on" class="card" style="margin:0 0 12px"><h3>الاستقالة (§108)</h3>
      <div class="small">تاريخ التقديم <b>{{ fmtD(d.filed_on) }}</b> · العدول متاح حتى <b>{{ fmtD(d.withdraw_until) }}</b> ما لم تُقبل · تُعد مقبولة نظامًا في <b>{{ fmtD(d.auto_accept_on) }}</b> ما لم تُقبل قبل ذلك</div>
      <div v-if="d.postponed_until" class="notice warn tiny">أُجّل القبول حتى {{ fmtD(d.postponed_until) }} — {{ d.postpone_reason }}</div>
      <div v-if="d.accepted_at" class="notice ok tiny">قُبلت {{ fmtDT(d.accepted_at) }} — {{ ACCEPT[d.accepted_how] || d.accepted_how }}</div>
      <div v-if="d.withdrawn_at" class="notice info tiny">عدل الموظف عن الاستقالة {{ fmtDT(d.withdrawn_at) }}</div>
      <FlowSteps v-if="d.flows?.resign" :f="d.flows.resign" />
      <div v-if="d.can_postpone" class="fr mt"><div class="fg"><label>تأجيل القبول حتى (60 يومًا كحد أقصى)</label><DateInput v-model="pp.until" /></div><div class="fg"><label>الإيضاح المكتوب المسبَّب لمصلحة العمل *</label><input v-model="pp.note" class="in" /></div></div>
    </div>

    <div v-if="d.flows?.decision" class="card" style="margin:0 0 12px"><h3>قرار الإنهاء (§109) <span v-if="d.checklist?.leader" class="pill info">من القيادات</span></h3>
      <div v-if="d.checklist" class="grid g2 small">
        <div>نوع العقد: <b>{{ d.checklist.contract_type }}</b></div><div>الإشعار: <b>{{ d.checklist.notice === 'served' ? 'يُمنح كاملًا' : 'تعويض عن مدة الإشعار' }}</b></div>
        <div>السبب ومسوغه: {{ d.checklist.reason_detail }}</div><div>التعويض: {{ d.checklist.compensation }}</div>
        <div v-if="d.checklist.operational" style="grid-column:span 2">البدائل التي رُوجعت (سبب تشغيلي): {{ d.checklist.alternatives }}</div>
        <div class="tiny muted" style="grid-column:span 2">فحصتها {{ d.checklist.checked_by }} · {{ fmtDT(d.checklist.checked_at) }}{{ d.checklist.protected_checked ? ' · رُوجعت الحالات المحمية نظامًا' : '' }}</div>
      </div>
      <FlowSteps :f="d.flows.decision" />
    </div>

    <div v-if="['resign_pending', 'resign_postponed', 'pending_decision', 'withdrawn', 'rejected'].includes(d.status)" class="notice info small">تبدأ إجراءات إخلاء الطرف والتصفية بعد قبول الاستقالة أو اعتماد قرار الإنهاء.</div>
    <template v-else>
      <div class="steps mb"><b class="small" style="margin-inline-end:8px">التصفية:</b>
        <template v-for="(s, i) in STAGES" :key="s[0]"><span class="step" :class="i < d.stage ? 'done' : i === d.stage && d.status === 'open' ? 'cur' : ''"><i><Icon v-if="i < d.stage" name="check" :size="13" :stroke="3" /><template v-else>{{ i + 1 }}</template></i>{{ s[1] }}</span><Icon v-if="i < 3" name="chevron-left" :size="16" class="muted" flip-rtl /></template>
      </div>
      <div class="grid g2">
        <div class="card" style="margin:0"><h3>إخلاء الطرف <span class="sub">المهام تُنجز بالتوازي (§111)</span></h3>
          <div v-for="tsk in d.clearance" :key="tsk.key" class="row mb" style="justify-content:space-between">
            <div><Icon :name="tsk.done ? 'check-circle' : 'hourglass'" :size="15" :class="tsk.done ? 'ok' : 'muted'" /> {{ tsk.label }} <span class="tiny muted">({{ OWNER[tsk.owner] || tsk.owner }})</span><div v-if="tsk.done" class="tiny muted">{{ tsk.evidence }} — {{ tsk.by }} · {{ fmtDT(tsk.at) }}</div></div>
            <button v-if="!tsk.done && tsk.key !== 'clearance' && ['open', 'settled', 'paid'].includes(d.status)" class="btn sec xs" @click="doTask(tsk)">إتمام</button>
          </div>
          <h4 style="margin:10px 0 4px">اعتماد الإخلاء</h4>
          <FlowSteps v-if="d.flows?.clearance" :f="d.flows.clearance" />
          <button v-if="d.flows?.clearance?.can_act" class="btn okb xs mt" :class="{ loading: busy }" @click="flowAct('clearance', {})">اعتماد الإخلاء — {{ d.flows.clearance.next }}</button>
        </div>
        <div class="card" style="margin:0"><h3>مكافأة نهاية الخدمة</h3>
          <div class="small">مدة الخدمة: <b>{{ d.eosb.pretty }}</b> ({{ d.eosb.years }} سنة) · الأجر المعتبر <b class="num">{{ money(d.eosb.wage) }}</b></div>
          <div class="small">نصف أجر لأول {{ d.eosb.half_years }} سنوات: <b class="num">{{ money(d.eosb.t1) }}</b> · أجر كامل لما بعدها: <b class="num">{{ money(d.eosb.t2) }}</b></div>
          <div class="small">الإجمالي <b class="num">{{ money(d.eosb.gross) }}</b> × معامل {{ d.eosb.factor }} = <b class="num">{{ money(d.eosb.amount) }}</b> <span class="tiny muted">({{ d.eosb.why }})</span></div>
          <div v-if="d.eosb_adjusted !== null" class="notice warn tiny">مُعدَّلة إلى <b class="num">{{ money(d.eosb_adjusted) }}</b> — {{ d.eosb_adjust_reason }}</div>
          <div v-if="d.can_manage && d.status === 'open'" class="row mt"><input v-model.number="adj.amount" type="number" class="in" placeholder="مبلغ معدّل" style="max-width:140px" /><input v-model="adj.reason" class="in" placeholder="السبب *" /><button class="btn ghost xs" @click="adjust">تعديل</button></div>
          <div v-if="d.settlement_due" class="notice small" :class="d.settlement_due < today() && ['open', 'settled'].includes(d.status) ? 'bad' : 'info'">موعد تسوية المستحقات: <b>{{ fmtD(d.settlement_due) }}</b> — {{ d.settlement_due_rule }} · لا يُعلَّق صرف المستحقات غير المتنازع عليها على اكتمال إخلاء الطرف (§111)</div>
          <h3 style="margin-top:14px">التصفية النهائية <span class="sub">v{{ d.settlement?.version }}</span><span v-if="d.can_manage && d.status === 'open' && d.stage < 4" class="acts"><button class="btn ghost xs" @click="rebuild">إعادة بناء البنود</button></span></h3>
          <div v-dt class="tw"><table><tbody><tr v-for="(l, i) in d.settlement?.lines || []" :key="i"><td>{{ l.name }}</td><td class="num" :class="l.amount < 0 ? 'bad' : ''">{{ money(l.amount) }}</td></tr><tr><th>الصافي</th><th class="num">{{ money(d.settlement?.total) }}</th></tr></tbody></table></div>
        </div>
      </div>
      <div v-if="d.approvals?.length" class="mt"><b class="small">اعتمادات التصفية:</b> <span v-for="a in d.approvals" :key="a.role" class="pill ok">{{ a.label }} — {{ a.by }}</span></div>
    </template>
    <div v-if="d.can_approve || d.flows?.decision?.can_act || d.flows?.resign?.can_act" class="fg mt"><label>ملاحظة (اختيارية في الاعتماد، إلزامية في الرفض)</label><input v-model="note" class="in" /></div>
    <template #foot>
      <button class="btn sec" @click="d = null">إغلاق</button>
      <RouterLink v-if="d.settlement" class="btn ghost" :to="`/print/settlement/${d.id}`"><Icon name="external" :size="14" /> مستند التصفية</RouterLink>
      <button v-if="['open', 'pending_decision', 'resign_pending', 'resign_postponed'].includes(d.status) && auth.has('super', 'hr_mgr')" class="btn ghost bad" @click="cancel">إلغاء الحالة</button>
      <button v-if="d.can_withdraw" class="btn ghost bad" :class="{ loading: busy }" @click="withdraw">تسجيل العدول عن الاستقالة</button>
      <button v-if="d.can_postpone" class="btn sec" :class="{ loading: busy }" @click="postpone">تأجيل القبول</button>
      <button v-if="d.flows?.resign?.can_act" class="btn okb" :class="{ loading: busy }" @click="flowAct('resignation', { action: 'approve', note: note || null })">قبول الاستقالة — {{ d.flows.resign.next }}</button>
      <template v-if="d.flows?.decision?.can_act"><button class="btn dan" :class="{ loading: busy }" @click="rejectDecision">رفض القرار</button><button class="btn okb" :class="{ loading: busy }" @click="flowAct('decision', { action: 'approve', note: note || null })">اعتماد القرار — {{ d.flows.decision.next }}</button></template>
      <button v-if="d.can_approve" class="btn okb" :class="{ loading: busy }" @click="approve">اعتماد التصفية — {{ d.next_stage }}</button>
      <template v-if="d.can_pay"><button v-if="d.payment === 'none'" class="btn sec" @click="pay('sent')">إرسال إلى أودو</button><button class="btn pri" @click="pay('paid')">تأكيد الصرف</button></template>
    </template>
  </Modal>

  <Modal v-if="nf" title="فتح حالة إنهاء خدمة" size="wide" @close="nf = null">
    <div class="fg"><label>الموظف *</label><SearchSelect v-model="nf.employee_id" :options="empOptions" /></div>
    <div class="fr"><div class="fg"><label>السبب *</label><select v-model="nf.reason" class="in"><option v-for="r in reasons.filter((x) => x.code !== 'resign')" :key="r.code" :value="r.code">{{ r.name }}</option></select></div><div class="fg"><label>آخر يوم عمل *</label><DateInput v-model="nf.last_working_day" /></div></div>
    <div class="fg"><label>ملاحظة *</label><input v-model="nf.note" class="in" /></div>
    <template v-if="nf.reason === 'employer'">
      <h4 style="margin:8px 0">قائمة الفحص قبل الإنهاء (§109)</h4>
      <div class="fr"><div class="fg"><label>نوع العقد *</label><input v-model="nf.checklist.contract_type" class="in" placeholder="غير محدد المدة / محدد المدة…" /></div>
        <div class="fg"><label>الإشعار *</label><select v-model="nf.checklist.notice" class="in"><option value="served">يُمنح الإشعار كاملًا ({{ noticeEmployer }} يومًا)</option><option value="compensated">تعويض عن مدة الإشعار</option></select></div></div>
      <div class="fg"><label>سبب الإنهاء ومسوغه النظامي *</label><textarea v-model="nf.checklist.reason_detail" class="in" rows="2" /></div>
      <div class="fg"><label>التعويض المستحق *</label><input v-model="nf.checklist.compensation" class="in" /></div>
      <label class="chk"><input v-model="nf.checklist.operational" type="checkbox" /> السبب تشغيلي (إعادة هيكلة / إلغاء دور)</label>
      <div v-if="nf.checklist.operational" class="fg"><label>البدائل التي رُوجعت (إعادة توزيع الدور، النقل بموافقة صحيحة…) *</label><textarea v-model="nf.checklist.alternatives" class="in" rows="2" /></div>
      <label class="chk"><input v-model="nf.checklist.protected_checked" type="checkbox" /> رُوجعت الحالات المحمية نظامًا وحق الموظف في البحث عن عمل خلال الإشعار</label>
      <p class="tiny muted">لا ينفذ القرار إلا بعد اعتماد المشرف المباشر ثم المالية ثم الرئيس التنفيذي للشركة ثم الرئيس التنفيذي للمجموعة (القيادات: المالية ثم الرئيس التنفيذي للمجموعة).</p>
    </template>
    <p v-else class="tiny muted">الاستقالة تُقيَّد من «قيد استقالة»، والفصل التأديبي يُفتح من قرار المخالفة.</p>
    <template #foot><button class="btn sec" @click="nf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="createCase">فتح الحالة</button></template>
  </Modal>

  <Modal v-if="rf" title="قيد استقالة (§108)" @close="rf = null">
    <div class="fg"><label>الموظف *</label><SearchSelect v-model="rf.employee_id" :options="empOptions" /></div>
    <div class="fr"><div class="fg"><label>تاريخ تقديم الاستقالة *</label><DateInput v-model="rf.filed_on" /></div><div class="fg"><label>آخر يوم عمل (فارغ = التقديم + مدة إشعار الموظف)</label><DateInput v-model="rf.last_working_day" /></div></div>
    <div class="fg"><label>وسيلة التقديم والمرجع *</label><input v-model="rf.note" class="in" placeholder="خطاب موقّع / بريد مؤسسي بتاريخ…" /></div>
    <p class="tiny muted">تُعد الاستقالة مقبولة بمضي 30 يومًا من تقديمها دون رد، ويجوز تأجيل قبولها حتى 60 يومًا بإيضاح مكتوب قبل نهاية الثلاثين، وللموظف العدول خلال 7 أيام ما لم تُقبل. يستمر العقد خلال المدة.</p>
    <template #foot><button class="btn sec" @click="rf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveResign">قيد الاستقالة</button></template>
  </Modal>

  <Modal v-if="simOpen" title="محاكاة مكافأة نهاية الخدمة" @close="simOpen = false">
    <div class="fg"><label>الموظف</label><SearchSelect v-model="sim.employee_id" :options="empOptions" /></div>
    <div class="fr"><div class="fg"><label>تاريخ الانتهاء</label><DateInput v-model="sim.end" /></div><div class="fg"><label>السبب</label><select v-model="sim.reason" class="in"><option v-for="r in reasons" :key="r.code" :value="r.code">{{ r.name }}</option></select></div></div>
    <button class="btn sec sm" @click="runSim">احسب</button>
    <div v-if="simRes" class="notice info mt">{{ simRes.pretty }} · الأجر المعتبر {{ money(simRes.wage) }} · الإجمالي {{ money(simRes.gross) }} × {{ simRes.factor }} = <b class="num">{{ money(simRes.amount) }}</b> · الإشعار {{ simRes.notice_days }} يومًا<div class="tiny">{{ simRes.why }}</div></div>
    <template #foot><button class="btn sec" @click="simOpen = false">إغلاق</button></template>
  </Modal>
</template>

<script setup>
import { promptBox } from '../dialog'
import { computed, defineComponent, h, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtD, fmtDT, money, today } from '../utils'

// one approval chain (steps with the done / current marker and who approved)
const FlowSteps = defineComponent({
  props: { f: { type: Object, required: true } },
  setup: (p) => () => h('div', { class: 'mt' }, [
    h('div', { class: 'row', style: 'gap:6px;flex-wrap:wrap' }, p.f.steps.map((s, i) => h('span', { class: ['pill', i < p.f.stage ? 'ok' : i === p.f.stage && !p.f.done ? 'warn' : 'gray'] }, `${i + 1}. ${s.label}${p.f.approvals[i] ? ' — ' + p.f.approvals[i].by : ''}`))),
    p.f.blocked ? h('div', { class: 'tiny muted mt' }, p.f.blocked) : null,
  ]),
})
const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const STAGES = [['mgr', 'المشرف المباشر'], ['hr', 'الموارد البشرية (إعداد)'], ['ceo', 'الرئيس التنفيذي للشركة'], ['fin', 'المالية (اعتماد وصرف)']]
const OWNER = { it: 'الخدمات المساندة/العهد والتقنية', fin: 'المالية', hr: 'الموارد البشرية', mgr: 'المشرف المباشر' }
const SCOLOR = { resign_pending: 'warn', resign_postponed: 'warn', pending_decision: 'purple', open: 'warn', settled: 'info', paid: 'ok', cancelled: 'gray', withdrawn: 'gray', rejected: 'bad' }
const ACCEPT = { auto: 'قبول نظامي بمضي المدة', approval: 'قبول باعتماد المسار', request: 'من طلب استقالة معتمد' }
const rows = ref([]); const d = ref(null); const nf = ref(null); const rf = ref(null); const emps = ref([]); const busy = ref(false); const note = ref(''); const adj = ref({ amount: null, reason: '' }); const pp = ref({ until: '', note: '' })
const simOpen = ref(false); const sim = ref({ employee_id: null, end: today(), reason: 'resign' }); const simRes = ref(null)
const reasons = computed(() => auth.meta?.lookups?.termination_reasons || [])
const noticeEmployer = computed(() => auth.meta?.policy?.noticeDaysEmployer || 60)
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const errMsg = (e) => Object.values(e.errors || {}).flat().join(' · ') || e.message
async function load() { rows.value = await api.get('/terminations'); if (!emps.value.length && auth.isApprover) emps.value = await api.get('/employees/options') }
async function open(id) { d.value = await api.get(`/terminations/${id}`); note.value = ''; adj.value = { amount: null, reason: '' }; pp.value = { until: d.value.postpone_max || '', note: '' } }
function openNew() { nf.value = { employee_id: null, reason: reasons.value.find((r) => r.code !== 'resign')?.code, last_working_day: today(), note: '', checklist: { contract_type: '', reason_detail: '', notice: 'served', compensation: '', operational: false, alternatives: '', protected_checked: false } } }
function openResign() { rf.value = { employee_id: null, filed_on: today(), last_working_day: '', note: '' } }
async function createCase() {
  if (!nf.value.employee_id || !nf.value.note?.trim()) return ui.toast('الموظف والملاحظة إلزاميان', 'warn')
  busy.value = true
  const body = { ...nf.value }; if (body.reason !== 'employer') delete body.checklist
  try { const r = await api.post('/terminations', body); ui.toast(r.status === 'pending_decision' ? 'رُفع قرار الإنهاء للاعتماد' : 'فُتحت الحالة', 'ok'); nf.value = null; load(); open(r.id) } catch (e) { ui.toast(errMsg(e), 'bad', 6000) } finally { busy.value = false }
}
async function saveResign() {
  if (!rf.value.employee_id || !rf.value.note?.trim()) return ui.toast('الموظف ووسيلة التقديم إلزاميان', 'warn')
  busy.value = true
  try { const r = await api.post('/terminations/resignation', { ...rf.value, last_working_day: rf.value.last_working_day || null }); ui.toast('قُيّدت الاستقالة وأُشعر الموظف والمسار', 'ok'); rf.value = null; load(); open(r.id) } catch (e) { ui.toast(errMsg(e), 'bad', 6000) } finally { busy.value = false }
}
async function flowAct(path, body) { busy.value = true; try { d.value = await api.post(`/terminations/${d.value.id}/${path}`, body); note.value = ''; ui.toast('تم الاعتماد', 'ok'); load() } catch (e) { ui.toast(errMsg(e), 'bad', 6000) } finally { busy.value = false } }
async function rejectDecision() { if (!note.value.trim()) return ui.toast('اكتب سبب الرفض في الملاحظة', 'warn'); await flowAct('decision', { action: 'reject', note: note.value }) }
async function postpone() { if (!pp.value.note.trim()) return ui.toast('الإيضاح المكتوب إلزامي', 'warn'); await flowAct('resignation', { action: 'postpone', note: pp.value.note, until: pp.value.until || null }) }
async function withdraw() { const n = await promptBox('مرجع العدول (خطاب أو بريد الموظف):'); if (!n) return; await flowAct('resignation', { action: 'withdraw', note: n }) }
async function doTask(tsk) { const evidence = await promptBox(`إثبات إتمام «${tsk.label}»:`); if (!evidence) return; try { await api.post(`/terminations/${d.value.id}/task`, { key: tsk.key, evidence }); open(d.value.id); load() } catch (e) { ui.toast(e.message, 'bad') } }
async function adjust() { if (!adj.value.reason?.trim()) return ui.toast('السبب إلزامي', 'warn'); d.value = await api.post(`/terminations/${d.value.id}/eosb`, adj.value); ui.toast('عُدّلت المكافأة وأُعيد بناء التصفية', 'ok'); load() }
async function rebuild() { d.value = await api.post(`/terminations/${d.value.id}/rebuild`); ui.toast('أُعيد بناء البنود — تبدأ الاعتمادات من جديد', 'ok'); load() }
async function approve() { busy.value = true; try { d.value = await api.post(`/terminations/${d.value.id}/approve`, { note: note.value || null }); ui.toast('تم الاعتماد', 'ok'); load() } catch (e) { ui.toast(e.message, 'bad', 6000) } finally { busy.value = false } }
async function pay(action) { const ref_ = action === 'paid' ? await promptBox('مرجع الصرف / الحوالة', { multiline: false, required: false }) : null; d.value = await api.post(`/terminations/${d.value.id}/pay`, { action, ref: ref_ }); ui.toast(action === 'paid' ? 'تم تأكيد الصرف' : 'أُرسل القيد إلى صادر أودو', 'ok'); load() }
async function cancel() { const n = await promptBox('سبب الإلغاء:'); if (!n) return; await api.post(`/terminations/${d.value.id}/cancel`, { note: n }); d.value = null; ui.toast('أُلغيت الحالة وعاد الموظف نشطًا'); load() }
async function runSim() { if (!sim.value.employee_id) return; simRes.value = await api.get(`/terminations/simulate/${sim.value.employee_id}`, { params: { end: sim.value.end, reason: sim.value.reason } }) }
onMounted(load)
</script>
