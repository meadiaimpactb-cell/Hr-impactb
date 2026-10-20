<template>
  <div class="page-h">
    <div><h1>{{ t('المخالفات والانضباط') }}</h1><p>تسجيل (خلال 30 يومًا من الكشف) ← إفادة الموظف خلال يومي عمل على الأقل ← تحقيق الموارد البشرية ومحضره وتوصيتها ← اعتماد الرئيس التنفيذي للشركة خلال 30 يومًا من انتهاء التحقيق ← تنفيذ الموارد البشرية وتبليغ الموظف ← نافذة · للموظف التظلم خلال 30 يومًا من التبليغ أمام لجنة مستقلة يعتمد قرارها الرئيس التنفيذي للمجموعة</p></div>
    <div v-if="auth.isApprover" class="acts"><RouterLink class="btn ghost sm" to="/fines-register"><Icon name="money" :size="16" /> سجل الغرامات</RouterLink><button class="btn pri sm" @click="openForm()"><Icon name="plus" :size="16" /> تسجيل مخالفة</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <div v-if="tab === 'mine'" class="card">
    <div v-if="!mine.length" class="empty"><div class="ic"><Icon name="gavel" :size="40" :stroke="1.4" /></div>لا مخالفات مسجلة بحقك</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>النوع</th><th>التاريخ</th><th>الوصف</th><th>الدرجة</th><th>الحالة</th><th /></tr></thead>
      <tbody><tr v-for="v in mine" :key="v.id"><td><b>{{ v.type_name }}</b></td><td class="num">{{ fmtD(v.occurred_on) }}</td><td class="small">{{ v.description }}</td><td>{{ v.degree_name || v.proposed_name + ' (مقترح)' }}</td><td><span class="pill" :class="COLOR[v.status]">{{ v.status_label }}</span><span v-if="v.objection !== 'none'" class="tiny muted">تظلم: {{ v.objection_label }}</span></td><td><button class="btn xs" :class="v.status === 'awaiting_statement' ? 'okb' : 'ghost'" @click="open(v.id)">{{ v.status === 'awaiting_statement' ? 'كتابة الإفادة' : 'فتح' }}</button></td></tr></tbody></table></div>
  </div>

  <template v-else-if="tab === 'all'">
    <div class="card">
      <div class="row mb"><select v-model="status" class="in" style="width:auto" @change="load"><option value="">كل الحالات</option><option v-for="(l, k) in STATUS" :key="k" :value="k">{{ l }}</option></select></div>
      <div v-if="!all.length" class="empty">لا مخالفات</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>النوع</th><th>التاريخ</th><th>الدرجة المقترحة</th><th>الموقَّعة</th><th>الأثر</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="v in all" :key="v.id"><td><b>{{ v.employee?.name }}</b><span class="tiny muted">{{ v.company }}</span></td><td>{{ v.type_name }}</td><td class="num">{{ fmtD(v.occurred_on) }}</td><td>{{ v.proposed_name }}</td><td>{{ v.degree_name || '—' }}</td><td class="num small">{{ effect(v) }}</td><td><span class="pill" :class="COLOR[v.status]">{{ v.status_label }}</span><span v-if="['open', 'proposed'].includes(v.objection)" class="pill warn">تظلم</span><span v-if="v.removal_status === 'approved'" class="pill info">إبعاد احترازي</span></td><td><button class="btn ghost xs" @click="open(v.id)">فتح</button></td></tr></tbody></table></div>
    </div>
  </template>

  <div v-else-if="tab === 'suggest'" class="card">
    <h3>اقتراحات آلية <span class="sub">من سجل الحضور (تأخر ≥ 3 مرات أو غياب ≥ يوم في الشهر الماضي) ومن رصد الانقطاع اليومي (§100–101) · لا تُوقِّع المنصة جزاءً آليًا (§95)</span></h3>
    <div v-if="!suggestions.length" class="empty">لا اقتراحات</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>المصدر</th><th>النوع المقترح</th><th>الوصف</th><th /></tr></thead>
      <tbody><tr v-for="(s, i) in suggestions" :key="i"><td><b>{{ s.employee?.name }}</b></td>
        <td><span v-if="s.kind === 'absence_watch'" class="pill" :class="s.severity">{{ s.alert_label }}</span><span v-else class="pill gray">الحضور الشهري</span></td>
        <td>{{ s.type_name }}<div v-if="s.kind === 'absence_watch'" class="tiny muted">الدرجة المقترحة: إنذار كتابي قبل الفصل</div></td><td class="small">{{ s.description }}</td>
        <td class="row" style="gap:4px;flex-wrap:nowrap"><button class="btn sec xs" @click="openForm(s)">تسجيل</button><button v-if="s.kind === 'absence_watch' && auth.isHr" class="btn ghost xs" @click="dismiss(s)">استبعاد (عذر مقبول)</button></td></tr></tbody></table></div>
  </div>

  <div v-else class="card">
    <h3>جداول المخالفات والجزاءات <span class="sub">اللائحة §97–103 · الغرامة على الأجر الأساسي اليومي (÷30) فقط · لا تزيد على أجر 5 أيام للمخالفة و5 أيام في الشهر · الإيقاف دون أجر حتى 5 أيام في الشهر · الحرمان من العلاوة أو الترقية حتى 12 شهرًا · مهلة الإفادة {{ rules?.statement_days }} أيام عمل، والتظلم {{ rules?.objection_days }} يومًا من التبليغ، ولا اتهام بعد {{ rules?.charge_days }} يومًا من الكشف · التكرار خلال 180 يومًا من تبليغ الجزاء السابق</span></h3>
    <div v-dt class="tw"><table><thead><tr><th>الجدول</th><th>المخالفة</th><th>المرة الأولى</th><th>الثانية</th><th>الثالثة</th><th>الرابعة</th><th>الرجوع</th></tr></thead>
      <tbody><tr v-for="tp in rules?.types || []" :key="tp.code"><td class="small">{{ CAT[tp.meta.category] || '—' }}</td><td><b>{{ tp.name }}</b></td><td v-for="i in 4" :key="i" class="small">{{ stepLabel(ladder(tp)[i - 1]) }}</td><td class="small">{{ tp.meta.lookback_days }} يوم</td></tr></tbody></table></div>
    <div class="row mt"><span v-for="g in rules?.degrees || []" :key="g.code" class="pill" :class="g.meta.terminates ? 'bad' : g.meta.financial ? 'warn' : 'gray'">{{ g.code }} · {{ g.name }}</span></div>
  </div>

  <Modal v-if="d" :title="`${d.type_name} — ${d.employee?.name || ''}`" size="wide" @close="d = null">
    <div class="row mb"><span class="pill" :class="COLOR[d.status]">{{ d.status_label }}</span><span class="small muted">وقعت {{ fmtD(d.occurred_on) }} · كُشفت {{ fmtD(d.discovered_on || d.occurred_on) }} · سُجّلت بواسطة {{ d.recorded_by }} · مهلة الإفادة حتى {{ fmtD(d.deadline) }} ({{ d.deadline_rule }})</span><span v-if="d.prior" class="pill warn">سوابق مبلَّغة خلال 180 يومًا: {{ d.prior }}</span></div>
    <div class="grid g2">
      <div><h4 style="margin:0 0 6px">الوصف</h4><p class="small">{{ d.description }}</p><p v-if="d.evidence" class="tiny muted">الدليل: {{ d.evidence }}</p>
        <h4 style="margin:10px 0 6px">إفادة الموظف</h4><p v-if="d.statement" class="small">{{ d.statement }} <span class="tiny muted">({{ fmtDT(d.statement_at) }})</span></p><textarea v-else-if="d.can_state" v-model="stmt" class="in" rows="4" placeholder="اكتب إفادتك…" /><p v-else class="muted small">لا إفادة</p>
        <h4 style="margin:10px 0 6px">التحقيق (§104)</h4>
        <div class="small">المحقق: <b>{{ d.investigator || '—' }}</b><span v-if="d.investigation_done_on" class="tiny muted"> · انتهى التحقيق {{ fmtD(d.investigation_done_on) }}<template v-if="d.sign_deadline"> · يُوقَّع الجزاء حتى {{ fmtD(d.sign_deadline) }} (§97)</template></span></div>
        <p v-if="d.minutes && !d.can_investigate" class="small" style="white-space:pre-wrap">{{ d.minutes }}</p>
        <template v-if="d.can_investigate">
          <div class="fg"><label>المحقق (لا يكون من سجّل المخالفة)</label><select v-model="inv.investigator_id" class="in"><option :value="auth.user?.id">أنا ({{ auth.user?.name }})</option><option v-for="x in users" :key="x.id" :value="x.id">{{ x.name }} — {{ x.role }}</option></select></div>
          <div class="fg"><label>محضر التحقيق</label><textarea v-model="inv.minutes" class="in" rows="3" /></div>
          <button class="btn sec xs" :class="{ loading: busy }" @click="act('investigation', inv)">حفظ التحقيق</button>
        </template>
        <div class="notice tiny mt" :class="d.removal_status === 'approved' ? 'warn' : 'info'">الإبعاد الاحترازي: <b>{{ REMOVAL[d.removal_status] || d.removal_status }}</b> — مؤقت عن مهمة أو صلاحية لحماية التحقيق مع استمرار الأجر، وليس جزاءً.<div v-if="d.removal_note" style="white-space:pre-wrap">{{ d.removal_note }}</div></div>
      </div>
      <div><h4 style="margin:0 0 6px">القرار</h4>
        <div class="small">الجزاء بحسب الجدول (المرة {{ (d.step_index ?? 0) + 1 }}): <b>{{ d.step_label }}</b> <span class="tiny muted">· الدرجة المقترحة {{ d.proposed_name }}</span></div>
        <div v-if="d.ladder" class="tiny muted">سلّم النوع: {{ d.ladder.join(' ← ') }}</div>
        <div v-if="d.recommended_name" class="small">توصية الموارد البشرية: <b>{{ d.recommended_name }}</b></div>
        <template v-if="d.can_recommend">
          <div class="fr mt"><div class="fg"><label>الدرجة الموصى بها</label><select v-model="rec.recommended_degree" class="in"><option v-for="g in rules?.degrees || []" :key="g.code" :value="g.code">{{ g.code }} · {{ g.name }}</option></select></div>
            <div class="fg"><label>الجزاء (من الجدول)</label><input v-model="rec.step" class="in" dir="ltr" placeholder="warn | 25% | 1d" /></div>
            <div class="fg" style="grid-column:span 2"><label>توصية الموارد البشرية بعد التحقق *</label><input v-model="rec.note" class="in" /></div></div>
        </template>
        <div v-if="d.signed_degree !== null" class="small">الدرجة الموقَّعة: <b>{{ d.degree_name }}</b><span v-if="d.amount"> · خصم <b class="num">{{ money(d.amount) }}</b> من راتب {{ d.period }}</span><span v-if="d.suspension_days"> · إيقاف دون أجر <b>{{ d.suspension_days }}</b> أيام ({{ d.period }})</span><span v-if="d.deprivation_until"> · حرمان حتى <b>{{ fmtD(d.deprivation_until) }}</b></span> · نافذة من {{ fmtD(d.effective_from) }}<span v-if="d.notified_at"> · بُلّغ {{ fmtDT(d.notified_at) }}</span></div>
        <div v-if="d.objection_decision" class="notice info tiny">{{ d.objection_decision }}</div>
        <template v-if="d.can_sign">
          <div class="fr mt"><div class="fg"><label>الدرجة</label><select v-model="sign.signed_degree" class="in"><option v-for="g in rules?.degrees || []" :key="g.code" :value="g.code">{{ g.code }} · {{ g.name }}</option></select></div>
            <div class="fg"><label>سريان من</label><DateInput v-model="sign.effective_from" /></div>
            <div v-if="isFinancial(sign.signed_degree)" class="fg"><label>المبلغ (فارغ = آلي من الجدول على الأجر الأساسي اليومي، بحد أجر 5 أيام)</label><input v-model.number="sign.amount" type="number" class="in" /></div>
            <div v-if="+sign.signed_degree === rules?.deg_suspension" class="fg"><label>أيام الإيقاف دون أجر (1–5، ومجموع الشهر ≤ 5)</label><input v-model.number="sign.suspension_days" type="number" min="1" max="5" class="in" /></div>
            <div v-if="+sign.signed_degree === rules?.deg_deprivation" class="fg"><label>مدة الحرمان من العلاوة / الترقية بالأشهر (1–12)</label><input v-model.number="sign.deprivation_months" type="number" min="1" max="12" class="in" /></div>
            <div class="fg" style="grid-column:span 2"><label>مبررات القرار *</label><input v-model="sign.reason" class="in" /></div></div>
        </template>
        <template v-if="d.objection !== 'none'">
          <div class="notice warn tiny mt">التظلم ({{ d.objection_label }}) — قُدّم {{ fmtDT(d.objection_at) }}<template v-if="d.decision_deadline"> · يُبت فيه حتى {{ fmtD(d.decision_deadline) }}</template>: {{ d.objection_text }}
            <div v-if="d.committee_members?.length">اللجنة: {{ d.committee_members.map((m) => m.name).join('، ') }}</div>
            <div v-if="d.objection_proposal">قرار اللجنة: <b>{{ d.objection_proposal === 'accepted' ? 'قبول التظلم' : 'رفض التظلم' }}</b> — {{ d.objection_proposal_note }}</div>
          </div>
        </template>
        <div v-if="d.status === 'active' && d.objection_deadline" class="tiny muted mt">التظلم متاح حتى {{ fmtD(d.objection_deadline) }} ({{ d.objection_rule }})</div>
        <div v-if="d.can_object" class="fg mt"><label>نص التظلم</label><textarea v-model="objText" class="in" rows="3" /></div>
        <div v-if="d.can_form_committee" class="fg mt"><label>أعضاء لجنة التظلم (لا تضم المحقق ولا من سجّل أو أوصى أو اعتمد)</label>
          <div class="row" style="max-height:140px;overflow:auto"><label v-for="x in users.filter((x) => !d.committee_excluded.includes(x.id))" :key="x.id" class="chk small"><input v-model="committee" type="checkbox" :value="x.id" /> {{ x.name }}</label></div></div>
        <div v-if="d.can_propose || d.can_finalize || d.can_cancel || d.can_propose_removal || d.can_approve_removal || d.can_end_removal" class="fg mt"><label>ملاحظة القرار</label><input v-model="note" class="in" /></div>
      </div>
    </div>
    <template #foot>
      <button class="btn sec" @click="d = null">إغلاق</button>
      <button v-if="d.can_state" class="btn pri" :class="{ loading: busy }" @click="act('statement', { statement: stmt })">إرسال الإفادة</button>
      <button v-if="d.can_propose_removal" class="btn ghost" :class="{ loading: busy }" @click="act('removal', { action: 'propose', note })">اقتراح إبعاد احترازي</button>
      <template v-if="d.can_approve_removal"><button class="btn ghost bad" :class="{ loading: busy }" @click="act('removal', { action: 'reject', note })">رفض الإبعاد</button><button class="btn okb" :class="{ loading: busy }" @click="act('removal', { action: 'approve', note })">اعتماد الإبعاد الاحترازي</button></template>
      <button v-if="d.can_end_removal" class="btn ghost" :class="{ loading: busy }" @click="act('removal', { action: 'end', note })">إنهاء الإبعاد</button>
      <button v-if="d.can_recommend" class="btn pri" :class="{ loading: busy }" @click="act('recommend', rec)">رفع التوصية للرئيس التنفيذي</button>
      <button v-if="d.can_sign" class="btn okb" :class="{ loading: busy }" @click="act('sign', sign)">اعتماد الدرجة</button>
      <button v-if="d.can_approve" class="btn okb" :class="{ loading: busy }" @click="act('approve')">تنفيذ وتبليغ الموظف</button>
      <button v-if="d.can_object" class="btn dan" :class="{ loading: busy }" @click="act('object', { text: objText })">تقديم التظلم</button>
      <button v-if="d.can_form_committee" class="btn pri" :class="{ loading: busy }" @click="act('committee', { members: committee })">تشكيل اللجنة</button>
      <template v-if="d.can_propose"><button class="btn dan" :class="{ loading: busy }" @click="act('objection', { decision: 'rejected', note })">اللجنة: رفض التظلم</button><button class="btn okb" :class="{ loading: busy }" @click="act('objection', { decision: 'accepted', note })">اللجنة: قبول التظلم</button></template>
      <template v-if="d.can_finalize"><button class="btn dan" :class="{ loading: busy }" @click="act('objection', { decision: 'rejected', note })">اعتماد: رفض التظلم</button><button class="btn okb" :class="{ loading: busy }" @click="act('objection', { decision: 'accepted', note })">اعتماد: قبول التظلم</button></template>
      <button v-if="d.can_cancel && d.status !== 'active'" class="btn ghost bad" :class="{ loading: busy }" @click="act('cancel', { note })">إلغاء المخالفة</button>
    </template>
  </Modal>

  <Modal v-if="f" title="تسجيل مخالفة" @close="f = null">
    <div class="fg"><label>الموظف *</label><SearchSelect v-model="f.employee_id" :options="empOptions" /></div>
    <div class="fg"><label>النوع *</label><select v-model="f.type" class="in"><option v-for="tp in rules?.types || []" :key="tp.code" :value="tp.code">{{ tp.name }}</option></select></div>
    <div class="fr"><div class="fg"><label>تاريخ الواقعة *</label><DateInput v-model="f.occurred_on" /></div><div class="fg"><label>تاريخ الكشف</label><DateInput v-model="f.discovered_on" /></div></div>
    <div class="fg"><label>الوصف *</label><textarea v-model="f.description" class="in" rows="3" /></div>
    <div class="fg"><label>الدليل</label><input v-model="f.evidence" class="in" /></div>
    <label class="chk"><input v-model="f.operational_impact" type="checkbox" /> أثر تشغيلي مثبت (يرفع جزاء التأخر وفق §99)</label>
    <p class="tiny muted">لا يجوز الاتهام بعد مضي 30 يومًا من الكشف (§97). الجزاء يُقترح من جدول اللائحة وعدد الجزاءات السابقة المماثلة المبلَّغة خلال 180 يومًا، ويمر بإفادة الموظف (يوما عمل على الأقل) ثم تحقيق الموارد البشرية وتوصيتها ثم اعتماد الرئيس التنفيذي للشركة.</p>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">تسجيل وإشعار الموظف</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { promptBox } from '../dialog'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtD, fmtDT, money, today } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const STATUS = { awaiting_statement: 'بانتظار الإفادة', awaiting_recommendation: 'بانتظار توصية الموارد البشرية', awaiting_approval: 'بانتظار اعتماد الرئيس التنفيذي', active: 'نافذة', cancelled: 'ملغاة' }
const COLOR = { awaiting_statement: 'warn', awaiting_recommendation: 'purple', awaiting_approval: 'info', active: 'bad', cancelled: 'gray' }
const REMOVAL = { none: 'لا يوجد', proposed: 'مقترح — بانتظار اعتماد الرئيس التنفيذي للشركة', approved: 'معتمد ونافذ', rejected: 'مرفوض', ended: 'منتهٍ' }
const rec = ref({ recommended_degree: '2', note: '' })
const tabs = computed(() => [auth.emp && ['mine', 'مخالفاتي'], auth.isApprover && ['all', 'السجل'], auth.isApprover && ['suggest', 'اقتراحات آلية'], ['rules', 'لائحة التدرّج']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'all')
const mine = ref([]); const all = ref([]); const suggestions = ref([]); const rules = ref(null); const status = ref('')
const d = ref(null); const f = ref(null); const emps = ref([]); const users = ref([]); const busy = ref(false); const stmt = ref(''); const objText = ref(''); const note = ref(''); const committee = ref([])
const inv = ref({ investigator_id: null, minutes: '' })
const sign = ref({ signed_degree: '1', effective_from: today(), amount: null, reason: '', suspension_days: null, deprivation_months: null })
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const ladder = (tp) => String(tp.meta.steps || tp.meta.ladder || '').split(',').map((x) => x.trim()).filter(Boolean)
const stepLabel = (s) => (s === 'warn' ? 'إنذار' : s === 'dismiss' ? 'فصل مع المكافأة' : s === 'investigate' ? 'إحالة للتحقيق' : s === '-' || !s ? '—' : /%$/.test(s) ? `غرامة ${s} من الأجر الأساسي اليومي` : /d$/.test(s) ? `غرامة أجر ${s.replace('d', '')} يوم` : s)
const CAT = { attendance: 'التأخر وإثبات الحضور (§99)', absence: 'الغياب (§100)', instructions: 'التعليمات والتقصير (§102)', conduct: 'السلوك والأصول والأموال (§103)' }
const isFinancial = (code) => !!(rules.value?.degrees || []).find((g) => g.code === String(code))?.meta?.financial
const effect = (v) => (v.amount ? money(v.amount) : v.suspension_days ? `إيقاف ${v.suspension_days} أيام` : v.deprivation_until ? `حرمان حتى ${fmtD(v.deprivation_until)}` : '—')
async function load() {
  if (!rules.value) rules.value = await api.get('/violations/rules')
  if (tab.value === 'mine') mine.value = await api.get('/violations/mine')
  else if (tab.value === 'all') all.value = await api.get('/violations', { params: { status: status.value || undefined, company_id: ui.company || undefined } })
  else if (tab.value === 'suggest') suggestions.value = await api.get('/violations/suggestions')
}
async function open(id) {
  d.value = await api.get(`/violations/${id}`); stmt.value = ''; objText.value = ''; note.value = ''; committee.value = []
  sign.value = { signed_degree: String(d.value.recommended_degree ?? d.value.proposed_degree), effective_from: today(), amount: null, reason: '', suspension_days: null, deprivation_months: null }
  rec.value = { recommended_degree: String(d.value.proposed_degree), step: d.value.step, note: '' }
  inv.value = { investigator_id: d.value.investigator_id || auth.user?.id, minutes: d.value.minutes || '' }
  if ((d.value.can_investigate || d.value.can_form_committee) && !users.value.length) users.value = await api.get('/users/options').catch(() => [])
}
async function openForm(s) { if (!emps.value.length) emps.value = await api.get('/employees/options'); f.value = { employee_id: s?.employee?.id || null, type: s?.type || rules.value?.types?.[0]?.code, occurred_on: s?.occurred_on || today(), discovered_on: today(), description: s?.description || '', evidence: s ? 'سجل الحضور' : '', alert_id: s?.alert_id || null } }
async function save() {
  if (!f.value.employee_id || !f.value.description?.trim()) return ui.toast('الموظف والوصف إلزاميان', 'warn')
  busy.value = true
  try { await api.post('/violations', f.value); ui.toast('سُجّلت المخالفة وأُشعر الموظف', 'ok'); f.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function dismiss(s) { const n = await promptBox('سبب الاستبعاد (مثل: ثبوت العذر بعد التحقق):'); if (!n) return; try { await api.post(`/violation-alerts/${s.alert_id}/dismiss`, { note: n }); ui.toast('استُبعد التنبيه', 'ok'); load() } catch (e) { ui.toast(e.message, 'bad') } }
async function act(action, body) {
  if (action === 'statement' && !stmt.value.trim()) return ui.toast('اكتب الإفادة', 'warn')
  if (action === 'sign' && !sign.value.reason?.trim()) return ui.toast('مبررات القرار إلزامية', 'warn')
  if (action === 'recommend' && !rec.value.note?.trim()) return ui.toast('اكتب توصية الموارد البشرية', 'warn')
  if (action === 'object' && !objText.value.trim()) return ui.toast('اكتب نص التظلم', 'warn')
  if (action === 'committee' && !committee.value.length) return ui.toast('اختر أعضاء اللجنة', 'warn')
  if (['objection', 'cancel', 'removal'].includes(action) && !note.value.trim()) return ui.toast('ملاحظة القرار إلزامية', 'warn')
  busy.value = true
  try { await api.post(`/violations/${d.value.id}/${action}`, body); ui.toast('تم', 'ok'); const id = d.value.id; d.value = null; load(); if (action !== 'cancel') open(id) } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
onMounted(load)
</script>
