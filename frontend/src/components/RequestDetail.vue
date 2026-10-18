<template>
  <Modal :title="r ? `${r.type_label} — ${r.employee?.name || ''}` : '…'" size="wide" @close="$emit('close')">
    <div v-if="!r"><div class="skel" /><div class="skel" /></div>
    <template v-else>
      <div class="steps mb">
        <template v-for="(s, i) in r.flow" :key="i">
          <span class="step" :class="stepCls(i)"><i><Icon v-if="i < r.stage || r.status === 'approved'" name="check" :size="13" :stroke="3" /><template v-else>{{ i + 1 }}</template></i>{{ s.label }}</span>
          <Icon v-if="i < r.flow.length - 1" name="chevron-left" :size="16" class="muted" flip-rtl />
        </template>
      </div>
      <div class="row mb"><StatusPill :status="r.status" /><span class="small muted">{{ fmtDT(r.created_at) }}</span><span v-if="r.waiting_for?.length" class="pill warn">بانتظار: {{ r.waiting_for.join('، ') }}</span></div>
      <div v-if="r.is_mine && r.status === 'pending' && ['hr_mgr', 'hr'].includes(r.flow[r.stage]?.role) && auth.has('hr_mgr')" class="notice warn small">هذا طلبك أنت، ولا يمكن لأحد اعتماد طلبه بنفسه، لذلك أُحيل تلقائيًا إلى {{ r.waiting_for?.join('، ') || 'الرئيس التنفيذي للمجموعة' }}. طلبات الموظفين الآخرين تصل إليك أنت.</div>
      <div class="notice info">{{ r.summary }} <a v-if="r.has_attachment" href="#" class="btn ghost xs" @click.prevent="fileUrl(r.attachment_path)"><Icon name="download" :size="13" /> المرفق</a></div>
      <div v-if="r.type === 'trip' && r.status === 'approved'" class="card" style="margin:8px 0">
        <b class="small">تقرير الرحلة</b>
        <p v-if="r.data.report" class="small" style="white-space:pre-wrap">{{ r.data.report }}<span class="tiny muted"> ({{ fmtDT(r.data.report_at) }})</span></p>
        <template v-else-if="r.is_mine"><textarea v-model="tripReport" class="in" rows="3" placeholder="ماذا تحقق في الرحلة؟" /><button class="btn sec xs mt" @click="sendTripReport">إرسال التقرير</button></template>
        <span v-else class="muted small">لم يُرفع بعد</span>
      </div>
      <div v-if="r.data.coverage_exceeded" class="notice bad">تجاوز حد الغياب في الفريق — أُضيف اعتماد استثنائي من الموارد البشرية.</div>
      <div v-if="r.data.team_off?.length" class="notice warn">في إجازة خلال الفترة نفسها من الفريق: {{ r.data.team_off.join('، ') }}</div>

      <div v-if="r.extra?.balance" class="grid g3 mb">
        <Kpi label="الرصيد المتاح" :value="r.extra.balance.available" hint="بعد حجز الطلبات المعلّقة" color="b" />
        <Kpi label="البديل" :value="subName || '—'" :hint="r.data.delegate ? 'مع تفويض الاعتمادات' : ''" color="p" />
        <Kpi label="تعارض التغطية" :value="r.extra.conflicts?.length || 0" :hint="(r.extra.conflicts || []).map((c) => c.name).join('، ') || 'لا يوجد'" :color="r.extra.conflicts?.length ? 'r' : 'g'" />
      </div>
      <div class="tw mb">
        <table><tbody>
          <tr v-for="(v, k) in shownData" :key="k"><th style="width:180px">{{ k }}</th><td>{{ v }}</td></tr>
        </tbody></table>
      </div>

      <h4 style="margin:10px 0 4px">سجل القرار</h4>
      <ul class="timeline">
        <li v-for="a in r.actions" :key="a.id" :class="{ rej: a.decision === 'return' }">
          <b>{{ decisions[a.decision] }}</b> — {{ a.decision === 'skip' ? 'النظام' : a.user?.name }}
          <span v-if="a.acting_for" class="tiny muted">(مفوَّض عن {{ a.acting_for.name }})</span>
          <span class="tiny muted"> {{ fmtDT(a.created_at) }}</span>
          <div v-if="a.reason" class="small">{{ a.reason }}</div>
        </li>
      </ul>

      <div v-if="deciding" class="fg mt">
        <label>{{ deciding === 'return' ? 'سبب الإرجاع (إلزامي)' : 'ملاحظة (اختياري)' }}</label>
        <textarea v-model="reason" class="in" />
      </div>
    </template>
    <template #foot>
      <button class="btn sec" @click="$emit('close')">{{ t('إغلاق') }}</button>
      <button v-if="r?.can_cancel" class="btn dan" @click="cancel">إلغاء الطلب</button>
      <template v-if="r?.can_decide">
        <template v-if="!deciding">
          <button class="btn sec" @click="deciding = 'return'">{{ t('إرجاع') }}</button>
          <button class="btn okb" @click="deciding = 'approve'">{{ t('اعتماد') }}</button>
        </template>
        <button v-else class="btn" :class="[deciding === 'approve' ? 'okb' : 'dan', { loading: busy }]" :disabled="busy" @click="decide">
          تأكيد {{ deciding === 'approve' ? 'الاعتماد' : 'الإرجاع' }}
        </button>
      </template>
    </template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { fileUrl } from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtDT } from '../utils'
import Kpi from './Kpi.vue'
import Modal from './Modal.vue'
import StatusPill from './StatusPill.vue'

const props = defineProps({ id: Number })
const emit = defineEmits(['close', 'changed'])
const { t } = useI18n()
const ui = useUi()
const r = ref(null)
const deciding = ref(null)
const reason = ref('')
const busy = ref(false)
const tripReport = ref('')
async function sendTripReport() { if (!tripReport.value.trim()) return; r.value = await api.post(`/requests/${props.id}/trip-report`, { report: tripReport.value }); ui.toast('أُرسل تقرير الرحلة', 'ok') }
const decisions = { submit: 'تقديم الطلب', approve: 'اعتماد', return: 'إرجاع', cancel: 'إلغاء', skip: 'مرحلة متخطاة' }
const labels = { type_name: 'النوع', from: 'من', to: 'إلى', days: 'الأيام', handover: 'تسليم المهام', amount: 'المبلغ', installments: 'الأقساط', monthly: 'القسط الشهري', purpose: 'الغرض', date: 'اليوم', kind: 'النوع', time: 'الوقت', reason: 'السبب', lang: 'اللغة', destination: 'الوجهة', estimate: 'التقدير الإجمالي', advance: 'العهدة النقدية', before: 'قبل', after: 'بعد', requested_last: 'آخر يوم مطلوب', computed_last: 'آخر يوم محسوب', notice: 'مدة الإشعار',
  scope: 'نوع الرحلة', cost_flight: 'الطيران', cost_lodging: 'السكن', cost_allowance: 'البدل اليومي (تقدير)', cost_transport: 'التنقل', cost_fees: 'الرسوم', level: 'المستوى الوظيفي', per_diem: 'البدل اليومي المستحق', allowance_total: 'إجمالي البدل اليومي', hotel_cap: 'سقف الفندق لليلة', hotel_total_cap: 'سقف الفندق للرحلة', why: 'المبرر', needed_by: 'مطلوبة بتاريخ' }
const hidden = ['type', 'substitute_id', 'delegate']
const auth = useAuth()
// Custom request types carry their own field labels (from the request-type form schema).
const customLabels = computed(() => Object.fromEntries(((auth.meta?.request_type_defs || []).find((d) => d.code === r.value?.type)?.form || []).map((f) => [f.key, f.label])))
const shownData = computed(() => Object.fromEntries(Object.entries(r.value?.data || {}).filter(([k, v]) => !hidden.includes(k) && v !== null && typeof v !== 'object' && !k.endsWith('_name'))
  .map(([k, v]) => [customLabels.value[k] || labels[k] || k, typeof v === 'boolean' ? (v ? 'نعم' : 'لا') : (r.value.data[k + '_name'] ?? v)])))
const subName = computed(() => r.value?.data?.substitute_id ? `#${r.value.data.substitute_id}` : null)
const stepCls = (i) => (r.value.status === 'returned' && i === r.value.stage ? 'rej' : i < r.value.stage || r.value.status === 'approved' ? 'done' : i === r.value.stage && r.value.status === 'pending' ? 'cur' : '')

onMounted(async () => { r.value = await api.get(`/requests/${props.id}`) })
async function decide() {
  if (deciding.value === 'return' && !reason.value.trim()) return ui.toast('سبب الإرجاع إلزامي', 'warn')
  busy.value = true
  try {
    await api.post(`/requests/${props.id}/decide`, { decision: deciding.value, reason: reason.value || null })
    ui.toast(deciding.value === 'approve' ? 'تم الاعتماد' : 'تم الإرجاع', 'ok')
    emit('changed'); emit('close')
  } finally { busy.value = false }
}
async function cancel() {
  if (!await confirmBox('إلغاء الطلب نهائيًا؟')) return
  await api.post(`/requests/${props.id}/cancel`)
  ui.toast('أُلغي الطلب'); emit('changed'); emit('close')
}
</script>
