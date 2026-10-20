<template>
  <div class="page-h">
    <div><h1>{{ t('الشركات والتصنيفات') }}</h1><p>لكل شركة هويتها (الشعار والألوان) التي يراها موظفوها · وتصنيفها (تأسيس / تمكين / أثر) الذي تنزل مزاياه تلقائيًا على موظفيها — اللائحة §47–52</p></div>
    <div class="acts"><button class="btn pri sm" @click="edit({})"><Icon name="plus" :size="16" /> شركة</button></div>
  </div>
  <div class="tabs"><button :class="{ on: tab === 'c' }" @click="tab = 'c'">الشركات</button><button :class="{ on: tab === 't' }" @click="tab = 't'">فئات التصنيف ومزاياها</button></div>

  <div v-if="tab === 'c'" class="card">
    <DataTable :rows="companies" :columns="columns" :selectable="auth.has('super')" store-key="companies" export-name="companies" search-placeholder="ابحث بالرمز أو الاسم أو الموقع…" @row-click="view = $event">
      <template #bulk="{ selected, clear }">
        <button class="btn sec xs" @click="bulkActive(selected, true, clear)"><Icon name="check-circle" :size="14" /> تفعيل</button>
        <button class="btn sec xs" @click="bulkActive(selected, false, clear)"><Icon name="ban" :size="14" /> إيقاف</button>
        <select class="in xs" style="width:auto;min-height:28px" @change="bulkTier(selected, $event, clear)"><option value="">تغيير التصنيف…</option><option v-for="tr in tiers" :key="tr.id" :value="tr.id">{{ tr.name }}</option></select>
      </template>
      <template #cell-code="{ row: c }"><b>{{ c.code }}</b><span v-if="c.is_holding" class="tiny muted">القابضة</span></template>
      <template #cell-name="{ row: c }">
        <div class="row" style="gap:8px;flex-wrap:nowrap;align-items:center">
          <img v-if="c.logo_url" :src="c.logo_url" :alt="c.name" class="co-thumb" />
          <span v-else class="co-thumb co-none" :style="{ background: c.color || '#67718F' }">{{ c.code?.slice(0, 2) }}</span>
          <div style="min-width:0">{{ c.name }}<span class="tiny muted">{{ c.name_en }}</span><span v-if="c.color" class="tiny muted" dir="ltr"><i class="sw" :style="{ background: c.color }" /><i v-if="c.color2" class="sw" :style="{ background: c.color2 }" /></span></div>
        </div>
        <a v-if="c.website" :href="c.website" target="_blank" rel="noopener" class="site" dir="ltr" title="فتح موقع الشركة" @click.stop>{{ c.website.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '') }} <Icon name="external" :size="12" /></a>
      </template>
      <template #cell-ceo="{ row: c }">{{ c.ceo?.name || '—' }}</template>
      <template #cell-tier="{ row: c }"><span v-if="c.tier" class="pill" :style="{ background: c.tier.color + '22', color: c.tier.color }">{{ c.tier.name }}</span><span v-else class="pill warn">بلا تصنيف</span></template>
      <template #cell-status="{ row: c }"><span class="pill" :class="c.active ? 'ok' : 'gray'">{{ c.active ? 'نشطة' : 'موقوفة' }}</span></template>
      <template #cell-actions="{ row: c }">
        <div class="row" style="gap:2px;flex-wrap:nowrap;justify-content:flex-end" @click.stop>
          <a v-if="c.website" class="btn ghost xs" :href="c.website" target="_blank" rel="noopener" title="فتح الموقع"><Icon name="external" :size="15" /></a>
          <button class="btn ghost xs" title="عرض" @click="view = c"><Icon name="eye" :size="15" /></button>
          <button class="btn ghost xs" title="تعديل" @click="edit(c)"><Icon name="edit" :size="15" /></button>
          <ActionMenu :items="menu(c)" />
        </div>
      </template>
    </DataTable>
  </div>

  <Modal v-if="view" :title="view.name" @close="view = null">
    <div class="tw"><table class="kv">
      <tr><th>الرمز</th><td>{{ view.code }}{{ view.is_holding ? ' · الشركة القابضة' : '' }}</td></tr>
      <tr><th>الاسم بالإنجليزية</th><td>{{ view.name_en || '—' }}</td></tr>
      <tr><th>الموقع الإلكتروني</th><td><a v-if="view.website" :href="view.website" target="_blank" rel="noopener" dir="ltr">{{ view.website }} <Icon name="external" :size="12" /></a><span v-else>—</span></td></tr>
      <tr><th>الرئيس التنفيذي</th><td>{{ view.ceo?.name || '—' }}</td></tr>
      <tr><th>التصنيف</th><td><span v-if="view.tier" class="pill" :style="{ background: view.tier.color + '22', color: view.tier.color }">{{ view.tier.name }}</span><span v-else>—</span></td></tr>
      <tr><th>الهوية</th><td><div class="row" style="align-items:center;gap:10px"><img v-if="view.logo_url" :src="view.logo_url" :alt="view.name" style="height:44px;max-width:160px;object-fit:contain;border-radius:8px;padding:3px 6px" /><span v-else class="muted">بلا شعار</span><span v-if="view.color" dir="ltr" class="tiny"><i class="sw" :style="{ background: view.color }" /> {{ view.color }} <i v-if="view.color2" class="sw" :style="{ background: view.color2 }" /> {{ view.color2 || '' }}</span></div></td></tr>
      <tr><th>عدد الموظفين</th><td>{{ view.employees_count }}</td></tr>
      <tr><th>السجل التجاري</th><td>{{ view.legal_no || '—' }}</td></tr>
      <tr><th>الرقم الضريبي</th><td>{{ view.tax_no || '—' }}</td></tr>
      <tr><th>العملة · المنطقة الزمنية</th><td>{{ view.currency }} · {{ view.timezone }}</td></tr>
      <tr><th>معرّف أودو</th><td>{{ view.odoo_id || '—' }}</td></tr>
      <tr><th>الحالة</th><td>{{ view.active ? 'نشطة' : 'موقوفة' }}</td></tr>
    </table></div>
    <template #foot>
      <RouterLink class="btn ghost" :to="{ path: '/employees' }" @click="ui.company = view.id">موظفو الشركة</RouterLink>
      <button class="btn sec" @click="view = null">إغلاق</button>
      <button class="btn pri" @click="edit(view); view = null"><Icon name="edit" :size="15" /> تعديل</button>
    </template>
  </Modal>

  <div v-else class="card">
    <h3>فئات التصنيف ومصفوفة المزايا <span class="sub">اللائحة §47: تأسيس (أقل من 100% من الخطة) · تمكين (100%–110%) · أثر (110% فأكثر) — التعديل صلاحية الرئيس التنفيذي للمجموعة وكل حفظ ينشئ نسخة جديدة مع السبب</span></h3>
    <div class="notice info small">التصنيف يؤثر في المزايا والحوافز فقط (§51)، ولا يغيّر الحقوق النظامية. هوية الشركة (الشعار والألوان) تُضبط من بطاقة الشركة نفسها وتظهر لموظفيها في كل الصفحات.</div>
    <div v-if="ui.company" class="notice warn small">تُعرض الفئة الخاصة بالشركة المختارة في الأعلى فقط. لعرض كل الفئات اختر «كل الشركات».</div>
    <div v-if="!auth.has('super')" class="notice warn small">إضافة الفئات وحذفها وتعديل مزاياها صلاحية الرئيس التنفيذي للمجموعة (اللائحة §49) — ادخل بحسابه لتظهر أدوات التعديل والحذف.</div>
    <div v-if="auth.has('super') && (selTiers.length || selBen.length)" class="row mb" style="gap:8px;padding:8px 10px;background:var(--badbg);border-radius:10px;align-items:center">
      <b class="small">المحدد: {{ selTiers.length }} فئة · {{ selBen.length }} بند</b>
      <button v-if="selTiers.length" class="btn dan xs" @click="openBulkTier"><Icon name="trash" :size="13" /> حذف الفئات المحددة</button>
      <button v-if="selBen.length" class="btn dan xs" @click="removeBenefits"><Icon name="trash" :size="13" /> حذف البنود المحددة من كل الفئات</button>
      <button class="btn ghost xs" @click="selTiers = []; selBen = []">إلغاء التحديد</button>
    </div>
    <div v-dt class="tw"><table>
      <thead><tr><th><input v-if="auth.has('super')" type="checkbox" title="تحديد كل البنود" :checked="selBen.length === benefitKeys.length && benefitKeys.length > 0" @change="selBen = $event.target.checked ? [...benefitKeys] : []" /> البند</th><th v-for="tr in tiers" :key="tr.id" :style="{ color: tr.color }">
        <div class="row" style="gap:4px;flex-wrap:nowrap;align-items:center">
          <input v-if="auth.has('super')" v-model="selTiers" type="checkbox" :value="tr.id" title="تحديد للحذف المتعدد" />
          <input v-if="auth.has('super')" type="color" :value="tr.color || '#1B82C3'" class="cp" :title="'لون الفئة'" @change="tierColor(tr, $event.target.value)" />
          <span style="min-width:0">{{ tr.name }} <span class="tiny muted">v{{ tr.version }} · {{ tr.companies_count }} شركات</span></span>
          <template v-if="auth.has('super')"><button class="btn ghost xs" title="تعديل الاسم" @click="renameTier(tr)"><Icon name="edit" :size="13" /></button><button class="btn ghost xs" title="حذف الفئة" @click="removeTier(tr)"><Icon name="trash" :size="13" /></button></template>
        </div>
      </th></tr></thead>
      <tbody><tr v-for="k in benefitKeys" :key="k">
        <td><div class="row" style="gap:6px;flex-wrap:nowrap;align-items:flex-start"><input v-if="auth.has('super')" v-model="selBen" type="checkbox" :value="k" style="margin-top:4px" /><div><b>{{ BEN[k] || k }}</b><span class="tiny muted">{{ k }}</span></div><button v-if="auth.has('super')" class="btn ghost xs" style="margin-inline-start:auto" title="حذف البند من كل الفئات" @click="selBen = [k]; removeBenefits()"><Icon name="trash" :size="12" /></button></div></td>
        <td v-for="tr in tiers" :key="tr.id"><input v-model="tr.benefits[k]" class="in" style="min-height:32px;padding:3px 8px" :disabled="!auth.has('super')" /></td>
      </tr></tbody>
    </table></div>
    <div v-if="auth.has('super')" class="row mt">
      <input v-model="reason" class="in" style="max-width:340px" placeholder="سبب التعديل (إلزامي)" />
      <button class="btn pri sm" @click="saveTiers">حفظ نسخة جديدة</button>
      <span class="spacer" />
      <button class="btn sec sm" @click="newBenefit"><Icon name="plus" :size="16" /> بند مزايا جديد</button>
      <button class="btn sec sm" @click="newTier"><Icon name="plus" :size="16" /> فئة جديدة</button>
    </div>
  </div>

  <Modal v-if="delTier" :title="delTier.list.length > 1 ? `حذف ${delTier.list.length} فئات` : `حذف فئة «${delTier.list[0].name}»`" @close="delTier = null">
    <ul class="small"><li v-for="x in delTier.list" :key="x.id"><b>{{ x.name }}</b> — {{ x.companies_count ? `مرتبطة بـ ${x.companies_count} شركة` : 'لا شركات مرتبطة' }}</li></ul>
    <p v-if="delCompanies" class="small">اختر الفئة التي تنتقل إليها الشركات المرتبطة ({{ delCompanies }} شركة)، وستتحدث مزايا موظفيها فورًا.</p>
    <div v-if="delCompanies" class="fg"><label>نقل الشركات إلى *</label><select v-model="delTarget" class="in"><option :value="null">—</option><option v-for="tr in moveTargets" :key="tr.id" :value="tr.id">{{ tr.name }}</option></select></div>
    <div v-if="delCompanies && !moveTargets.length" class="notice bad small">لا توجد فئة أخرى تنتقل إليها الشركات — أنشئ فئة أولًا أو لا تحذف كل الفئات.</div>
    <div class="fg"><label>سبب الحذف *</label><input v-model="delReason" class="in" placeholder="مثال: إلغاء الباقات القديمة بعد اعتماد اللائحة" /></div>
    <template #foot><button class="btn sec" @click="delTier = null">إلغاء</button><button class="btn dan" :class="{ loading: saving }" :disabled="saving || (delCompanies && !delTarget) || !delReason.trim()" @click="confirmRemoveTier">حذف</button></template>
  </Modal>

  <Modal v-if="form" :title="form.id ? 'تعديل شركة' : 'شركة جديدة'" @close="form = null">
    <div class="fr">
      <div class="fg"><label>الرمز *</label><input v-model="form.code" class="in" /></div>
      <div class="fg"><label>الاسم بالعربية *</label><input v-model="form.name" class="in" /></div>
      <div class="fg"><label>الاسم بالإنجليزية</label><input v-model="form.name_en" class="in" /></div>
      <div class="fg"><label>السجل التجاري</label><input v-model="form.legal_no" class="in" /></div>
      <div class="fg"><label>الرقم الضريبي</label><input v-model="form.tax_no" class="in" /></div>
      <div class="fg"><label>الموقع الإلكتروني</label><input v-model="form.website" class="in" dir="ltr" type="url" placeholder="https://impactv.sa" /></div>
      <div class="fg"><label>التصنيف <span class="tiny muted">(الشركة الجديدة تبدأ في الفئة الأولى — §49)</span></label><select v-model="form.tier_id" class="in"><option v-for="tr in tiers" :key="tr.id" :value="tr.id">{{ tr.name }}</option></select></div>
      <div class="fg"><label>جدول الدوام الافتراضي <span class="tiny muted">(يُسند للموظف الجديد تلقائيًا)</span></label><select v-model="form.default_schedule_id" class="in"><option :value="null">—</option><option v-for="sc in auth.meta?.schedules || []" :key="sc.id" :value="sc.id">{{ sc.name }}</option></select></div>
      <div class="fg"><label>معرّف أودو</label><input v-model="form.odoo_id" class="in" /></div>
      <div class="fg"><label>الرئيس التنفيذي</label><SearchSelect v-model="form.ceo_employee_id" :options="empOptions" placeholder="ابحث عن موظف" /></div>
      <div class="fg"><label>العملة</label><input v-model="form.currency" class="in" maxlength="3" /></div>
      <div class="fg"><label>المنطقة الزمنية</label><input v-model="form.timezone" class="in" style="direction:ltr" /></div>
      <div class="fg"><label>قاعدة الاحتساب التناسبي</label><select v-model="form.prorate_rule" class="in"><option value="actual">أيام الشهر الفعلية</option><option value="30">قاعدة الثلاثين يومًا</option></select></div>
    </div>
    <label class="chk"><input v-model="form.active" type="checkbox" /> نشطة</label>
    <h4 style="margin:14px 0 6px">هوية الشركة <span class="tiny muted">تظهر لموظفي الشركة في رأس الصفحات والخطابات الرسمية والتطبيق</span></h4>
    <div class="fr">
      <div class="fg"><label>اللون الأساسي</label><div class="row" style="gap:6px;flex-wrap:nowrap"><input type="color" class="cp" :value="form.color || '#1A2363'" @input="form.color = $event.target.value" /><input v-model.trim="form.color" class="in" dir="ltr" placeholder="#1A2363" maxlength="7" /></div></div>
      <div class="fg"><label>اللون الثانوي</label><div class="row" style="gap:6px;flex-wrap:nowrap"><input type="color" class="cp" :value="form.color2 || '#4FCAF2'" @input="form.color2 = $event.target.value" /><input v-model.trim="form.color2" class="in" dir="ltr" placeholder="#4FCAF2" maxlength="7" /></div></div>
      <div class="fg"><label>الشعار <span class="tiny muted">PNG/SVG بخلفية شفافة · حتى 2MB</span></label>
        <div class="row" style="gap:8px;align-items:center;flex-wrap:nowrap">
          <img v-if="logoPreview || form.logo_url" :src="logoPreview || form.logo_url" alt="" style="height:44px;max-width:150px;object-fit:contain" />
          <input ref="logoInput" type="file" accept=".png,.jpg,.jpeg,.svg,.webp" style="display:none" @change="pickLogo" />
          <button type="button" class="btn sec xs" @click="$refs.logoInput.click()"><Icon name="upload" :size="14" /> {{ form.logo_url || logoFile ? 'تغيير' : 'رفع شعار' }}</button>
          <button v-if="form.id && form.logo_url && !logoFile" type="button" class="btn ghost xs" title="إزالة الشعار" @click="removeLogo"><Icon name="trash" :size="14" /></button>
          <button v-if="logoPreview || form.logo_url" type="button" class="btn ghost xs" title="يقترح اللونين الأساسي والثانوي من ألوان الشعار" @click="suggestColors"><Icon name="palette" :size="14" /> ألوان من الشعار</button>
        </div>
      </div>
    </div>
    <div class="brand-preview" :style="{ background: `linear-gradient(90deg, ${form.color || '#1A2363'}, ${form.color2 || '#4FCAF2'})` }">
      <img v-if="logoPreview || form.logo_url" :src="logoPreview || form.logo_url" alt="" />
      <span><b>{{ form.name || 'اسم الشركة' }}</b><small>HC — إدارة رأس المال البشري · مجموعة وادي الأثر المالية</small></span>
    </div>
    <template #foot><button class="btn sec" @click="form = null">إلغاء</button><button class="btn pri" :class="{ loading: saving }" :disabled="saving" @click="saveCompany">حفظ</button></template>
  </Modal>
</template>

<script setup>
import { logoColors } from '../lib/logoColors'
import { alertBox, confirmBox, promptBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import ActionMenu from '../components/ActionMenu.vue'
import DataTable from '../components/DataTable.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const tab = ref('c')
const companies = ref([])
const tiers = ref([])
const form = ref(null)
const reason = ref('')
const view = ref(null)
const columns = [
  { key: 'code', label: 'الرمز' },
  { key: 'name', label: 'الشركة', value: (c) => `${c.name} ${c.name_en || ''} ${c.website || ''}`, exportValue: (c) => c.name },
  { key: 'name_en', label: 'الاسم بالإنجليزية', hidden: true },
  { key: 'website', label: 'الموقع الإلكتروني', hidden: true },
  { key: 'ceo', label: 'الرئيس التنفيذي', value: (c) => c.ceo?.name },
  { key: 'tier', label: 'التصنيف', value: (c) => c.tier?.name },
  { key: 'employees_count', label: 'الموظفون' },
  { key: 'legal_no', label: 'السجل التجاري' },
  { key: 'tax_no', label: 'الرقم الضريبي', hidden: true },
  { key: 'odoo_id', label: 'أودو', hidden: true },
  { key: 'status', label: 'الحالة', value: (c) => (c.active ? 'نشطة' : 'موقوفة') },
  { key: 'actions', label: '', sortable: false, search: false, export: false, class: 'dt-actions' },
]
const bodyOf = (c, patch = {}) => ({ code: c.code, name: c.name, name_en: c.name_en, legal_no: c.legal_no, tax_no: c.tax_no, website: c.website || null, tier_id: c.tier_id, color: c.color || null, color2: c.color2 || null, default_schedule_id: c.default_schedule_id || null, odoo_id: c.odoo_id, prorate_rule: c.prorate_rule || 'actual', active: !!c.active, ceo_employee_id: c.ceo_employee_id || null, currency: c.currency || 'SAR', timezone: c.timezone || 'Asia/Riyadh', ...patch })
function menu(c) {
  return [
    { label: 'عرض البيانات', icon: 'eye', action: () => (view.value = c) },
    { label: 'تعديل', icon: 'edit', action: () => edit(c) },
    { label: 'فتح موقع الشركة', icon: 'external', hidden: !c.website, action: () => window.open(c.website, '_blank', 'noopener') },
    { divider: true },
    { label: c.active ? 'إيقاف الشركة' : 'تفعيل الشركة', icon: c.active ? 'ban' : 'check-circle', disabled: c.is_holding, action: () => setActive(c, !c.active) },
    { label: 'حذف الشركة', icon: 'trash', danger: true, hidden: !auth.has('super'), disabled: c.is_holding || c.employees_count > 0, action: () => remove(c) },
  ]
}
async function setActive(c, on) {
  if (!on && !await confirmBox(`إيقاف شركة «${c.name}»؟ تبقى بياناتها وموظفوها كما هم، ولا تظهر في قوائم الاختيار الجديدة.`)) return
  try { await api.put(`/companies/${c.id}`, bodyOf(c, { active: on })); ui.toast(on ? 'فُعّلت الشركة' : 'أُوقفت الشركة', 'ok'); load(); auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
async function remove(c) {
  if (!await confirmBox(`حذف شركة «${c.name}» نهائيًا؟`, { title: 'حذف الشركة', confirmText: 'حذف' })) return
  try { await api.delete(`/companies/${c.id}`); ui.toast('حُذفت الشركة', 'ok'); load(); auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
async function bulkActive(rows, on, clear) {
  const list = rows.filter((c) => !c.is_holding)
  if (!list.length || !await confirmBox(`${on ? 'تفعيل' : 'إيقاف'} ${list.length} شركة؟`, { danger: !on })) return
  for (const c of list) await api.put(`/companies/${c.id}`, bodyOf(c, { active: on }))
  ui.toast('تم', 'ok'); clear(); load(); auth.loadMeta()
}
async function bulkTier(rows, ev, clear) {
  const tierId = +ev.target.value; ev.target.value = ''
  const tr = tiers.value.find((x) => x.id === tierId); if (!tr) return
  if (!await confirmBox(`تصنيف ${rows.length} شركة في «${tr.name}»؟ تتحدث أرصدة وبدلات موظفيها فورًا.`)) return
  for (const c of rows) await api.put(`/companies/${c.id}`, bodyOf(c, { tier_id: tierId }))
  ui.toast('حُدّث التصنيف', 'ok'); clear(); load(); auth.loadMeta()
}
const BEN = computed(() => auth.meta?.benefit_labels || {})
const ORDER = ['annualLeave', 'leaveUnit', 'eidExtraDays', 'bridgeDay', 'sick', 'emergency', 'maternity', 'marriage', 'bereavement', 'siblingBereavement', 'newborn', 'hajj', 'unpaidMax', 'carryOverMax',
  'housingPct', 'transportPct', 'transportMin', 'transport', 'comm', 'commMax', 'carAllowance2', 'carAllowance4', 'carAllowanceDaily', 'weeklyHours', 'workDays', 'flexMinutes', 'remote',
  'devBudget', 'devDays', 'programsPerMonth', 'ticketCap', 'ticketFamily', 'bonusMaxMonths', 'hotelCap', 'perDiemCeo', 'perDiemDir', 'perDiemMgr', 'perDiemSpec', 'perDiemStaff',
  'insuranceCeo', 'insuranceLeaders', 'insuranceMgrs', 'insuranceClass', 'dependents']
const benefitKeys = computed(() => { const ks = Object.keys(tiers.value[0]?.benefits || {}); return [...ORDER.filter((k) => ks.includes(k)), ...ks.filter((k) => !ORDER.includes(k))] })
async function load() { [companies.value, tiers.value] = await Promise.all([api.get('/companies'), api.get('/tiers')]) }
const empOptions = ref([])
const edit = async (c) => {
  logoFile.value = null; logoPreview.value = null
  if (!empOptions.value.length) empOptions.value = (await api.get('/employees/options')).map((e) => ({ value: e.id, label: e.name, sub: e.emp_no }))
  form.value = { active: true, prorate_rule: 'actual', currency: 'SAR', timezone: 'Asia/Riyadh', tier_id: tiers.value.find((x) => x.is_default)?.id || tiers.value[0]?.id, ...c }
}
async function newTier() {
  const name = await promptBox('اسم الفئة الجديدة', { multiline: false, confirmText: 'إضافة' })
  if (!name) return
  const copy = tiers.value[tiers.value.length - 1]
  await api.post('/tiers', { name, copy_from: copy?.id, reason: 'إنشاء فئة تصنيف جديدة' })
  ui.toast(`أُنشئت الفئة بنسخ مزايا «${copy?.name}» — عدّل القيم ثم احفظ`, 'ok', 5000)
  load()
}
async function renameTier(tr) {
  const name = await promptBox('اسم الفئة', { value: tr.name, multiline: false, confirmText: 'حفظ' })
  if (!name || name === tr.name) return
  try { await api.put(`/tiers/${tr.id}`, { name, reason: `تغيير اسم الفئة من «${tr.name}»` }); ui.toast('حُفظ الاسم', 'ok'); load(); auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
async function tierColor(tr, color) {
  try { await api.put(`/tiers/${tr.id}`, { color, reason: 'تغيير لون الفئة' }); tr.color = color; auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
const delTier = ref(null); const delTarget = ref(null); const delReason = ref('')
const selTiers = ref([]); const selBen = ref([]); const allTiers = ref([])
const delCompanies = computed(() => (delTier.value?.list || []).reduce((s, x) => s + (x.companies_count || 0), 0))
const moveTargets = computed(() => allTiers.value.filter((x) => !(delTier.value?.list || []).some((d) => d.id === x.id)))
async function openDelete(list) {
  allTiers.value = await api.get('/tiers', { allCompanies: true }) // reassignment targets are every tier, whatever company is selected
  delTier.value = { list }; delTarget.value = null; delReason.value = ''
}
function removeTier(tr) { openDelete([tr]) }
function openBulkTier() { openDelete(tiers.value.filter((t) => selTiers.value.includes(t.id))) }
async function confirmRemoveTier() {
  saving.value = true
  let moved = 0; let done = 0
  try {
    for (const t of delTier.value.list) {
      const r = await api.delete(`/tiers/${t.id}`, { data: { move_to: t.companies_count ? delTarget.value : null, reason: delReason.value }, allCompanies: true })
      moved += r.moved || 0; done++
    }
    ui.toast(`حُذفت ${done} فئة${moved ? ` ونُقلت ${moved} شركة` : ''}`, 'ok'); delTier.value = null; selTiers.value = []; load(); auth.loadMeta()
  } catch (e) { alertBox(`${e.message}${done ? ` (حُذفت ${done} قبل الخطأ)` : ''}`, { icon: 'error' }); load() } finally { saving.value = false }
}
/** Removes benefit items from every tier (and their custom labels), with a reason — a new version of each tier. */
async function removeBenefits() {
  const keys = [...selBen.value]; if (!keys.length) return
  const why = await promptBox(`حذف ${keys.length} بند من كل الفئات؟ اكتب السبب`, { text: keys.map((k) => BEN.value[k] || k).join('، '), multiline: false, confirmText: 'حذف' })
  if (!why) return
  try {
    const all = await api.get('/tiers', { allCompanies: true })
    for (const tr of all) { const b = { ...tr.benefits }; keys.forEach((k) => delete b[k]); await api.put(`/tiers/${tr.id}`, { benefits: b, reason: why }, { allCompanies: true }) }
    const labels = { ...(auth.meta?.benefit_labels || {}) }; let custom = false
    keys.forEach((k) => { if (k.startsWith('custom')) { delete labels[k]; custom = true } })
    if (custom) await api.put('/settings', { key: 'benefitLabels', value: labels, reason: why })
    ui.toast(`حُذف ${keys.length} بند من كل الفئات`, 'ok'); selBen.value = []; await Promise.all([auth.loadMeta(), load()])
  } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
// logo: picked locally for preview, uploaded right after the company is saved (needs its id)
const logoInput = ref(null); const logoFile = ref(null); const logoPreview = ref(null); const saving = ref(false)
function pickLogo(ev) {
  const f = ev.target.files?.[0]; if (!f) return
  if (f.size > 2 * 1024 * 1024) { ui.toast('حجم الشعار يتجاوز 2MB', 'warn'); ev.target.value = ''; return }
  logoFile.value = f; logoPreview.value = URL.createObjectURL(f)
  if (!form.value.color && !form.value.color2) suggestColors() // a new company gets colours matching its logo right away
}
async function suggestColors() {
  try {
    const c = await logoColors(logoPreview.value || form.value.logo_url)
    if (!c) return ui.toast('تعذر استخراج ألوان من الشعار', 'warn')
    form.value.color = c.color; form.value.color2 = c.color2
    ui.toast('اقتُرح لونان من الشعار — يمكنك تعديلهما', 'ok')
  } catch { ui.toast('تعذر قراءة الشعار', 'warn') }
}
async function removeLogo() {
  if (!await confirmBox('إزالة شعار الشركة؟')) return
  try { const c = await api.delete(`/companies/${form.value.id}/logo`); form.value.logo_url = c.logo_url; load(); auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
async function newBenefit() {
  // Arabic name only: the internal code is generated, and the item is saved to every package right away
  const label = ((await promptBox('اسم بند المزايا الجديد', { text: 'مثال: بدل نادي رياضي', multiline: false, confirmText: 'التالي' })) || '').trim()
  if (!label) return
  const labels = { ...(auth.meta?.benefit_labels || {}) }
  if (Object.values(labels).some((l) => String(l).trim() === label)) return ui.toast('يوجد بند بهذا الاسم', 'warn')
  const raw = ((await promptBox(`القيمة الافتراضية لـ«${label}» في كل الباقات`, { text: 'رقم (مبلغ أو عدد أيام)، ويمكن تعديله لكل باقة لاحقًا', multiline: false, required: false, confirmText: 'إضافة البند' })) ?? '0').trim()
  const val = raw === '' ? 0 : Number(raw.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
  if (Number.isNaN(val)) return ui.toast('القيمة يجب أن تكون رقمًا', 'warn')
  let key = 'custom' + Date.now().toString(36)
  while (benefitKeys.value.includes(key)) key += '1'
  const reason = `بند مزايا جديد: ${label}`
  await api.put('/settings', { key: 'benefitLabels', value: { ...labels, [key]: label }, reason })
  for (const tr of tiers.value) await api.put(`/tiers/${tr.id}`, { benefits: { ...tr.benefits, [key]: val }, reason })
  await Promise.all([auth.loadMeta(), load()])
  ui.toast(`أُضيف «${label}» إلى كل الباقات وحُفظ — عدّل قيمته لأي باقة ثم «حفظ نسخة جديدة»`, 'ok', 6000)
}
async function saveCompany() {
  const f = form.value
  const body = bodyOf(f)
  for (const k of ['color', 'color2']) if (body[k] && !/^#[0-9A-Fa-f]{6}$/.test(body[k])) return ui.toast('اللون يجب أن يكون بصيغة #RRGGBB', 'warn')
  saving.value = true
  try {
    const saved = f.id ? await api.put(`/companies/${f.id}`, body) : await api.post('/companies', body)
    if (logoFile.value) {
      const fd = new FormData(); fd.append('logo', logoFile.value)
      await api.post(`/companies/${saved.id}/logo`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    }
    ui.toast('تم الحفظ', 'ok'); form.value = null; logoFile.value = null; logoPreview.value = null; load(); auth.loadMeta()
  } catch (e) { if (e?.message) alertBox(e.message, { icon: 'error' }) } finally { saving.value = false }
}
async function saveTiers() {
  if (!reason.value.trim()) return ui.toast('سبب التعديل إلزامي', 'warn')
  const cast = (v) => (v === 'true' ? true : v === 'false' ? false : v !== '' && !isNaN(v) ? Number(v) : v)
  for (const tr of tiers.value) await api.put(`/tiers/${tr.id}`, { benefits: Object.fromEntries(Object.entries(tr.benefits).map(([k, v]) => [k, cast(v)])), reason: reason.value })
  ui.toast('حُفظت الباقات', 'ok'); reason.value = ''; load()
}
onMounted(load)
</script>

<style scoped>
.site { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; margin-top: 2px; }
.site:hover { text-decoration: underline; }
.co-thumb { height: 34px; width: 56px; object-fit: contain; flex: 0 0 auto; }
.co-none { display: inline-flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 12px; border: 0; }
.sw { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-inline-end: 3px; vertical-align: middle; }
.cp { width: 34px; height: 30px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: none; cursor: pointer; flex: 0 0 auto; }
.brand-preview { margin-top: 12px; border-radius: 10px; padding: 10px 14px; color: #fff; display: flex; align-items: center; gap: 12px; }
.brand-preview img { height: 38px; max-width: 150px; object-fit: contain; filter: brightness(0) invert(1); }
.brand-preview small { display: block; font-size: 10.5px; opacity: .85; font-weight: 400; }
</style>
