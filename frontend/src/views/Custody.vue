<template>
  <div class="page-h">
    <div><h1>العهد</h1><p>تجهيز ← تسليم ← إقرار استلام وتعهد ← قيد الاستخدام ← طلب استرداد ← فحص وإغلاق (سليمة / مفقودة / تالفة) · أسرار مشفّرة وكل كشف مسجَّل · العهد النقدية تُسوّى بالفواتير خلال 10 أيام عمل من انتهاء الغرض وبحد أقصى 30 يومًا</p></div>
    <div class="acts"><button v-if="auth.isApprover && tab === 'items'" class="btn pri sm" @click="openForm()"><Icon name="plus" :size="16" /> عهدة جديدة</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <template v-if="tab === 'mine'">
    <div class="card">
      <h3>عهدي</h3>
      <div v-if="!mine.length" class="empty"><div class="ic"><Icon name="briefcase" :size="40" :stroke="1.4" /></div>لا عهد مسجلة عليك</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>العهدة</th><th>النوع</th><th>القيمة</th><th>الحالة</th><th>موعد التسوية</th><th /></tr></thead>
        <tbody><tr v-for="i in mine" :key="i.id">
          <td><b>{{ i.ident }}</b></td><td>{{ i.type.name }}</td><td class="num">{{ money(i.value) }}</td>
          <td><span class="pill" :class="STATUS_COLOR[i.status]">{{ i.status_label }}</span></td>
          <td><template v-if="i.settle_due">{{ fmtD(i.settle_due) }} <span v-if="i.overdue" class="pill bad">متأخرة</span></template><template v-else>—</template></td>
          <td><button class="btn xs" :class="i.status === 'pending_ack' ? 'okb' : 'ghost'" @click="openId = i.id">{{ i.status === 'pending_ack' ? 'إقرار الاستلام' : 'فتح' }}</button></td>
        </tr></tbody>
      </table></div>
    </div>
    <div class="card">
      <h3>تسوياتي المالية</h3>
      <div v-if="!myClaims.length" class="empty">لا تسويات</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>العهدة</th><th>المصروف</th><th>الباقي</th><th>الحالة</th><th>التاريخ</th></tr></thead>
        <tbody><tr v-for="c in myClaims" :key="c.id"><td>{{ c.item?.ident }}</td><td class="num">{{ money(c.claimed) }}</td><td class="num">{{ money(c.remainder) }}</td><td><span class="pill" :class="CLAIM_COLOR[c.status]">{{ c.status_label }}</span></td><td>{{ fmtD(c.created_at) }}</td></tr></tbody>
      </table></div>
    </div>
  </template>

  <template v-else-if="tab === 'items'">
    <div class="grid g4 mb">
      <Kpi v-for="s in ['in_use', 'pending_ack', 'return_requested', 'available']" :key="s" :label="STATUS_LABEL[s]" :value="items.filter((i) => i.status === s).length" :color="{ in_use: 'g', pending_ack: 'y', return_requested: 'y', available: 'b' }[s]" />
    </div>
    <div class="card">
      <div class="row mb">
        <select v-model="statusFilter" class="in" style="width:auto"><option value="">كل الحالات</option><option v-for="(l, k) in STATUS_LABEL" :key="k" :value="k">{{ l }}</option></select>
        <div class="search"><Icon name="search" :size="16" /><input v-model="q" class="in" placeholder="بحث بالوصف أو الموظف…" /></div>
      </div>
      <div v-if="!filtered.length" class="empty">لا عهد</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>العهدة</th><th>النوع</th><th>الشركة</th><th>الموظف</th><th>القيمة</th><th>الحالة</th><th>موعد التسوية</th><th /></tr></thead>
        <tbody><tr v-for="i in filtered" :key="i.id">
          <td><b>{{ i.ident }}</b><span v-if="i.has_secret" class="tiny muted"><Icon name="key" :size="11" /> يحمل سرًا</span></td>
          <td>{{ i.type.name }}</td><td>{{ i.company?.name }}</td><td>{{ i.employee?.name || '—' }}</td><td class="num">{{ money(i.value) }}</td>
          <td><span class="pill" :class="STATUS_COLOR[i.status]">{{ i.status_label }}</span></td>
          <td><template v-if="i.settle_due">{{ fmtD(i.settle_due) }} <span v-if="i.overdue" class="pill bad">متأخرة</span></template><template v-else>—</template></td>
          <td><button class="btn ghost xs" @click="openId = i.id">فتح</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <template v-else-if="tab === 'claims'">
    <div class="card">
      <h3>تسويات العهد المالية <span class="sub">يقررها المدير المالي (المادة 44): اعتماد وإقفال، أو إرجاع لاستكمال المستندات، أو إقفال برد المتبقي لحساب الشركة أو بخصمه من الراتب</span></h3>
      <div v-if="!claims.length" class="empty">لا تسويات</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>الموظف</th><th>العهدة</th><th>القيمة</th><th>الفواتير</th><th>المصروف</th><th>الباقي</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="c in claims" :key="c.id">
          <td>{{ c.employee?.name }}</td><td>{{ c.item?.ident }}</td><td class="num">{{ money(c.item?.value) }}</td>
          <td class="small">{{ c.invoices.map((x) => `${x.name} (${money(x.amount)})`).join('، ') }}</td>
          <td class="num">{{ money(c.claimed) }}</td><td class="num">{{ money(c.remainder) }}</td>
          <td><span class="pill" :class="CLAIM_COLOR[c.status]">{{ c.status_label }}</span><div v-if="c.decision_note" class="tiny muted">{{ c.decision_note }}</div><div v-if="c.refund_amount" class="tiny muted">رُدّ {{ money(c.refund_amount) }} في {{ fmtD(c.refund_date) }}</div></td>
          <td><button v-if="c.status === 'pending_finance' && auth.has('fin', 'super')" class="btn okb xs" @click="decideTarget = c">قرار</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <template v-else-if="tab === 'recon'">
    <div class="row mb">
      <MonthPicker v-model="reconPeriod" @change="load" />
      <button class="btn sec sm" :disabled="!recon?.rows?.length" @click="exportRecon"><Icon name="download" :size="15" /> تصدير Excel</button>
    </div>
    <div v-if="recon" class="grid g4 mb">
      <Kpi label="أرصدة السلف القائمة" color="n"><span class="num">{{ money(recon.totals.advances_balance) }}</span></Kpi>
      <Kpi label="أقساط متأخرة" color="r"><span class="num">{{ money(recon.totals.overdue_instalments_amount) }}</span></Kpi>
      <Kpi label="عهد نقدية مفتوحة" color="b"><span class="num">{{ money(recon.totals.cash_amount) }}</span></Kpi>
      <Kpi label="عهد نقدية متأخرة التسوية" color="r" :hint="`${recon.totals.overdue_employees} موظف عليهم متأخرات`"><span class="num">{{ money(recon.totals.cash_overdue_amount) }}</span></Kpi>
    </div>
    <div v-if="recon?.companies?.length" class="card">
      <h3>حسب الشركة</h3>
      <div v-dt class="tw"><table>
        <thead><tr><th>الشركة</th><th>الموظفون</th><th>أرصدة السلف</th><th>أقساط متأخرة</th><th>عهد نقدية مفتوحة</th><th>عهد متأخرة</th><th>عليهم متأخرات</th></tr></thead>
        <tbody><tr v-for="c in recon.companies" :key="c.company_id">
          <td><b>{{ c.company }}</b></td><td>{{ c.employees }}</td><td class="num">{{ money(c.advances_balance) }}</td><td class="num">{{ money(c.overdue_instalments_amount) }}</td>
          <td class="num">{{ money(c.cash_amount) }}</td><td class="num">{{ money(c.cash_overdue_amount) }}</td>
          <td><span class="pill" :class="c.overdue_employees ? 'bad' : 'ok'">{{ c.overdue_employees }}</span></td>
        </tr></tbody>
      </table></div>
    </div>
    <div class="card">
      <h3>المطابقة الشهرية للسلف والعهد النقدية <span class="sub">المادة 46 — يطابقها المدير المالي ويرفع المتأخرات للرئيس التنفيذي للشركة وللمجموعة</span></h3>
      <div v-if="!recon" class="skel" />
      <div v-else-if="!recon.rows.length" class="empty">لا سلف قائمة ولا عهد نقدية مفتوحة</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>الموظف</th><th>الشركة</th><th>السلف</th><th>رصيد السلف</th><th>أقساط متأخرة</th><th>عهد نقدية</th><th>قيمتها</th><th>أقرب موعد تسوية</th><th>الحالة</th></tr></thead>
        <tbody><tr v-for="r in recon.rows" :key="r.employee_id" :class="{ 'row-bad': r.overdue }">
          <td><b>{{ r.name }}</b><span class="tiny muted">{{ r.emp_no }}</span><span v-if="r.terminated" class="pill gray">منتهية خدمته</span></td>
          <td>{{ r.company }}</td><td>{{ r.advances_count }}</td><td class="num">{{ money(r.advances_balance) }}</td>
          <td class="num"><span v-if="r.overdue_instalments" class="pill bad">{{ r.overdue_instalments }} · {{ money(r.overdue_instalments_amount) }}</span><template v-else>—</template></td>
          <td>{{ r.cash_count }}</td><td class="num">{{ money(r.cash_amount) }}</td>
          <td>{{ r.next_settle_due ? fmtD(r.next_settle_due) : '—' }}</td>
          <td><span class="pill" :class="r.overdue ? 'bad' : 'ok'">{{ r.overdue ? 'متأخرات' : 'منتظم' }}</span></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <template v-else-if="tab === 'types'">
    <div class="card">
      <h3>أنواع العهد <span class="acts"><button class="btn sec xs" @click="typeForm = { fields: [], requires_ack: true, active: true }"><Icon name="plus" :size="14" /> نوع</button></span></h3>
      <div v-dt class="tw"><table>
        <thead><tr><th>النوع</th><th>الحقول المخصصة</th><th>إقرار</th><th>سر</th><th>مالية</th><th>الجهة</th><th>العناصر</th><th /></tr></thead>
        <tbody><tr v-for="t in types" :key="t.id">
          <td><b>{{ t.name }}</b><span class="tiny muted">{{ t.name_en }}</span></td><td class="small">{{ t.fields.join(' · ') }}</td>
          <td>{{ t.requires_ack ? '✓' : '—' }}</td><td>{{ t.has_secret ? '✓' : '—' }}</td><td>{{ t.is_cash ? '✓' : '—' }}</td><td>{{ t.dept }}</td><td>{{ t.items_count }}</td>
          <td><button class="btn ghost xs" @click="typeForm = { ...t }">تعديل</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <CustodyItemModal v-if="openId" :id="openId" @close="openId = null" @changed="load" @edit="(it) => { openId = null; openForm(it) }" />

  <Modal v-if="form" :title="form.id ? 'تعديل عهدة' : 'عهدة جديدة'" @close="form = null">
    <div class="fr">
      <div class="fg"><label>النوع *</label><select v-model="form.custody_type_id" class="in" :disabled="!!form.id"><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
      <div class="fg"><label>الشركة المالكة *</label><select v-model="form.company_id" class="in" :disabled="!!form.id"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
    </div>
    <div class="fg"><label>الوصف / المعرّف *</label><input v-model="form.ident" class="in" /></div>
    <div class="fr">
      <div v-for="f in formType?.fields || []" :key="f" class="fg"><label>{{ f }}</label><input v-model="form.fields[f]" class="in" /></div>
      <div class="fg"><label>القيمة (ر.س)</label><input v-model.number="form.value" type="number" min="0" class="in" /></div>
      <div class="fg"><label>الحالة الفنية</label><input v-model="form.condition" class="in" /></div>
    </div>
    <template #foot><button class="btn sec" @click="form = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveForm">حفظ</button></template>
  </Modal>

  <Modal v-if="typeForm" :title="typeForm.id ? 'تعديل نوع' : 'نوع عهدة جديد'" @close="typeForm = null">
    <div class="fr">
      <div class="fg"><label>الاسم *</label><input v-model="typeForm.name" class="in" /></div>
      <div class="fg"><label>الاسم بالإنجليزية</label><input v-model="typeForm.name_en" class="in" /></div>
      <div class="fg"><label>الجهة</label><input v-model="typeForm.dept" class="in" /></div>
    </div>
    <div class="fg"><label>الحقول المخصصة (سطر لكل حقل)</label><textarea v-model="typeFieldsText" class="in" rows="5" /></div>
    <div class="row">
      <label class="chk"><input v-model="typeForm.requires_ack" type="checkbox" /> يتطلب إقرار استلام</label>
      <label class="chk"><input v-model="typeForm.has_secret" type="checkbox" /> يحمل سرًا (كلمة مرور/PIN)</label>
      <label class="chk"><input v-model="typeForm.is_cash" type="checkbox" /> عهدة مالية تُسوّى بالفواتير</label>
      <label class="chk"><input v-model="typeForm.active" type="checkbox" /> مفعّل</label>
    </div>
    <template #foot><button class="btn sec" @click="typeForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveType">حفظ</button></template>
  </Modal>

  <Modal v-if="decideTarget" title="قرار التسوية" size="narrow" @close="decideTarget = null">
    <p class="small">{{ decideTarget.employee?.name }} — {{ decideTarget.item?.ident }}<br />المصروف <b class="num">{{ money(decideTarget.claimed) }}</b> من <b class="num">{{ money(decideTarget.item?.value) }}</b> · الباقي <b class="num">{{ money(decideTarget.remainder) }}</b></p>
    <div class="fg"><label>القرار</label>
      <select v-model="decision" class="in">
        <option value="settle" :disabled="decideTarget.remainder > 0.009">(1) اعتماد التسوية وإقفال العهدة</option>
        <option value="return">(2) إرجاع للموظف لاستكمال المستندات</option>
        <option value="refund" :disabled="decideTarget.remainder <= 0">(3) إقفال مع رد المتبقي ({{ money(decideTarget.remainder) }}) لحساب الشركة البنكي</option>
        <option value="to_salary" :disabled="decideTarget.remainder <= 0">(3) إقفال مع خصم المتبقي ({{ money(decideTarget.remainder) }}) من أجر الشهر التالي</option>
      </select>
    </div>
    <template v-if="decision === 'refund'">
      <div class="fr">
        <div class="fg"><label>المبلغ المردود *</label><input v-model.number="refundAmount" type="number" min="0.01" step="0.01" class="in" /></div>
        <div class="fg"><label>تاريخ الرد *</label><input v-model="refundDate" type="date" class="in" /></div>
      </div>
      <div class="fg"><label>مرجع التحويل</label><input v-model="refundRef" class="in" /></div>
      <p v-if="refundAmount && refundAmount < decideTarget.remainder" class="tiny muted">يُخصم الفرق ({{ money(decideTarget.remainder - refundAmount) }}) من أجر الشهر التالي ضمن الحدود النظامية.</p>
    </template>
    <p v-if="decision === 'to_salary'" class="tiny muted">يُجدول قسطًا واحدًا في أجر الشهر التالي، ولا يتجاوز مع سائر الحسومات نصف الأجر (يُرحَّل الفرق ولا يسقط).</p>
    <div class="fg"><label>{{ decision === 'return' ? 'سبب الإرجاع *' : 'ملاحظة' }}</label><input v-model="decisionNote" class="in" /></div>
    <template #foot><button class="btn sec" @click="decideTarget = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="doDecide">تأكيد</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../api'
import CustodyItemModal from '../components/CustodyItemModal.vue'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { curYM, fmtD, money, norm, today } from '../utils'
import { exportXlsx } from '../excel'

const auth = useAuth()
const ui = useUi()
const STATUS_LABEL = { available: 'متاحة', pending_ack: 'بانتظار الإقرار', in_use: 'قيد الاستخدام', return_requested: 'مطلوب استردادها', inspecting: 'قيد الفحص', lost: 'مفقودة', damaged: 'تالفة' }
const STATUS_COLOR = { available: 'gray', pending_ack: 'warn', in_use: 'ok', return_requested: 'warn', inspecting: 'info', lost: 'bad', damaged: 'bad' }
const CLAIM_COLOR = { pending_finance: 'warn', settled: 'ok', refunded: 'ok', to_salary: 'info', returned: 'bad' }

const tabs = computed(() => [auth.emp && ['mine', 'عهدي'], auth.isApprover && ['items', 'كل العهد'], auth.isApprover && ['claims', 'تسويات العهد المالية'], auth.has('fin', 'super', 'ceo') && ['recon', 'المطابقة الشهرية'], auth.isAdmin && ['types', 'أنواع العهد']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'items')
const mine = ref([]); const myClaims = ref([]); const items = ref([]); const claims = ref([]); const types = ref([])
const statusFilter = ref(''); const q = ref('')
const openId = ref(null); const form = ref(null); const typeForm = ref(null); const decideTarget = ref(null)
const decision = ref('settle'); const decisionNote = ref(''); const busy = ref(false)
const typeFieldsText = ref('')
const recon = ref(null); const reconPeriod = ref(curYM())
const refundAmount = ref(null); const refundDate = ref(today()); const refundRef = ref('')
watch(decideTarget, (c) => { if (c) { decision.value = c.remainder > 0.009 ? 'refund' : 'settle'; refundAmount.value = c.remainder > 0 ? c.remainder : null; refundDate.value = today(); refundRef.value = ''; decisionNote.value = '' } })

const filtered = computed(() => items.value.filter((i) => (!statusFilter.value || i.status === statusFilter.value) && (!q.value || norm(i.ident + ' ' + (i.employee?.name || '')).includes(norm(q.value)))))
const formType = computed(() => types.value.find((t) => t.id === form.value?.custody_type_id))
watch(typeForm, (t) => { if (t) typeFieldsText.value = (t.fields || []).join('\n') })
watch(() => form.value?.custody_type_id, () => { if (form.value && !form.value.id) form.value.fields = {} })

async function load() {
  if (!types.value.length) types.value = await api.get('/custody/types')
  if (tab.value === 'mine') [mine.value, myClaims.value] = await Promise.all([api.get('/custody/mine'), api.get('/custody/claims/mine')])
  else if (tab.value === 'items') items.value = await api.get('/custody', { params: { company_id: ui.company || undefined } })
  else if (tab.value === 'claims') claims.value = await api.get('/custody/claims')
  else if (tab.value === 'recon') { recon.value = null; recon.value = await api.get('/finance/reconciliation', { params: { period: reconPeriod.value, company_id: ui.company || undefined } }) }
  else if (tab.value === 'types') types.value = await api.get('/custody/types')
}
function openForm(it) {
  form.value = it
    ? { id: it.id, custody_type_id: it.type.id, company_id: it.company.id, ident: it.ident, fields: { ...(it.fields || {}) }, value: it.value, condition: it.condition }
    : { custody_type_id: types.value[0]?.id, company_id: auth.meta.companies[0]?.id, ident: '', fields: {}, value: 0, condition: '' }
}
async function saveForm() {
  const f = form.value
  if (!f.ident?.trim()) return ui.toast('الوصف إلزامي', 'warn')
  busy.value = true
  try {
    if (f.id) await api.put(`/custody/${f.id}`, { ident: f.ident, fields: f.fields, value: f.value, condition: f.condition })
    else await api.post('/custody', f)
    ui.toast('تم الحفظ', 'ok'); form.value = null; load()
  } finally { busy.value = false }
}
async function saveType() {
  const t = typeForm.value
  if (!t.name?.trim()) return ui.toast('الاسم إلزامي', 'warn')
  busy.value = true
  try {
    const body = { ...t, fields: typeFieldsText.value.split('\n').map((s) => s.trim()).filter(Boolean) }
    delete body.items_count
    if (t.id) await api.put(`/custody/types/${t.id}`, body); else await api.post('/custody/types', body)
    ui.toast('تم الحفظ', 'ok'); typeForm.value = null; types.value = await api.get('/custody/types')
  } finally { busy.value = false }
}
async function doDecide() {
  if (decision.value === 'return' && !decisionNote.value.trim()) return ui.toast('سبب الإرجاع إلزامي', 'warn')
  const body = { decision: decision.value, note: decisionNote.value || null }
  if (decision.value === 'refund') Object.assign(body, { refund_amount: refundAmount.value, refund_date: refundDate.value, refund_ref: refundRef.value || null })
  busy.value = true
  try { await api.post(`/custody/claims/${decideTarget.value.id}/decide`, body); ui.toast('تم', 'ok'); decideTarget.value = null; decisionNote.value = ''; load() } finally { busy.value = false }
}
async function exportRecon() {
  const r = recon.value
  const head = ['الموظف', 'الرقم الوظيفي', 'الشركة', 'عدد السلف', 'رصيد السلف', 'أقساط متأخرة (عدد)', 'أقساط متأخرة (مبلغ)', 'عهد نقدية مفتوحة', 'قيمة العهد', 'عهد متأخرة', 'قيمة المتأخرة', 'أقرب موعد تسوية', 'الحالة']
  const rows = r.rows.map((x) => [x.name, x.emp_no, x.company, x.advances_count, x.advances_balance, x.overdue_instalments, x.overdue_instalments_amount, x.cash_count, x.cash_amount, x.cash_overdue, x.cash_overdue_amount, x.next_settle_due ? fmtD(x.next_settle_due) : '', x.overdue ? 'متأخرات' : 'منتظم'])
  const byCo = [['الشركة', 'الموظفون', 'أرصدة السلف', 'أقساط متأخرة', 'عهد نقدية مفتوحة', 'عهد متأخرة', 'موظفون عليهم متأخرات'], ...r.companies.map((c) => [c.company, c.employees, c.advances_balance, c.overdue_instalments_amount, c.cash_amount, c.cash_overdue_amount, c.overdue_employees])]
  await exportXlsx(`مطابقة السلف والعهد ${r.period}`, { 'تفصيل الموظفين': [head, ...rows], 'ملخص الشركات': byCo }, { title: 'المطابقة الشهرية للسلف والعهد النقدية', period: r.period, kind: 'المطابقة الشهرية للسلف والعهد' })
}
onMounted(load)
</script>

<style scoped>
tr.row-bad td { background: rgba(192, 57, 43, .08); }
</style>
