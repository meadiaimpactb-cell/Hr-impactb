<template>
  <div class="page-h">
    <div><h1>{{ t('البيانات الأساسية') }}</h1><p>أنواع الإجازات · كتالوج الوظائف · جداول الدوام · مواقع التحضير · القوائم العامة (أنواع الخطابات، تصحيح الحضور، المخالفات…) — تُدار دون برمجة</p></div>
    <div class="acts">
      <button v-if="kind === 'lists'" class="btn sec sm" @click="groupForm = { code: '', name: '', name_en: '', meta_fields: [] }"><Icon name="plus" :size="16" /> قائمة جديدة</button>
      <button class="btn pri sm" @click="kind === 'lists' ? openItem({}) : open({})"><Icon name="plus" :size="16" /> إضافة</button>
    </div>
  </div>
  <div class="tabs"><button v-for="(l, k) in KINDS" :key="k" :class="{ on: kind === k }" @click="kind = k; load()">{{ l }}</button></div>

  <template v-if="kind === 'lists'">
    <div class="row mb">
      <select v-model="group" class="in" style="width:auto" @change="load"><option v-for="g in groups" :key="g.code" :value="g.code">{{ g.name }} ({{ g.items_count }})</option></select>
      <button v-if="curGroup" class="btn ghost xs" @click="groupForm = JSON.parse(JSON.stringify(curGroup))"><Icon name="edit" :size="14" /> تعديل القائمة</button>
      <span class="tiny muted" style="direction:ltr">{{ group }}</span>
    </div>
    <div class="card">
      <div v-if="loading"><div class="skel" v-for="i in 4" :key="i" /></div>
      <div v-else-if="!rows.length" class="empty">لا عناصر</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>الرمز</th><th>الاسم</th><th>بالإنجليزية</th><th v-for="mf in curGroup?.meta_fields || []" :key="mf.key">{{ mf.label }}</th><th>الترتيب</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="r in rows" :key="r.id">
          <td class="num small" style="direction:ltr">{{ r.code }}</td><td><b>{{ r.name }}</b></td><td>{{ r.name_en }}</td>
          <td v-for="mf in curGroup?.meta_fields || []" :key="mf.key">{{ mf.type === 'bool' ? (r.meta?.[mf.key] ? '✓' : '—') : (r.meta?.[mf.key] ?? '—') }}</td>
          <td>{{ r.sort }}</td><td><span class="pill" :class="r.active ? 'ok' : 'gray'">{{ r.active ? 'مفعّل' : 'معطّل' }}</span></td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><button class="btn ghost xs" @click="openItem(r)"><Icon name="edit" :size="15" /></button><button class="btn ghost xs bad" @click="removeItem(r)"><Icon name="trash" :size="15" /></button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>
  <div v-else class="card">
    <div v-if="loading"><div class="skel" v-for="i in 4" :key="i" /></div>
    <div v-else-if="!rows.length" class="empty">لا بيانات</div>
    <div v-dt v-else class="tw"><table>
      <template v-if="kind === 'leave-types'">
        <thead><tr><th>الرمز</th><th>الاسم</th><th>الاحتساب</th><th>بند الرصيد في الباقة</th><th>يخصم</th><th>مرفق إلزامي</th><th>الأجر</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="r in rows" :key="r.id">
          <td><b>{{ r.code }}</b></td><td>{{ r.name }}<span class="tiny muted">{{ r.name_en }}</span></td><td>{{ ACCRUAL[r.accrual] }}</td><td class="small">{{ benefitLabel(r.quota_key) }}</td>
          <td><Yes :v="r.deducts" /></td><td><Yes :v="r.attachment_required" /></td><td>{{ PAY[r.pay] }}<span v-if="r.extra_approval?.length" class="tiny muted">+ {{ r.extra_approval.map((k) => flowRoles[k]).join('، ') }}</span></td>
          <td><span class="pill" :class="r.active ? 'ok' : 'gray'">{{ r.active ? 'مفعّل' : 'معطّل' }}</span></td><td><Acts :r="r" /></td>
        </tr></tbody>
      </template>
      <template v-else-if="kind === 'job-titles'">
        <thead><tr><th>الرمز</th><th>المسمى</th><th>العائلة</th><th>المسؤوليات</th><th>مؤشرات مقترحة</th><th>الموظفون</th><th>النسخة</th><th /></tr></thead>
        <tbody><tr v-for="r in rows" :key="r.id">
          <td><b>{{ r.code }}</b></td><td>{{ r.name }}<span class="tiny muted">{{ r.name_en }}</span></td><td>{{ r.family }}</td>
          <td class="small">{{ (r.responsibilities || []).join(' · ') }}</td><td class="small">{{ (r.kpis || []).join(' · ') }}</td><td>{{ r.employees_count }}</td><td>v{{ r.version }}</td><td><Acts :r="r" /></td>
        </tr></tbody>
      </template>
      <template v-else-if="kind === 'schedules'">
        <thead><tr><th>الجدول</th><th>الأيام والساعات</th><th>مرن</th><th>عابر لمنتصف الليل</th><th>الموظفون</th><th /></tr></thead>
        <tbody><tr v-for="r in rows" :key="r.id">
          <td><b>{{ r.name }}</b></td><td class="small">{{ r.week_days.map((d) => `${DAYS_AR[d]} ${r.hours[d]?.[0]}–${r.hours[d]?.[1]}`).join(' · ') }}</td>
          <td><Yes :v="r.flex" /></td><td><Yes :v="r.cross_midnight" /></td><td>{{ r.employees_count }}</td><td><Acts :r="r" /></td>
        </tr></tbody>
      </template>
      <template v-else>
        <thead><tr><th>الموقع</th><th>الشركة</th><th>النوع</th><th>القطر</th><th>الإحداثيات</th><th /></tr></thead>
        <tbody><tr v-for="r in rows" :key="r.id">
          <td><b>{{ r.name }}</b> <span v-if="r.is_main" class="pill navy">المقر الرسمي</span><div v-if="r.address" class="tiny muted">{{ r.address }}</div></td>
          <td>{{ r.company?.name || 'المجموعة (كل الشركات)' }}</td><td>{{ SCOPE[r.scope] }}</td>
          <td>{{ r.scope === 'open' ? '—' : r.radius + ' م' }}</td>
          <td class="num small">{{ r.scope === 'open' ? '—' : `${r.lat}, ${r.lng}` }} <a v-if="r.scope !== 'open'" :href="`https://www.google.com/maps?q=${r.lat},${r.lng}`" target="_blank" rel="noopener" class="btn ghost xs" title="فتح في خرائط Google"><Icon name="external" :size="13" /></a></td><td><Acts :r="r" /></td>
        </tr></tbody>
      </template>
    </table></div>
  </div>

  <Modal v-if="f" :title="(f.id ? 'تعديل — ' : 'إضافة — ') + KINDS[kind]" :size="kind === 'job-titles' ? 'wide' : ''" @close="f = null">
    <template v-if="kind === 'leave-types'">
      <div class="fr3">
        <div class="fg"><label>الرمز <span class="tiny muted">(اختياري — يُولَّد تلقائيًا)</span></label><input v-model="f.code" class="in" dir="ltr" :disabled="!!f.id" /></div>
        <div class="fg"><label>الاسم *</label><input v-model="f.name" class="in" /></div>
        <div class="fg"><label>الاسم بالإنجليزية</label><input v-model="f.name_en" class="in" /></div>
        <div class="fg"><label>طريقة الاحتساب</label><select v-model="f.accrual" class="in"><option v-for="(l, k) in ACCRUAL" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="fg"><label>بند الرصيد في الباقة</label><select v-model="f.quota_key" class="in"><option :value="null">—</option><option v-for="(l, k) in auth.meta.benefit_labels" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="fg"><label>الأجر أثناء الإجازة</label><select v-model="f.pay" class="in"><option v-for="(l, k) in PAY" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="fg"><label>الحد لكل واقعة (أيام)</label><input v-model.number="f.max_per_case" type="number" class="in" /></div>
        <div class="fg"><label>الترتيب</label><input v-model.number="f.sort" type="number" class="in" /></div>
      </div>
      <div class="row"><label class="chk"><input v-model="f.deducts" type="checkbox" /> يخصم من الرصيد</label><label class="chk"><input v-model="f.attachment_required" type="checkbox" /> مرفق إلزامي</label>
        <label class="chk"><input v-model="f.carry_over" type="checkbox" /> يُرحَّل</label><label class="chk"><input v-model="f.active" type="checkbox" /> مفعّل</label></div>
      <div v-if="f.deducts && !f.quota_key && f.accrual !== 'none' && f.accrual !== 'case'" class="notice warn small mt">نوع يخصم من الرصيد بلا «بند رصيد في الباقة» يبدأ رصيده صفرًا. اختر بند الباقة (أو أضف بندًا جديدًا من صفحة الشركات والباقات)، أو اضبط الرصيد يدويًا من ملف الموظف.</div>
      <div class="notice info small mt"><b>يخصم من الرصيد:</b> عند تفعيله تُطرح أيام الإجازة من رصيد الموظف لهذا النوع، وعند إلغائه تُسجَّل الإجازة دون المساس بأي رصيد (مثل إجازة الزواج أو الوفاة).</div>
      <div class="fg mt"><label>اعتمادات إضافية لهذا النوع (تُضاف بعد مسار الإجازة)</label>
        <div class="row"><label v-for="(l, k) in flowRoles" :key="k" class="chk small"><input v-model="f.extra_approval" type="checkbox" :value="k" /> {{ l }}</label></div>
      </div>
    </template>

    <template v-else-if="kind === 'job-titles'">
      <div class="fr3">
        <div class="fg"><label>الرمز <span class="tiny muted">(اختياري — يُولَّد تلقائيًا)</span></label><input v-model="f.code" class="in" dir="ltr" /></div>
        <div class="fg"><label>المسمى *</label><input v-model="f.name" class="in" /></div>
        <div class="fg"><label>المسمى بالإنجليزية</label><input v-model="f.name_en" class="in" /></div>
        <div class="fg"><label>العائلة الوظيفية</label><input v-model="f.family" class="in" /></div>
      </div>
      <div class="fg"><label>الغرض من الوظيفة</label><textarea v-model="f.purpose" class="in" /></div>
      <div class="fr">
        <div v-for="(l, k) in LISTS" :key="k" class="fg"><label>{{ l }} (سطر لكل بند)</label><textarea v-model="f[k + '_txt']" class="in" /></div>
      </div>
      <p v-if="f.id" class="tiny muted">الحفظ يُصدر نسخة جديدة ويُشعر شاغلي الوظيفة.</p>
    </template>

    <template v-else-if="kind === 'schedules'">
      <div class="fg"><label>اسم الجدول *</label><input v-model="f.name" class="in" /></div>
      <div v-dt class="tw mb"><table>
        <thead><tr><th>يوم عمل</th><th>اليوم</th><th>البداية</th><th>النهاية</th></tr></thead>
        <tbody><tr v-for="d in [6, 0, 1, 2, 3, 4, 5]" :key="d">
          <td><input v-model="f.days[d].on" type="checkbox" /></td><td>{{ DAYS_AR[d] }}</td>
          <td><input v-model="f.days[d].from" type="time" class="in" :disabled="!f.days[d].on" /></td>
          <td><input v-model="f.days[d].to" type="time" class="in" :disabled="!f.days[d].on" /></td>
        </tr></tbody>
      </table></div>
      <div class="row"><label class="chk"><input v-model="f.flex" type="checkbox" /> يسمح بالساعة المرنة</label><label class="chk"><input v-model="f.cross_midnight" type="checkbox" /> وردية عابرة لمنتصف الليل</label></div>
      <div class="fr mt"><div class="fg"><label>فترة الراحة اليومية (دقيقة)</label><input v-model.number="f.break_minutes" type="number" min="0" max="180" class="in" /></div>
        <div class="fg"><label>مجموع ساعات الأسبوع</label><div class="in" :class="{ bad: weekHours > 48 }" style="background:transparent">{{ weekHours }} ساعة <span class="tiny muted">(الحد الأعلى 48)</span></div></div></div>
      <p class="tiny muted">اللائحة §24: لا يتجاوز الجدول 48 ساعة في الأسبوع، وتُخفَّض في رمضان للمسلمين إلى 6 ساعات يوميًا (الإعدادات ramadanFrom / ramadanTo). §25: لا يبقى الموظف في مكان العمل أكثر من 12 ساعة يوميًا، ولا يعمل أكثر من 5 ساعات متصلة دون راحة لا تقل عن 30 دقيقة، ولا تُحسب الراحة من ساعات العمل المطلوبة.</p>
      <div v-if="errors.hours" class="notice bad small">{{ [].concat(errors.hours).join(' · ') }}</div>
    </template>

    <template v-else>
      <div class="notice info small">الطريقة الأسهل: افتح موقع الشركة في خرائط Google ثم الصق هنا أحد الثلاثة واضغط «جلب الموقع»: كود «مشاركة ← تضمين خريطة» (الأدق)، أو الرابط من شريط العنوان في المتصفح، أو الإحداثيات مباشرة (نقرة يمنى على الموقع ← نسخ). تُملأ الإحداثيات والاسم تلقائيًا، وتبقى الخريطة للتأكد وضبط القطر. ملاحظة: روابط المشاركة المختصرة (maps.app.goo.gl) لا تحمل الإحداثيات.</div>
      <div class="fg"><label>رابط خرائط Google أو كود التضمين</label>
        <div class="row" style="gap:6px;flex-wrap:nowrap"><input v-model.trim="f.map_url" class="in" dir="ltr" placeholder="<iframe src=…>  أو  https://www.google.com/maps/place/…/@24.58,46.59…  أو  24.5865, 46.5969" @keydown.enter.prevent="resolveLink" /><button type="button" class="btn sec sm" :class="{ loading: resolving }" :disabled="resolving || !f.map_url" @click="resolveLink"><Icon name="map-pin" :size="15" /> جلب الموقع</button></div>
      </div>
      <div class="fr">
        <div class="fg"><label>اسم الموقع *</label><input v-model="f.name" class="in" placeholder="مثال: المقر الرئيسي — مركز بلال" /></div>
        <div class="fg"><label>العنوان</label><input v-model="f.address" class="in" placeholder="اختياري" /></div>
      </div>
      <div class="fr">
        <div class="fg"><label>نوع النطاق</label><select v-model="f.scope" class="in"><option v-for="(l, k) in SCOPE" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="fg"><label>الشركة (اختياري)</label><select v-model="f.company_id" class="in"><option :value="null">المجموعة</option><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      </div>
      <label class="chk"><input v-model="f.is_main" type="checkbox" /> المقر الرسمي للشركة <span class="tiny muted">(يُقارن به حضور كل موظفي الشركة ما لم يُحدَّد لهم موقع آخر)</span></label>
      <template v-if="f.scope !== 'open'">
        <LocationPicker v-model:lat="f.lat" v-model:lng="f.lng" v-model:radius="f.radius" />
        <div class="fr3 mt">
          <div class="fg"><label>خط العرض</label><input v-model.number="f.lat" type="number" step="any" class="in" dir="ltr" /></div>
          <div class="fg"><label>خط الطول</label><input v-model.number="f.lng" type="number" step="any" class="in" dir="ltr" /></div>
          <div class="fg"><label>القطر (متر)</label><input v-model.number="f.radius" type="number" class="in" /></div>
        </div>
      </template>
    </template>
    <div v-if="Object.keys(errors).length" class="notice bad mt">{{ Object.values(errors).flat().join(' · ') }}</div>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">حفظ</button></template>
  </Modal>

  <Modal v-if="itemForm" :title="(itemForm.id ? 'تعديل عنصر — ' : 'عنصر جديد — ') + (curGroup?.name || '')" @close="itemForm = null">
    <div class="fr">
      <div class="fg"><label>الاسم *</label><input v-model="itemForm.name" class="in" /></div>
      <div class="fg"><label>الاسم بالإنجليزية</label><input v-model="itemForm.name_en" class="in" /></div>
      <div class="fg"><label>الرمز (اختياري)</label><input v-model="itemForm.code" class="in" style="direction:ltr" /></div>
      <div class="fg"><label>الترتيب</label><input v-model.number="itemForm.sort" type="number" class="in" /></div>
      <div v-for="mf in curGroup?.meta_fields || []" :key="mf.key" class="fg">
        <label>{{ mf.label }}</label>
        <label v-if="mf.type === 'bool'" class="chk"><input v-model="itemForm.meta[mf.key]" type="checkbox" /> نعم</label>
        <input v-else v-model="itemForm.meta[mf.key]" :type="mf.type === 'number' ? 'number' : 'text'" step="any" class="in" />
      </div>
    </div>
    <label class="chk"><input v-model="itemForm.active" type="checkbox" /> مفعّل</label>
    <template #foot><button class="btn sec" @click="itemForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveItem">حفظ</button></template>
  </Modal>

  <Modal v-if="groupForm" :title="groupForm.id ? 'تعديل قائمة' : 'قائمة عامة جديدة'" @close="groupForm = null">
    <div class="fr">
      <div class="fg"><label>الرمز (لاتيني) *</label><input v-model="groupForm.code" class="in" style="direction:ltr" :disabled="!!groupForm.id" placeholder="violation_types" /></div>
      <div class="fg"><label>الاسم *</label><input v-model="groupForm.name" class="in" /></div>
      <div class="fg"><label>الاسم بالإنجليزية</label><input v-model="groupForm.name_en" class="in" /></div>
    </div>
    <label class="tiny muted b">حقول إضافية لكل عنصر (مثل: النقاط، الحد الأقصى)</label>
    <div v-for="(mf, i) in groupForm.meta_fields" :key="i" class="row mb" style="flex-wrap:nowrap">
      <input v-model="mf.key" class="in" style="direction:ltr" placeholder="key" /><input v-model="mf.label" class="in" placeholder="التسمية" />
      <select v-model="mf.type" class="in" style="max-width:110px"><option value="text">نص</option><option value="number">رقم</option><option value="bool">نعم/لا</option></select>
      <button class="btn ghost xs bad" @click="groupForm.meta_fields.splice(i, 1)"><Icon name="trash" :size="14" /></button>
    </div>
    <button class="btn sec sm" @click="groupForm.meta_fields.push({ key: '', label: '', type: 'text' })"><Icon name="plus" :size="14" /> حقل</button>
    <template #foot><button class="btn sec" @click="groupForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveGroup">حفظ</button></template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, defineComponent, h, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Icon from '../components/Icon.vue'
import LocationPicker from '../components/LocationPicker.vue'
import Modal from '../components/Modal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { DAYS_AR, getPos } from '../utils'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const KINDS = { 'leave-types': 'أنواع الإجازات', 'job-titles': 'كتالوج الوظائف', schedules: 'جداول الدوام', locations: 'مواقع التحضير', lists: 'القوائم العامة' }
const ACCRUAL = { monthly: 'شهري تراكمي', yearly: 'سنوي مستقل', case: 'لكل حالة', none: 'بلا رصيد' }
const PAY = { full: 'كامل', tiered: 'متدرج', policy: 'وفق السياسة', none: 'بلا أجر' }
const SCOPE = { point: 'نطاق بقطر محدد', city: 'مدينة', region: 'منطقة', open: 'مفتوح (ميداني)' }
const LISTS = { responsibilities: 'المسؤوليات', qualifications: 'المؤهلات', skills: 'المهارات', kpis: 'مؤشرات الأداء المقترحة' }
const kind = ref('leave-types')
const rows = ref([])
const loading = ref(true)
const f = ref(null)
const busy = ref(false)
const errors = ref({})
const flowRoles = ref({})
const groups = ref([]); const group = ref(''); const itemForm = ref(null); const groupForm = ref(null)
const curGroup = computed(() => groups.value.find((g) => g.code === group.value))
api.get('/flows/roles').then((r) => (flowRoles.value = r))
const benefitLabel = (k) => (k ? auth.meta.benefit_labels?.[k] || k : '—')

const Yes = (p) => (p.v ? h(Icon, { name: 'check', size: 16, class: 'tick', stroke: 2.5 }) : h('span', { class: 'cross' }, '—'))
Yes.props = ['v']
const Acts = defineComponent({
  props: { r: Object },
  setup(p) {
    return () => h('div', { class: 'row', style: 'gap:4px;flex-wrap:nowrap' }, [
      h('button', { class: 'btn ghost xs', title: 'تعديل', onClick: () => open(p.r) }, [h(Icon, { name: 'edit', size: 15 })]),
      h('button', { class: 'btn ghost xs bad', title: 'حذف', onClick: () => remove(p.r) }, [h(Icon, { name: 'trash', size: 15 })]),
    ])
  },
})

async function load() {
  loading.value = true
  if (kind.value === 'lists') {
    groups.value = await api.get('/lookup-groups')
    if (!group.value || !groups.value.find((g) => g.code === group.value)) group.value = groups.value[0]?.code || ''
    rows.value = group.value ? await api.get(`/lookup-groups/${group.value}/items`) : []
  } else rows.value = await api.get(`/lookups/${kind.value}`)
  loading.value = false
}
function openItem(r) { itemForm.value = { name: '', name_en: '', code: '', sort: (rows.value.length + 1) * 10, active: true, ...JSON.parse(JSON.stringify(r)), meta: { ...(r.meta || {}) } } }
async function saveItem() {
  const x = itemForm.value
  if (!x.name?.trim()) return ui.toast('الاسم إلزامي', 'warn')
  busy.value = true
  try {
    const body = { name: x.name, name_en: x.name_en, code: x.code || null, sort: x.sort, active: !!x.active, meta: x.meta }
    if (x.id) await api.put(`/lookup-groups/${group.value}/items/${x.id}`, body); else await api.post(`/lookup-groups/${group.value}/items`, body)
    ui.toast('تم الحفظ', 'ok'); itemForm.value = null; load(); auth.loadMeta()
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function removeItem(r) {
  if (!await confirmBox(`حذف «${r.name}»؟`)) return
  const res = await api.delete(`/lookup-groups/${group.value}/items/${r.id}`)
  ui.toast(res.deactivated ? 'مستخدم في طلبات سابقة — عُطّل بدل الحذف' : 'حُذف', 'ok'); load(); auth.loadMeta()
}
async function saveGroup() {
  const g = groupForm.value
  if (!g.code || !g.name) return ui.toast('الرمز والاسم إلزاميان', 'warn')
  busy.value = true
  try {
    const body = { code: g.code, name: g.name, name_en: g.name_en, meta_fields: g.meta_fields.filter((m) => m.key && m.label) }
    if (g.id) await api.put(`/lookup-groups/${g.code}`, body); else await api.post('/lookup-groups', body)
    ui.toast('حُفظت القائمة', 'ok'); group.value = g.code; groupForm.value = null; load()
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}

function open(r) {
  errors.value = {}
  const x = JSON.parse(JSON.stringify(r))
  if (kind.value === 'leave-types') f.value = { accrual: 'yearly', pay: 'full', deducts: true, active: true, quota_key: null, ...x, extra_approval: x.extra_approval || [] }
  else if (kind.value === 'job-titles') f.value = { ...x, ...Object.fromEntries(Object.keys(LISTS).map((k) => [k + '_txt', (x[k] || []).join('\n')])) }
  else if (kind.value === 'schedules') {
    const days = {}
    for (let d = 0; d < 7; d++) days[d] = { on: (x.week_days || [0, 1, 2, 3, 4]).includes(d), from: x.hours?.[d]?.[0] || '09:00', to: x.hours?.[d]?.[1] || '17:00' }
    f.value = { flex: false, cross_midnight: false, break_minutes: 30, ...x, days }
  } else f.value = { scope: 'point', radius: 150, company_id: ui.company || auth.emp?.company_id || null, is_main: false, address: '', map_url: '', ...x } // the current company by default
}

// §24: weekly total of the schedule being edited (a shift ending before it starts crosses midnight)
const weekHours = computed(() => {
  if (kind.value !== 'schedules' || !f.value?.days) return 0
  const m = (t) => { const [h, mi] = (t || '0:0').split(':').map(Number); return h * 60 + mi }
  const tot = Object.values(f.value.days).filter((d) => d.on).reduce((s, d) => { const a = m(d.from); const b = m(d.to); return s + (b > a ? b - a : b + 1440 - a) }, 0)
  return Math.round(tot / 6) / 10
})

function payload() {
  const x = f.value
  if (kind.value === 'job-titles') {
    const out = { code: x.code, name: x.name, name_en: x.name_en, family: x.family, purpose: x.purpose }
    for (const k of Object.keys(LISTS)) out[k] = (x[k + '_txt'] || '').split('\n').map((s) => s.trim()).filter(Boolean)
    return out
  }
  if (kind.value === 'schedules') {
    const on = Object.entries(x.days).filter(([, v]) => v.on)
    return { name: x.name, flex: !!x.flex, cross_midnight: !!x.cross_midnight, break_minutes: x.break_minutes ?? 30, week_days: on.map(([d]) => +d), hours: Object.fromEntries(on.map(([d, v]) => [d, [v.from, v.to]])) }
  }
  const { id, created_at, updated_at, company, employees_count, ...rest } = x
  return rest
}

// Google Maps link → coordinates (server-side: follows short links, reads share links and embed codes)
const resolving = ref(false)
async function resolveLink() {
  if (!f.value.map_url) return
  resolving.value = true
  try {
    const r = await api.post('/lookups/locations/resolve', { url: f.value.map_url })
    f.value.lat = r.lat; f.value.lng = r.lng; f.value.map_url = r.url
    if (!f.value.name && r.name) f.value.name = r.name
    if (!f.value.address && r.name) f.value.address = r.name
    ui.toast(`حُدد الموقع: ${r.lat}, ${r.lng}${r.name ? ' — ' + r.name : ''}`, 'ok', 5000)
  } catch (e) { ui.toast(e.message || 'تعذر قراءة الرابط', 'bad', 6000) } finally { resolving.value = false }
}
async function save() {
  errors.value = {}
  if (kind.value === 'locations') {
    if (!f.value.name?.trim()) return ui.toast('اكتب اسم الموقع', 'warn')
    if (f.value.scope !== 'open' && !f.value.lat) return ui.toast('حدد الموقع على الخريطة: ابحث بالاسم أو انقر على الخريطة أو استخدم «موقعي الحالي»', 'warn', 6000)
  }
  busy.value = true
  try {
    const res = f.value.id ? await api.put(`/lookups/${kind.value}/${f.value.id}`, payload()) : await api.post(`/lookups/${kind.value}`, payload())
    ui.toast('تم الحفظ', 'ok')
    if (res?.check?.warnings?.length) ui.toast(res.check.warnings.join(' · '), 'warn', 8000)
    f.value = null
    load()
    auth.loadMeta()
  } catch (e) { errors.value = e.errors || {}; if (!e.errors) ui.toast(e.message || 'تعذر الحفظ', 'bad') } finally { busy.value = false }
}

async function remove(r) {
  if (!await confirmBox(`حذف «${r.name}»؟`)) return
  const res = await api.delete(`/lookups/${kind.value}/${r.id}`)
  ui.toast(res.deactivated ? 'النوع مستخدم في طلبات سابقة — عُطّل بدل الحذف' : 'حُذف', 'ok')
  load()
  auth.loadMeta()
}

async function useMyLocation() {
  const p = await getPos()
  if (!p) return ui.toast('تعذر تحديد الموقع — اسمح للمتصفح بالوصول للموقع', 'bad')
  f.value.lat = +p.lat.toFixed(6)
  f.value.lng = +p.lng.toFixed(6)
  ui.toast(`أُخذت إحداثيات موقعك (دقة ±${p.accuracy} م)`, 'ok')
}
onMounted(load)
</script>
