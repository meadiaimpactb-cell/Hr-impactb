<template>
  <Modal :title="d ? d.ident : '…'" size="wide" @close="$emit('close')">
    <div v-if="!d"><div class="skel" v-for="i in 5" :key="i" /></div>
    <template v-else>
      <div class="row mb">
        <span class="pill" :class="STATUS_COLOR[d.status]">{{ d.status_label }}</span>
        <span class="pill gray">{{ d.type.name }}</span>
        <span class="small muted">{{ d.company?.name }}</span>
        <span v-if="d.employee" class="small">· <b>{{ d.employee.name }}</b> ({{ d.employee.emp_no }})</span>
        <span class="small muted">· القيمة <b class="num">{{ money(d.value) }}</b></span>
      </div>

      <div class="grid g2">
        <div>
          <h4 style="margin:0 0 6px">البيانات</h4>
          <div class="tw"><table><tbody>
            <tr v-for="(v, k) in d.fields || {}" :key="k"><th style="width:45%">{{ k }}</th><td>{{ v || '—' }}</td></tr>
            <tr v-if="d.condition"><th>الحالة الفنية</th><td>{{ d.condition }}</td></tr>
          </tbody></table></div>

          <template v-if="d.type.has_secret">
            <h4 style="margin:14px 0 6px"><Icon name="key" :size="15" /> السر / كلمة المرور</h4>
            <div v-if="!d.has_secret" class="notice info">لا سر محفوظ لهذه العهدة.</div>
            <div v-else class="small muted">محفوظ مشفّرًا · آخر تدوير {{ fmtDT(d.secret_rotated_at) }} · كل كشف يُسجَّل باسم من كشفه وسببه</div>
            <div v-if="revealed" class="notice warn" style="direction:ltr;font-family:monospace;user-select:all">{{ revealed }}</div>
            <div class="row mt">
              <button v-if="d.can_reveal && !revealed" class="btn sec sm" @click="revealOpen = true"><Icon name="key" :size="14" /> كشف السر</button>
              <button v-if="d.can_set_secret" class="btn ghost sm" @click="secretOpen = true"><Icon name="refresh" :size="14" /> {{ d.has_secret ? 'تدوير السر' : 'حفظ سر' }}</button>
            </div>
            <div v-if="d.secret_logs?.length" class="mt">
              <div class="tiny muted b">سجل الكشف</div>
              <div v-for="l in d.secret_logs" :key="l.id" class="tiny">{{ l.action === 'reveal' ? 'كشف' : 'تدوير' }} — {{ l.user?.name }} · {{ fmtDT(l.created_at) }}<span v-if="l.reason"> · {{ l.reason }}</span></div>
            </div>
          </template>
        </div>

        <div>
          <h4 style="margin:0 0 6px">السجل</h4>
          <ul class="timeline">
            <li v-for="(h, i) in [...d.history].reverse()" :key="i"><b>{{ h.event }}</b> <span class="tiny muted">{{ fmtDT(h.at) }}</span><div v-if="h.note" class="small">{{ h.note }}</div></li>
          </ul>
        </div>
      </div>

      <template v-if="d.type.is_cash && d.employee">
        <h4 style="margin:14px 0 6px">مواعيد التسوية (المادة 44)</h4>
        <div class="row small">
          <span>الصرف: <b>{{ fmtD(d.disbursed_at) }}</b></span>
          <span>· انتهاء الغرض: <b>{{ d.purpose_end ? fmtD(d.purpose_end) : 'لم يُحدَّد' }}</b></span>
          <span v-if="d.settle_due">· آخر موعد للتسوية: <b>{{ fmtD(d.settle_due) }}</b></span>
          <span v-if="d.overdue" class="pill bad">متأخرة التسوية</span>
          <span v-if="d.overspend_approved" class="pill info">موافقة مسبقة على تجاوز القيمة{{ d.overspend_limit ? ` حتى ${money(d.overspend_limit)}` : '' }}</span>
        </div>
        <p class="tiny muted">تُسوّى خلال 10 أيام عمل من انتهاء الغرض أو العودة من المهمة، وبحد أقصى 30 يومًا من الصرف. التأخر يوقف صرف أي عهدة أو سلفة جديدة.</p>
        <div class="row">
          <button v-if="d.can_purpose_end" class="btn ghost sm" @click="purposeOpen = true"><Icon name="calendar" :size="14" /> تحديد انتهاء الغرض</button>
          <button v-if="d.can_overspend" class="btn ghost sm" @click="overOpen = true">{{ d.overspend_approved ? 'تعديل موافقة التجاوز' : 'موافقة مسبقة على تجاوز القيمة' }}</button>
        </div>
      </template>

      <template v-if="d.ack_at || (isMine && d.status === 'pending_ack' && d.ack_text)">
        <h4 style="margin:14px 0 6px">إقرار الاستلام والتعهد (المادة 43)</h4>
        <div class="notice" :class="d.ack_at ? 'ok' : 'warn'">{{ d.ack_text }}</div>
        <p v-if="d.ack_at" class="tiny muted">وافق عليه الموظف في {{ fmtDT(d.ack_at) }}<span v-if="d.ack_device"> · الجهاز: {{ d.ack_device }}</span><span v-if="d.ack_ip"> · IP {{ d.ack_ip }}</span></p>
      </template>

      <template v-if="d.claims?.length">
        <h4 style="margin:14px 0 6px">تسويات العهدة المالية</h4>
        <div class="tw"><table>
          <thead><tr><th>التاريخ</th><th>المصروف</th><th>الحالة</th><th>ملاحظة القرار</th></tr></thead>
          <tbody><tr v-for="c in d.claims" :key="c.id"><td>{{ fmtD(c.created_at) }}</td><td class="num">{{ money(c.claimed) }}</td><td>{{ CLAIM_STATUS[c.status] || c.status }}<div v-if="c.refund_amount" class="tiny muted">رُدّ {{ money(c.refund_amount) }} في {{ fmtD(c.refund_date) }}</div><div v-if="c.salary_amount" class="tiny muted">يُخصم من الراتب {{ money(c.salary_amount) }}</div></td><td class="small">{{ c.decision_note || '—' }}</td></tr></tbody>
        </table></div>
      </template>
    </template>

    <template #foot>
      <button class="btn sec" @click="$emit('close')">إغلاق</button>
      <!-- employee actions -->
      <button v-if="isMine && d?.status === 'pending_ack'" class="btn okb" :class="{ loading: busy }" @click="doAck"><Icon name="check-circle" :size="15" /> أقرّ بالاستلام</button>
      <button v-if="isMine && d?.status === 'in_use' && !d.type.is_cash" class="btn sec" :class="{ loading: busy }" @click="act('request-return')">طلب استرداد</button>
      <button v-if="isMine && d?.status === 'in_use' && d.type.is_cash && !hasPendingClaim" class="btn pri" @click="claimOpen = true"><Icon name="money" :size="15" /> رفع فواتير التسوية</button>
      <!-- keeper actions -->
      <template v-if="d?.can_manage">
        <button v-if="d.status === 'available'" class="btn pri" @click="pick = 'hand'"><Icon name="user-check" :size="15" /> تسليم لموظف</button>
        <button v-if="d.status === 'in_use'" class="btn ghost" @click="pick = 'transfer'">نقل لموظف آخر</button>
        <button v-if="['in_use', 'return_requested'].includes(d.status)" class="btn sec" :class="{ loading: busy }" @click="act('receive')">استلام للفحص</button>
        <button v-if="d.status === 'inspecting'" class="btn okb" @click="closeOpen = true">إغلاق الفحص</button>
        <button class="btn ghost" @click="$emit('edit', d)"><Icon name="edit" :size="14" /> تعديل</button>
      </template>
    </template>
  </Modal>

  <Modal v-if="pick" :title="pick === 'hand' ? 'تسليم العهدة لموظف' : 'نقل العهدة'" size="narrow" @close="pick = null">
    <div class="fg"><label>الموظف *</label><SearchSelect v-model="pickEmp" :options="empOptions" /></div>
    <div v-if="pick === 'hand' && d.type.is_cash" class="fg"><label>تاريخ انتهاء الغرض (اختياري)</label><input v-model="pickEnd" type="date" class="in" /><span class="tiny muted">يبدأ منه احتساب مهلة التسوية (10 أيام عمل)، وبحد أقصى 30 يومًا من الصرف.</span></div>
    <p class="tiny muted">{{ d.type.requires_ack ? 'يصل الموظف طلب إقرار استلام قبل أن تصبح قيد الاستخدام.' : 'تصبح قيد الاستخدام مباشرة.' }}</p>
    <template #foot><button class="btn sec" @click="pick = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="doPick">تأكيد</button></template>
  </Modal>

  <Modal v-if="closeOpen" title="نتيجة الفحص" size="narrow" @close="closeOpen = false">
    <div class="fg"><label>النتيجة</label><select v-model="closeResult" class="in"><option value="ok">سليمة — تعود للمخزون</option><option value="lost">مفقودة — تُعالج وفق اللائحة (لا خصم آلي)</option><option value="damaged">تالفة — تُعالج وفق اللائحة (لا خصم آلي)</option></select></div>
    <div class="fg"><label>ملاحظة الفحص *</label><textarea v-model="closeNote" class="in" /></div>
    <template #foot><button class="btn sec" @click="closeOpen = false">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="doClose">حفظ</button></template>
  </Modal>

  <Modal v-if="purposeOpen" title="تاريخ انتهاء الغرض / العودة من المهمة" size="narrow" @close="purposeOpen = false">
    <div class="fg"><label>التاريخ *</label><input v-model="purposeDate" type="date" class="in" /></div>
    <template #foot><button class="btn sec" @click="purposeOpen = false">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="doPurpose">حفظ</button></template>
  </Modal>

  <Modal v-if="overOpen" title="موافقة مسبقة على تجاوز قيمة العهدة" size="narrow" @close="overOpen = false">
    <label class="chk mb"><input v-model="overApproved" type="checkbox" /> أوافق على أن تتجاوز الفواتير قيمة العهدة</label>
    <div v-if="overApproved" class="fg"><label>الحد الأعلى للتجاوز (ر.س، اختياري)</label><input v-model.number="overLimit" type="number" min="0" step="0.01" class="in" /></div>
    <div class="fg"><label>المبرر *</label><textarea v-model="overNote" class="in" /></div>
    <template #foot><button class="btn sec" @click="overOpen = false">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="doOverspend">حفظ</button></template>
  </Modal>

  <Modal v-if="revealOpen" title="كشف السر — يُسجَّل باسمك" size="narrow" @close="revealOpen = false">
    <div class="fg"><label>سبب الكشف *</label><input v-model="reason" class="in" /></div>
    <template #foot><button class="btn sec" @click="revealOpen = false">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="doReveal">كشف</button></template>
  </Modal>

  <Modal v-if="secretOpen" :title="d.has_secret ? 'تدوير السر' : 'حفظ السر'" size="narrow" @close="secretOpen = false">
    <div class="fg"><label>السر الجديد *</label><input v-model="newSecret" type="password" class="in" style="direction:ltr" /></div>
    <p class="tiny muted">يُحفظ مشفّرًا على الخادم ولا يظهر في أي قائمة أو تصدير.</p>
    <template #foot><button class="btn sec" @click="secretOpen = false">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="doSetSecret">حفظ</button></template>
  </Modal>

  <Modal v-if="claimOpen" title="تسوية العهدة المالية — الفواتير" @close="claimOpen = false">
    <div v-if="invoiceTotal > d.value && !d.overspend_approved" class="notice bad mb">مجموع الفواتير يتجاوز قيمة العهدة — لا يجوز إلا بموافقة مسبقة من المدير المالي (المادة 44).</div>
    <div class="notice info">قيمة العهدة: <b class="num">{{ money(d.value) }}</b> · مجموع الفواتير الحالي: <b class="num">{{ money(invoiceTotal) }}</b> · الباقي: <b class="num">{{ money(d.value - invoiceTotal) }}</b></div>
    <div v-for="(inv, i) in invoices" :key="i" class="row mb" style="flex-wrap:nowrap">
      <input v-model="inv.name" class="in" placeholder="وصف الفاتورة" />
      <input v-model.number="inv.amount" type="number" min="0" step="0.01" class="in" style="max-width:140px" placeholder="المبلغ" />
      <button class="btn ghost xs" :disabled="invoices.length === 1" @click="invoices.splice(i, 1)"><Icon name="trash" :size="14" /></button>
    </div>
    <button class="btn sec sm mb" @click="invoices.push({ name: '', amount: 0 })"><Icon name="plus" :size="14" /> فاتورة</button>
    <div class="fg"><label>تفاصيل الصرف *</label><textarea v-model="claimDetails" class="in" /></div>
    <template #foot><button class="btn sec" @click="claimOpen = false">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="doClaim">رفع للمالية</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import { confirmBox } from '../dialog'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtD, fmtDT, money } from '../utils'
import Modal from './Modal.vue'
import SearchSelect from './SearchSelect.vue'

const props = defineProps({ id: Number })
const emit = defineEmits(['close', 'changed', 'edit'])
const auth = useAuth()
const ui = useUi()
const d = ref(null)
const busy = ref(false)
const pick = ref(null)
const pickEmp = ref(null)
const empOptions = ref([])
const closeOpen = ref(false)
const closeResult = ref('ok')
const closeNote = ref('')
const revealOpen = ref(false)
const reason = ref('')
const revealed = ref('')
const secretOpen = ref(false)
const newSecret = ref('')
const claimOpen = ref(false)
const claimDetails = ref('')
const invoices = ref([{ name: '', amount: 0 }])

const STATUS_COLOR = { available: 'gray', pending_ack: 'warn', in_use: 'ok', return_requested: 'warn', inspecting: 'info', lost: 'bad', damaged: 'bad' }
const CLAIM_STATUS = { pending_finance: 'بانتظار المالية', settled: 'مسوّاة ومُقفلة', refunded: 'أُقفلت برد المتبقي لحساب الشركة', to_salary: 'أُقفلت بخصم المتبقي من الراتب', returned: 'مُرجَعة لاستكمال المستندات' }
const pickEnd = ref('')
const purposeOpen = ref(false)
const purposeDate = ref('')
const overOpen = ref(false)
const overApproved = ref(true)
const overLimit = ref(null)
const overNote = ref('')
const isMine = computed(() => d.value && auth.emp && d.value.employee?.id === auth.emp.id)
const hasPendingClaim = computed(() => (d.value?.claims || []).some((c) => c.status === 'pending_finance'))
const invoiceTotal = computed(() => invoices.value.reduce((s, i) => s + (Number(i.amount) || 0), 0))

async function load() { d.value = await api.get(`/custody/${props.id}`); revealed.value = '' }
onMounted(load)

async function run(fn, msg) {
  busy.value = true
  try { await fn(); if (msg) ui.toast(msg, 'ok'); await load(); emit('changed') } finally { busy.value = false }
}
const act = (action) => run(() => api.post(`/custody/${props.id}/${action}`), 'تم')
async function doPick() {
  if (!pickEmp.value) return ui.toast('اختر الموظف', 'warn')
  await run(() => api.post(`/custody/${props.id}/${pick.value}`, { employee_id: pickEmp.value, purpose_end: (pick.value === 'hand' && pickEnd.value) || undefined }), 'تم')
  pick.value = null; pickEnd.value = ''
}
async function doAck() {
  const text = d.value.ack_text || ''
  if (!await confirmBox(text, { title: 'إقرار الاستلام والتعهد', confirmText: 'أوافق وأقرّ بالاستلام' })) return
  await run(() => api.post(`/custody/${props.id}/ack`, { agree: true }), 'سُجّل إقرارك')
}
async function doPurpose() {
  if (!purposeDate.value) return ui.toast('اختر التاريخ', 'warn')
  await run(() => api.post(`/custody/${props.id}/purpose-end`, { purpose_end: purposeDate.value }), 'حُدّث موعد التسوية')
  purposeOpen.value = false
}
async function doOverspend() {
  if (!overNote.value.trim()) return ui.toast('المبرر إلزامي', 'warn')
  await run(() => api.post(`/custody/${props.id}/overspend`, { approved: overApproved.value, limit: overApproved.value && overLimit.value ? overLimit.value : null, note: overNote.value }), 'تم الحفظ')
  overOpen.value = false; overNote.value = ''
}
async function doClose() {
  if (!closeNote.value.trim()) return ui.toast('ملاحظة الفحص إلزامية', 'warn')
  await run(() => api.post(`/custody/${props.id}/close`, { result: closeResult.value, note: closeNote.value }), 'أُغلق الفحص')
  closeOpen.value = false
}
async function doReveal() {
  if (!reason.value.trim()) return ui.toast('السبب إلزامي', 'warn')
  busy.value = true
  try { revealed.value = (await api.post(`/custody/${props.id}/reveal`, { reason: reason.value })).secret; revealOpen.value = false; reason.value = ''; d.value = await api.get(`/custody/${props.id}`) } finally { busy.value = false }
}
async function doSetSecret() {
  if (!newSecret.value) return ui.toast('أدخل السر', 'warn')
  await run(() => api.post(`/custody/${props.id}/secret`, { secret: newSecret.value }), 'حُفظ السر مشفّرًا')
  secretOpen.value = false; newSecret.value = ''
}
async function doClaim() {
  if (!claimDetails.value.trim() || invoices.value.some((i) => !i.name || !(Number(i.amount) >= 0))) return ui.toast('أكمل الفواتير والتفاصيل', 'warn')
  await run(() => api.post(`/custody/${props.id}/claims`, { details: claimDetails.value, invoices: invoices.value }), 'رُفعت التسوية للمالية')
  claimOpen.value = false
}
// employee list only needed for hand/transfer; loaded lazily once
import { watch } from 'vue'
watch(pick, async (v) => { if (v && !empOptions.value.length) empOptions.value = (await api.get('/employees/options')).map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })) })
</script>
