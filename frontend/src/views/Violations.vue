<template>
  <div class="page-h">
    <div><h1>{{ t('المخالفات والانضباط') }}</h1><p>تسجيل ← إفادة الموظف خلال المهلة ← توقيع رئيس الشركة للدرجة ← اعتماد الموارد البشرية ← نافذة · الدرجة المالية تُخصم من الراتب، والدرجة النهائية تفتح حالة إنهاء خدمة · للموظف اعتراض واحد</p></div>
    <div v-if="auth.isApprover" class="acts"><button class="btn pri sm" @click="openForm()"><Icon name="plus" :size="16" /> تسجيل مخالفة</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <div v-if="tab === 'mine'" class="card">
    <div v-if="!mine.length" class="empty"><div class="ic"><Icon name="gavel" :size="40" :stroke="1.4" /></div>لا مخالفات مسجلة بحقك</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>النوع</th><th>التاريخ</th><th>الوصف</th><th>الدرجة</th><th>الحالة</th><th /></tr></thead>
      <tbody><tr v-for="v in mine" :key="v.id"><td><b>{{ v.type_name }}</b></td><td class="num">{{ v.occurred_on }}</td><td class="small">{{ v.description }}</td><td>{{ v.degree_name || v.proposed_name + ' (مقترح)' }}</td><td><span class="pill" :class="COLOR[v.status]">{{ v.status_label }}</span><span v-if="v.objection !== 'none'" class="tiny muted">اعتراض: {{ OBJ[v.objection] }}</span></td><td><button class="btn xs" :class="v.status === 'awaiting_statement' ? 'okb' : 'ghost'" @click="open(v.id)">{{ v.status === 'awaiting_statement' ? 'كتابة الإفادة' : 'فتح' }}</button></td></tr></tbody></table></div>
  </div>

  <template v-else-if="tab === 'all'">
    <div class="card">
      <div class="row mb"><select v-model="status" class="in" style="width:auto" @change="load"><option value="">كل الحالات</option><option v-for="(l, k) in STATUS" :key="k" :value="k">{{ l }}</option></select></div>
      <div v-if="!all.length" class="empty">لا مخالفات</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>النوع</th><th>التاريخ</th><th>الدرجة المقترحة</th><th>الموقَّعة</th><th>الخصم</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="v in all" :key="v.id"><td><b>{{ v.employee?.name }}</b><span class="tiny muted">{{ v.company }}</span></td><td>{{ v.type_name }}</td><td class="num">{{ v.occurred_on }}</td><td>{{ v.proposed_name }}</td><td>{{ v.degree_name || '—' }}</td><td class="num">{{ v.amount ? money(v.amount) : '—' }}</td><td><span class="pill" :class="COLOR[v.status]">{{ v.status_label }}</span><span v-if="v.objection === 'open'" class="pill warn">اعتراض</span></td><td><button class="btn ghost xs" @click="open(v.id)">فتح</button></td></tr></tbody></table></div>
    </div>
  </template>

  <div v-else-if="tab === 'suggest'" class="card">
    <h3>اقتراحات آلية من سجل الحضور <span class="sub">تأخر ≥ 3 مرات أو غياب ≥ يوم في الشهر الماضي ولم تُسجَّل بعد</span></h3>
    <div v-if="!suggestions.length" class="empty">لا اقتراحات</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>النوع</th><th>الوصف</th><th /></tr></thead>
      <tbody><tr v-for="(s, i) in suggestions" :key="i"><td><b>{{ s.employee.name }}</b></td><td>{{ s.type_name }}</td><td class="small">{{ s.description }}</td><td><button class="btn sec xs" @click="openForm(s)">تسجيل</button></td></tr></tbody></table></div>
  </div>

  <div v-else class="card">
    <h3>لائحة التدرّج <span class="sub">تُدار من البيانات الأساسية ← القوائم العامة (أنواع المخالفات ودرجات الجزاء) · مهلة الإفادة والاعتراض {{ rules?.objection_days }} أيام</span></h3>
    <div v-dt class="tw"><table><thead><tr><th>النوع</th><th>الواقعة 1</th><th>2</th><th>3</th><th>4+</th><th>مدة الرجوع</th><th>أيام الخصم عند الدرجة المالية</th></tr></thead>
      <tbody><tr v-for="tp in rules?.types || []" :key="tp.code"><td><b>{{ tp.name }}</b></td><td v-for="(d, i) in ladder(tp)" :key="i">{{ degName(d) }}</td><td>{{ tp.meta.lookback_days }} يوم</td><td>{{ tp.meta.deduct_days }}</td></tr></tbody></table></div>
    <div class="row mt"><span v-for="d in rules?.degrees || []" :key="d.code" class="pill" :class="d.meta.terminates ? 'bad' : d.meta.financial ? 'warn' : 'gray'">{{ d.code }} · {{ d.name }}</span></div>
  </div>

  <Modal v-if="d" :title="`${d.type_name} — ${d.employee?.name || ''}`" size="wide" @close="d = null">
    <div class="row mb"><span class="pill" :class="COLOR[d.status]">{{ d.status_label }}</span><span class="small muted">وقعت {{ d.occurred_on }} · سُجّلت بواسطة {{ d.recorded_by }} · مهلة الإفادة حتى {{ d.deadline }}</span><span v-if="d.prior" class="pill warn">سوابق من النوع نفسه: {{ d.prior }}</span></div>
    <div class="grid g2">
      <div><h4 style="margin:0 0 6px">الوصف</h4><p class="small">{{ d.description }}</p><p v-if="d.evidence" class="tiny muted">الدليل: {{ d.evidence }}</p>
        <h4 style="margin:10px 0 6px">إفادة الموظف</h4><p v-if="d.statement" class="small">{{ d.statement }} <span class="tiny muted">({{ fmtDT(d.statement_at) }})</span></p><textarea v-else-if="d.can_state" v-model="stmt" class="in" rows="4" placeholder="اكتب إفادتك…" /><p v-else class="muted small">لا إفادة</p></div>
      <div><h4 style="margin:0 0 6px">القرار</h4>
        <div class="small">الدرجة المقترحة: <b>{{ d.proposed_name }}</b></div>
        <div v-if="d.signed_degree !== null" class="small">الدرجة الموقَّعة: <b>{{ d.degree_name }}</b><span v-if="d.amount"> · خصم <b class="num">{{ money(d.amount) }}</b> من راتب {{ d.period }}</span> · نافذة من {{ d.effective_from }}</div>
        <div v-if="d.objection_decision" class="notice info tiny">{{ d.objection_decision }}</div>
        <template v-if="d.can_sign">
          <div class="fr mt"><div class="fg"><label>الدرجة</label><select v-model="sign.signed_degree" class="in"><option v-for="g in rules?.degrees || []" :key="g.code" :value="g.code">{{ g.code }} · {{ g.name }}</option></select></div>
            <div class="fg"><label>سريان من</label><DateInput v-model="sign.effective_from" /></div>
            <div v-if="isFinancial(sign.signed_degree)" class="fg"><label>المبلغ (فارغ = آلي حسب أيام الخصم)</label><input v-model.number="sign.amount" type="number" class="in" /></div>
            <div class="fg" style="grid-column:span 2"><label>مبررات القرار *</label><input v-model="sign.reason" class="in" /></div></div>
        </template>
        <div v-if="d.objection !== 'none'" class="notice warn tiny mt">اعتراض ({{ OBJ[d.objection] }}): {{ d.objection_text }}</div>
        <div v-if="d.can_object" class="fg mt"><label>نص الاعتراض</label><textarea v-model="objText" class="in" rows="3" /></div>
        <div v-if="d.can_decide_objection || d.can_cancel" class="fg mt"><label>ملاحظة القرار</label><input v-model="note" class="in" /></div>
      </div>
    </div>
    <template #foot>
      <button class="btn sec" @click="d = null">إغلاق</button>
      <button v-if="d.can_state" class="btn pri" :class="{ loading: busy }" @click="act('statement', { statement: stmt })">إرسال الإفادة</button>
      <button v-if="d.can_sign" class="btn okb" :class="{ loading: busy }" @click="act('sign', sign)">توقيع الدرجة</button>
      <button v-if="d.can_approve" class="btn okb" :class="{ loading: busy }" @click="act('approve')">اعتماد الموارد البشرية</button>
      <button v-if="d.can_object" class="btn dan" :class="{ loading: busy }" @click="act('object', { text: objText })">اعتراض</button>
      <template v-if="d.can_decide_objection"><button class="btn dan" :class="{ loading: busy }" @click="act('objection', { decision: 'rejected', note })">رفض الاعتراض</button><button class="btn okb" :class="{ loading: busy }" @click="act('objection', { decision: 'accepted', note })">قبول الاعتراض</button></template>
      <button v-if="d.can_cancel && d.status !== 'active'" class="btn ghost bad" :class="{ loading: busy }" @click="act('cancel', { note })">إلغاء المخالفة</button>
    </template>
  </Modal>

  <Modal v-if="f" title="تسجيل مخالفة" @close="f = null">
    <div class="fg"><label>الموظف *</label><SearchSelect v-model="f.employee_id" :options="empOptions" /></div>
    <div class="fr"><div class="fg"><label>النوع *</label><select v-model="f.type" class="in"><option v-for="tp in rules?.types || []" :key="tp.code" :value="tp.code">{{ tp.name }}</option></select></div><div class="fg"><label>تاريخ الواقعة *</label><DateInput v-model="f.occurred_on" /></div></div>
    <div class="fg"><label>الوصف *</label><textarea v-model="f.description" class="in" rows="3" /></div>
    <div class="fg"><label>الدليل</label><input v-model="f.evidence" class="in" /></div>
    <p class="tiny muted">الدرجة المقترحة تُحسب آليًا من سلّم النوع وعدد السوابق النافذة خلال مدة الرجوع.</p>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">تسجيل وإشعار الموظف</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtDT, money, today } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const STATUS = { awaiting_statement: 'بانتظار الإفادة', awaiting_approval: 'بانتظار التوقيع والاعتماد', active: 'نافذة', cancelled: 'ملغاة' }
const COLOR = { awaiting_statement: 'warn', awaiting_approval: 'info', active: 'bad', cancelled: 'gray' }
const OBJ = { none: '—', open: 'قيد النظر', accepted: 'قُبل', rejected: 'رُفض' }
const tabs = computed(() => [auth.emp && ['mine', 'مخالفاتي'], auth.isApprover && ['all', 'السجل'], auth.isApprover && ['suggest', 'اقتراحات آلية'], ['rules', 'لائحة التدرّج']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'all')
const mine = ref([]); const all = ref([]); const suggestions = ref([]); const rules = ref(null); const status = ref('')
const d = ref(null); const f = ref(null); const emps = ref([]); const busy = ref(false); const stmt = ref(''); const objText = ref(''); const note = ref('')
const sign = ref({ signed_degree: '1', effective_from: today(), amount: null, reason: '' })
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const ladder = (tp) => String(tp.meta.ladder || '').split(',').map((x) => x.trim()).filter(Boolean)
const degName = (code) => (rules.value?.degrees || []).find((g) => g.code === String(code))?.name || code
const isFinancial = (code) => !!(rules.value?.degrees || []).find((g) => g.code === String(code))?.meta?.financial
async function load() {
  if (!rules.value) rules.value = await api.get('/violations/rules')
  if (tab.value === 'mine') mine.value = await api.get('/violations/mine')
  else if (tab.value === 'all') all.value = await api.get('/violations', { params: { status: status.value || undefined, company_id: ui.company || undefined } })
  else if (tab.value === 'suggest') suggestions.value = await api.get('/violations/suggestions')
}
async function open(id) { d.value = await api.get(`/violations/${id}`); stmt.value = ''; objText.value = ''; note.value = ''; sign.value = { signed_degree: String(d.value.proposed_degree), effective_from: today(), amount: null, reason: '' } }
async function openForm(s) { if (!emps.value.length) emps.value = await api.get('/employees/options'); f.value = { employee_id: s?.employee?.id || null, type: s?.type || rules.value?.types?.[0]?.code, occurred_on: s?.occurred_on || today(), description: s?.description || '', evidence: s ? 'سجل الحضور' : '' } }
async function save() {
  if (!f.value.employee_id || !f.value.description?.trim()) return ui.toast('الموظف والوصف إلزاميان', 'warn')
  busy.value = true
  try { await api.post('/violations', f.value); ui.toast('سُجّلت المخالفة وأُشعر الموظف', 'ok'); f.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function act(action, body) {
  if (action === 'statement' && !stmt.value.trim()) return ui.toast('اكتب الإفادة', 'warn')
  if (action === 'sign' && !sign.value.reason?.trim()) return ui.toast('مبررات القرار إلزامية', 'warn')
  if (action === 'object' && !objText.value.trim()) return ui.toast('اكتب نص الاعتراض', 'warn')
  if ((action === 'objection' || action === 'cancel') && !note.value.trim()) return ui.toast('ملاحظة القرار إلزامية', 'warn')
  busy.value = true
  try { await api.post(`/violations/${d.value.id}/${action}`, body); ui.toast('تم', 'ok'); const id = d.value.id; d.value = null; load(); if (action !== 'cancel') open(id) } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
onMounted(load)
</script>
