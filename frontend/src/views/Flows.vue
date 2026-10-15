<template>
  <div class="page-h">
    <div><h1>{{ t('أنواع الطلبات ومسارات الاعتماد') }}</h1><p>كل نوع طلب بياناته: الاسم، العملية، من يقدّمه، مراحل اعتماده — ويمكن إنشاء أنواع جديدة بحقول مخصصة دون برمجة</p></div>
    <div class="acts"><button class="btn pri sm" @click="openNew"><Icon name="plus" :size="16" /> نوع طلب جديد</button></div>
  </div>
  <div class="notice info">قواعد ثابتة لا تُعدَّل: لا يعتمد الشخص طلبه أبدًا، وعند غياب المعتمد يُصعَّد الطلب تلقائيًا، وتنتقل الاعتمادات للمفوَّض أثناء التفويض. تغيير المسار يسري على الطلبات الجديدة فقط.</div>
  <div class="grid g2">
    <div v-for="rt in types" :key="rt.code" class="card" :class="{ dim: !rt.active }">
      <h3>{{ rt.name }} <span class="sub">{{ rt.code }}<template v-if="rt.name_en"> · {{ rt.name_en }}</template></span>
        <span class="pill" :class="rt.active ? 'ok' : 'gray'">{{ rt.active ? 'مفعّل' : 'معطّل' }}</span>
        <span v-if="!rt.builtin" class="pill purple">مخصص</span>
        <span class="acts"><button class="btn ghost xs" @click="edit(rt)"><Icon name="edit" :size="14" /> تعديل</button></span>
      </h3>
      <div class="steps">
        <template v-for="(s, i) in rt.flow" :key="i">
          <span class="step"><i>{{ i + 1 }}</i>{{ s.label || roles[s.role] }}<span v-if="s.skip_if" class="tiny muted">(تُتخطى إذا {{ s.skip_if }})</span></span>
          <Icon v-if="i < rt.flow.length - 1" name="chevron-left" :size="16" class="muted" flip-rtl />
        </template>
      </div>
      <div class="tiny muted mt">
        {{ ops[rt.op] || rt.op }} · {{ rt.self_service ? 'يقدّمه الموظف' : '' }}{{ rt.self_service && rt.on_behalf ? ' و' : '' }}{{ rt.on_behalf ? 'يقدَّم نيابةً عن موظف' : '' }}
        · {{ rt.requests_count }} طلب<span v-if="rt.form?.length"> · {{ rt.form.length }} حقول</span>
      </div>
    </div>
  </div>

  <Modal v-if="f" :title="f.id ? `تعديل نوع: ${f.name}` : 'نوع طلب جديد'" size="wide" @close="f = null">
    <div class="fr3">
      <div class="fg"><label>الرمز (لاتيني) *</label><input v-model="f.code" class="in" :disabled="!!f.id" style="direction:ltr" /></div>
      <div class="fg"><label>الاسم *</label><input v-model="f.name" class="in" /></div>
      <div class="fg"><label>الاسم بالإنجليزية</label><input v-model="f.name_en" class="in" /></div>
      <div class="fg"><label>العملية (لحدود التفويض)</label><select v-model="f.op" class="in"><option v-for="(l, k) in ops" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="fg"><label>الترتيب</label><input v-model.number="f.sort" type="number" class="in" /></div>
    </div>
    <div class="row mb">
      <label class="chk"><input v-model="f.self_service" type="checkbox" /> يقدّمه الموظف لنفسه</label>
      <span class="small">الأدوار المسموح لها (فارغ = الجميع):</span><label v-for="(l, k) in auth.meta.roles" :key="k" class="chk small"><input v-model="f.roles" type="checkbox" :value="k" /> {{ l }}</label>
      <label class="chk"><input v-model="f.on_behalf" type="checkbox" /> يقدَّم نيابةً عن موظف (موارد بشرية / مدير)</label>
      <label class="chk"><input v-model="f.active" type="checkbox" /> مفعّل</label>
    </div>

    <h4 style="margin:10px 0 6px">مراحل الاعتماد</h4>
    <div v-for="(s, i) in f.flow" :key="i" class="row mb" style="flex-wrap:nowrap">
      <b style="width:24px">{{ i + 1 }}</b>
      <select v-model="s.role" class="in"><option v-for="(l, k) in roles" :key="k" :value="k">{{ l }}</option></select>
      <input v-model="s.label" class="in" placeholder="تسمية اختيارية" />
      <input v-model="s.skip_if" class="in num" style="direction:ltr;max-width:130px" placeholder="days<=2" />
      <button class="btn ghost xs" :disabled="i === 0" @click="move(i, -1)"><Icon name="chevron-up" :size="16" /></button>
      <button class="btn ghost xs" :disabled="i === f.flow.length - 1" @click="move(i, 1)"><Icon name="chevron-down" :size="16" /></button>
      <button class="btn ghost xs bad" :disabled="f.flow.length === 1" @click="f.flow.splice(i, 1)"><Icon name="trash" :size="16" /></button>
    </div>
    <button class="btn sec sm" :disabled="f.flow.length >= 6" @click="f.flow.push({ role: 'hr' })"><Icon name="plus" :size="16" /> مرحلة</button>
    <p class="tiny muted">شرط التخطي (اختياري) يقارن حقلًا رقميًا من الطلب، مثل <b class="num">days&lt;=2</b> أو <b class="num">amount&lt;=1000</b>.</p>

    <template v-if="!f.builtin">
      <h4 style="margin:14px 0 6px">حقول النموذج</h4>
      <div v-dt class="tw mb"><table>
        <thead><tr><th>المفتاح</th><th>التسمية</th><th>بالإنجليزية</th><th>النوع</th><th>الخيارات / القائمة</th><th>إلزامي</th><th /></tr></thead>
        <tbody><tr v-for="(fd, i) in f.form" :key="i">
          <td><input v-model="fd.key" class="in" style="direction:ltr;min-width:100px" placeholder="key" /></td>
          <td><input v-model="fd.label" class="in" style="min-width:120px" /></td>
          <td><input v-model="fd.label_en" class="in" style="min-width:100px" /></td>
          <td><select v-model="fd.type" class="in"><option v-for="(l, k) in FIELD_TYPES" :key="k" :value="k">{{ l }}</option></select></td>
          <td>
            <input v-if="fd.type === 'select'" v-model="fd.options_txt" class="in" placeholder="خيار1، خيار2" />
            <select v-else-if="fd.type === 'lookup'" v-model="fd.group" class="in"><option v-for="g in groups" :key="g.code" :value="g.code">{{ g.name }}</option></select>
            <span v-else class="muted">—</span>
          </td>
          <td><input v-model="fd.required" type="checkbox" /></td>
          <td><button class="btn ghost xs bad" @click="f.form.splice(i, 1)"><Icon name="trash" :size="14" /></button></td>
        </tr></tbody>
      </table></div>
      <button class="btn sec sm" :disabled="f.form.length >= 20" @click="f.form.push({ key: '', label: '', type: 'text', required: false })"><Icon name="plus" :size="16" /> حقل</button>
    </template>
    <p v-else class="tiny muted mt">هذا نوع مدمج: نموذجه وأثره بعد الاعتماد مبرمجان، ويمكن تعديل اسمه ومساره وتفعيله.</p>

    <div class="fg mt"><label>سبب التعديل *</label><input v-model="f.reason" class="in" /></div>
    <div v-if="Object.keys(errors).length" class="notice bad mt">{{ Object.values(errors).flat().join(' · ') }}</div>
    <template #foot>
      <button v-if="f.id && !f.builtin" class="btn dan" @click="remove">حذف</button>
      <button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">حفظ</button>
    </template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Modal from '../components/Modal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const { t } = useI18n()
const ui = useUi()
const auth = useAuth()
const FIELD_TYPES = { text: 'نص', textarea: 'نص طويل', number: 'رقم', date: 'تاريخ', select: 'اختيار من خيارات', lookup: 'قائمة عامة', bool: 'نعم/لا', employee: 'موظف' }
const types = ref([]); const roles = ref({}); const groups = ref([]); const ops = ref({})
const f = ref(null); const busy = ref(false); const errors = ref({})
async function load() {
  ;[types.value, roles.value, groups.value] = await Promise.all([api.get('/request-types'), api.get('/flows/roles'), api.get('/lookup-groups')])
  ops.value = auth.meta?.ops || {}
}
const edit = (rt) => { f.value = { ...JSON.parse(JSON.stringify(rt)), roles: rt.roles || [], form: (rt.form || []).map((x) => ({ ...x, options_txt: (x.options || []).join('، ') })), reason: '' }; errors.value = {} }
const openNew = () => { f.value = { code: '', name: '', name_en: '', op: 'EMP', flow: [{ role: 'mgr' }], form: [], roles: [], self_service: true, on_behalf: false, active: true, builtin: false, sort: 100, reason: 'نوع جديد' }; errors.value = {} }
function move(i, d) { const s = f.value.flow; [s[i], s[i + d]] = [s[i + d], s[i]] }
async function save() {
  const x = f.value
  if (!x.reason?.trim()) return ui.toast('سبب التعديل إلزامي', 'warn')
  busy.value = true; errors.value = {}
  try {
    const body = { ...x, roles: x.roles?.length ? x.roles : null, flow: x.flow.map((s) => ({ role: s.role, label: s.label || null, skip_if: s.skip_if || null })),
      form: x.builtin ? undefined : x.form.map(({ options_txt, ...fd }) => ({ ...fd, options: fd.type === 'select' ? (options_txt || '').split(/[،,]/).map((s) => s.trim()).filter(Boolean) : undefined })) }
    delete body.requests_count
    if (x.id) await api.put(`/request-types/${x.code}`, body); else await api.post('/request-types', body)
    ui.toast('حُفظ نوع الطلب', 'ok'); f.value = null; load(); auth.loadMeta()
  } catch (e) { errors.value = e.errors || { e: [e.message] } } finally { busy.value = false }
}
async function remove() {
  if (!await confirmBox(`حذف نوع «${f.value.name}»؟ إن كانت له طلبات سابقة فسيُعطَّل بدل الحذف.`)) return
  const r = await api.delete(`/request-types/${f.value.code}`)
  ui.toast(r.deactivated ? 'له طلبات سابقة — عُطّل' : 'حُذف', 'ok'); f.value = null; load(); auth.loadMeta()
}
onMounted(load)
</script>
