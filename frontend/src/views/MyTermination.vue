<template>
  <div class="page-h"><div><h1>استقالتي وانتهاء خدمتي</h1><p>متابعة الاستقالة المقيّدة ومواعيدها النظامية، والعدول عنها خلال 7 أيام ما لم تُقبل، ومراجعة كشف التسوية دون تعطيل موعدها (§108، §112)</p></div></div>
  <div v-if="!rows.length" class="card"><div class="empty"><div class="ic"><Icon name="exit" :size="40" :stroke="1.4" /></div>لا توجد حالة إنهاء خدمة لك</div></div>
  <div v-for="r in rows" :key="r.id" class="card">
    <h3>{{ r.reason_name }} <span class="pill" :class="{ resign_pending: 'warn', resign_postponed: 'warn', withdrawn: 'gray', open: 'info', settled: 'info', paid: 'ok', pending_decision: 'purple', rejected: 'gray' }[r.status]">{{ r.status_label }}</span></h3>
    <div class="small">آخر يوم عمل: <b>{{ fmtD(r.last_working_day) }}</b> · مدة الإشعار {{ r.notice_days }} يومًا</div>
    <template v-if="r.filed_on">
      <div class="small">تاريخ تقديم الاستقالة: <b>{{ fmtD(r.filed_on) }}</b> · تُعد مقبولة نظامًا في <b>{{ fmtD(r.auto_accept_on) }}</b> ما لم تُقبل قبل ذلك · يحق لك العدول حتى <b>{{ fmtD(r.withdraw_until) }}</b> ما لم تُقبل</div>
      <div v-if="r.postponed_until" class="notice warn tiny">أُجّل قبول الاستقالة حتى {{ fmtD(r.postponed_until) }} — {{ r.postpone_reason }}. يستمر العقد خلال المدة.</div>
      <div v-if="r.withdrawn_at" class="notice info tiny">سُجّل عدولك عن الاستقالة {{ fmtDT(r.withdrawn_at) }}</div>
    </template>
    <template v-if="r.settlement">
      <h4 style="margin:12px 0 6px">كشف التسوية <span class="tiny muted">— موعد التسوية النظامي {{ fmtD(r.settlement_due) }}</span></h4>
      <div v-dt class="tw"><table><tbody><tr v-for="(l, i) in r.settlement.lines || []" :key="i"><td>{{ l.name }}</td><td class="num" :class="l.amount < 0 ? 'bad' : ''">{{ money(l.amount) }}</td></tr><tr><th>الصافي</th><th class="num">{{ money(r.settlement.total) }}</th></tr></tbody></table></div>
      <p class="tiny muted">لك مراجعة الكشف والاستفسار عنه عبر الموارد البشرية دون أن يؤخر ذلك موعد الصرف، ولا يُشترط لصرف حقك التوقيع على تنازل عام. تُصدر لك شهادة خدمة عند طلبها دون مقابل من «الخطابات الرسمية».</p>
    </template>
    <div v-if="r.can_withdraw" class="row mt"><button class="btn dan sm" :class="{ loading: busy }" @click="withdraw(r)">العدول عن الاستقالة</button></div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../api'
import { confirmBox } from '../dialog'
import { useUi } from '../stores/ui'
import { fmtD, fmtDT, money } from '../utils'

const ui = useUi()
const rows = ref([]); const busy = ref(false)
async function load() { rows.value = await api.get('/my-terminations').catch(() => []) }
async function withdraw(r) {
  if (!(await confirmBox('تأكيد العدول عن الاستقالة؟ يستمر عقدك دون انقطاع.', { danger: false }))) return
  busy.value = true
  try { await api.post(`/terminations/${r.id}/resignation`, { action: 'withdraw' }); ui.toast('سُجّل عدولك عن الاستقالة', 'ok'); load() } catch (e) { ui.toast(e.message, 'bad', 6000) } finally { busy.value = false }
}
onMounted(load)
</script>
