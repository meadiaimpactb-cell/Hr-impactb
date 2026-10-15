<template>
  <div class="page-h">
    <div><h1>العهد</h1><p>تجهيز ← تسليم ← إقرار استلام ← قيد الاستخدام ← طلب استرداد ← فحص وإغلاق · أسرار مشفّرة وكل كشف مسجَّل · العهد المالية تُسوّى بالفواتير</p></div>
    <div class="acts"><button v-if="auth.isApprover && tab === 'items'" class="btn pri sm" @click="openForm()"><Icon name="plus" :size="16" /> عهدة جديدة</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <template v-if="tab === 'mine'">
    <div class="card">
      <h3>عهدي</h3>
      <div v-if="!mine.length" class="empty"><div class="ic"><Icon name="briefcase" :size="40" :stroke="1.4" /></div>لا عهد مسجلة عليك</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>العهدة</th><th>النوع</th><th>القيمة</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="i in mine" :key="i.id">
          <td><b>{{ i.ident }}</b></td><td>{{ i.type.name }}</td><td class="num">{{ money(i.value) }}</td>
          <td><span class="pill" :class="STATUS_COLOR[i.status]">{{ i.status_label }}</span></td>
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
        <thead><tr><th>العهدة</th><th>النوع</th><th>الشركة</th><th>الموظف</th><th>القيمة</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="i in filtered" :key="i.id">
          <td><b>{{ i.ident }}</b><span v-if="i.has_secret" class="tiny muted"><Icon name="key" :size="11" /> يحمل سرًا</span></td>
          <td>{{ i.type.name }}</td><td>{{ i.company?.name }}</td><td>{{ i.employee?.name || '—' }}</td><td class="num">{{ money(i.value) }}</td>
          <td><span class="pill" :class="STATUS_COLOR[i.status]">{{ i.status_label }}</span></td>
          <td><button class="btn ghost xs" @click="openId = i.id">فتح</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <template v-else-if="tab === 'claims'">
    <div class="card">
      <h3>تسويات العهد المالية <span class="sub">يقررها المدير المالي: اعتماد، أو تحويل الباقي لخصم من الراتب، أو إرجاع للتصحيح</span></h3>
      <div v-if="!claims.length" class="empty">لا تسويات</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>الموظف</th><th>العهدة</th><th>القيمة</th><th>الفواتير</th><th>المصروف</th><th>الباقي</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="c in claims" :key="c.id">
          <td>{{ c.employee?.name }}</td><td>{{ c.item?.ident }}</td><td class="num">{{ money(c.item?.value) }}</td>
          <td class="small">{{ c.invoices.map((x) => `${x.name} (${money(x.amount)})`).join('، ') }}</td>
          <td class="num">{{ money(c.claimed) }}</td><td class="num">{{ money(c.remainder) }}</td>
          <td><span class="pill" :class="CLAIM_COLOR[c.status]">{{ c.status_label }}</span><div v-if="c.decision_note" class="tiny muted">{{ c.decision_note }}</div></td>
          <td><button v-if="c.status === 'pending_finance' && auth.has('fin', 'super')" class="btn okb xs" @click="decideTarget = c">قرار</button></td>
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
        <option value="settle">اعتماد التسوية وإغلاق العهدة</option>
        <option value="to_salary">اعتماد وتحويل الباقي ({{ money(decideTarget.remainder) }}) لخصم من الراتب</option>
        <option value="return">إرجاع للموظف للتصحيح</option>
      </select>
    </div>
    <div class="fg"><label>ملاحظة</label><input v-model="decisionNote" class="in" /></div>
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
import { fmtD, money, norm } from '../utils'

const auth = useAuth()
const ui = useUi()
const STATUS_LABEL = { available: 'متاحة', pending_ack: 'بانتظار الإقرار', in_use: 'قيد الاستخدام', return_requested: 'مطلوب استردادها', inspecting: 'قيد الفحص', lost: 'مفقودة / تالفة' }
const STATUS_COLOR = { available: 'gray', pending_ack: 'warn', in_use: 'ok', return_requested: 'warn', inspecting: 'info', lost: 'bad' }
const CLAIM_COLOR = { pending_finance: 'warn', settled: 'ok', to_salary: 'info', returned: 'bad' }

const tabs = computed(() => [auth.emp && ['mine', 'عهدي'], auth.isApprover && ['items', 'كل العهد'], auth.isApprover && ['claims', 'تسويات العهد المالية'], auth.isAdmin && ['types', 'أنواع العهد']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'items')
const mine = ref([]); const myClaims = ref([]); const items = ref([]); const claims = ref([]); const types = ref([])
const statusFilter = ref(''); const q = ref('')
const openId = ref(null); const form = ref(null); const typeForm = ref(null); const decideTarget = ref(null)
const decision = ref('settle'); const decisionNote = ref(''); const busy = ref(false)
const typeFieldsText = ref('')

const filtered = computed(() => items.value.filter((i) => (!statusFilter.value || i.status === statusFilter.value) && (!q.value || norm(i.ident + ' ' + (i.employee?.name || '')).includes(norm(q.value)))))
const formType = computed(() => types.value.find((t) => t.id === form.value?.custody_type_id))
watch(typeForm, (t) => { if (t) typeFieldsText.value = (t.fields || []).join('\n') })
watch(() => form.value?.custody_type_id, () => { if (form.value && !form.value.id) form.value.fields = {} })

async function load() {
  if (!types.value.length) types.value = await api.get('/custody/types')
  if (tab.value === 'mine') [mine.value, myClaims.value] = await Promise.all([api.get('/custody/mine'), api.get('/custody/claims/mine')])
  else if (tab.value === 'items') items.value = await api.get('/custody', { params: { company_id: ui.company || undefined } })
  else if (tab.value === 'claims') claims.value = await api.get('/custody/claims')
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
  busy.value = true
  try { await api.post(`/custody/claims/${decideTarget.value.id}/decide`, { decision: decision.value, note: decisionNote.value || null }); ui.toast('تم', 'ok'); decideTarget.value = null; decisionNote.value = ''; load() } finally { busy.value = false }
}
onMounted(load)
</script>
