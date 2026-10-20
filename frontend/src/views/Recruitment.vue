<template>
  <div class="page-h">
    <div><h1>{{ t('التوظيف') }}</h1><p>شواغر تمر بمسار الاعتماد (المشرف ← الموارد البشرية ← المالية ← الرئيس التنفيذي للشركة، ويضاف الرئيس التنفيذي للمجموعة لما هو خارج الخطة)، ومتقدمون يمرون بمراحل من القوائم العامة، وعرض وظيفي، ثم تحويل المقبول إلى ملف موظف</p></div>
    <div class="acts"><button class="btn sec sm" @click="newOpening"><Icon name="plus" :size="16" /> شاغر</button><button v-if="isHr" class="btn pri sm" @click="af = { company_id: auth.meta.companies[0]?.id, opening_id: null, job_title_id: null, name: '', email: '', phone: '', notes: '', relation: '' }"><Icon name="plus" :size="16" /> متقدم</button></div>
  </div>
  <div class="tabs"><button v-if="isHr" :class="{ on: tab === 'apps' }" @click="tab = 'apps'">المتقدمون</button><button :class="{ on: tab === 'openings' }" @click="tab = 'openings'">الشواغر</button></div>

  <template v-if="tab === 'apps'">
    <div class="grid mb" :style="`grid-template-columns:repeat(${stages.length},1fr);gap:10px`">
      <Kpi v-for="s in stages" :key="s.code" :label="s.name" :value="apps.filter((a) => a.stage === s.code).length" :color="s.code === 'accepted' ? 'g' : s.code === 'rejected' ? 'r' : 'b'" />
    </div>
    <div class="card">
      <div class="row mb"><select v-model="stage" class="in" style="width:auto" @change="load"><option value="">كل المراحل</option><option v-for="s in stages" :key="s.code" :value="s.code">{{ s.name }}</option></select><select v-model="openingF" class="in" style="width:auto" @change="load"><option :value="null">كل الشواغر</option><option v-for="o in openings" :key="o.id" :value="o.id">{{ o.title }}</option></select></div>
      <div v-if="!apps.length" class="empty">لا متقدمين</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>المتقدم</th><th>الشاغر</th><th>الشركة</th><th>التواصل</th><th>المرحلة</th><th>التقييم</th><th>الإفصاح</th><th>المصدر</th><th>التاريخ</th><th /></tr></thead>
        <tbody><tr v-for="a in apps" :key="a.id">
          <td><b>{{ a.name }}</b><div v-if="a.notes" class="tiny muted">{{ a.notes.slice(0, 60) }}</div></td><td>{{ a.opening || a.job || '—' }}</td><td class="small">{{ a.company }}</td><td class="small" style="direction:ltr">{{ a.email }}<br />{{ a.phone }}</td>
          <td><select :value="a.stage" class="in" style="width:auto" :disabled="!!a.employee_id" @change="setStage(a, $event.target.value)"><option v-for="s in stages" :key="s.code" :value="s.code">{{ s.name }}</option></select></td>
          <td><span v-for="i in 5" :key="i" style="cursor:pointer" :class="i <= (a.rating || 0) ? 'ok' : 'muted'" @click="rate(a, i)">★</span></td>
          <td class="small"><span v-if="a.relation" class="pill warn" :title="a.relation">قرابة معلنة</span> <span v-if="a.conflicts" class="pill bad">تعارض ({{ a.conflicts }})</span><span v-if="!a.relation && !a.conflicts" class="muted">—</span></td>
          <td>{{ a.source === 'public' ? 'صفحة التقديم' : 'يدوي' }}</td><td class="small">{{ fmtD(a.created_at) }}</td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><a v-if="a.cv" href="#" class="btn ghost xs" @click.prevent="fileUrl(a.cv)"><Icon name="download" :size="13" /> CV</a><button class="btn ghost xs" @click="af = { ...a }">تعديل</button>
            <button class="btn ghost xs" title="الإفصاح عن قرابة أو مصلحة مع المرشح (المادة 16)" @click="disclose(a)">{{ a.my_disclosure ? (a.my_disclosure.conflict ? 'أفصحت بتعارض' : 'أفصحت: لا تعارض') : 'إفصاح' }}</button>
            <button v-if="!a.employee_id" class="btn ghost xs" @click="openOffer(a)">{{ a.offer ? 'العرض' : 'إصدار عرض' }}</button>
            <button v-if="a.stage === 'accepted' && !a.employee_id" class="btn okb xs" @click="convert(a)">تحويل إلى موظف</button><span v-if="a.employee_id" class="pill ok">مُعيَّن</span></td>
        </tr></tbody></table></div>
    </div>
  </template>
  <div v-else class="card">
    <div v-if="!openings.length" class="empty">لا شواغر</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>الشاغر</th><th>الشركة</th><th>الوظيفة</th><th>العدد</th><th>الخطة</th><th>الاعتماد</th><th>المتقدمون</th><th>عام</th><th>الحالة</th><th /></tr></thead>
      <tbody><tr v-for="o in openings" :key="o.id"><td><b>{{ o.title }}</b><div v-if="lastNote(o)" class="tiny muted">{{ lastNote(o) }}</div></td><td>{{ o.company_name }}</td><td>{{ o.job }}</td><td>{{ o.headcount }}</td>
        <td><span class="pill" :class="o.in_plan ? 'ok' : 'warn'">{{ o.in_plan ? 'ضمن الخطة' : 'خارج الخطة' }}</span></td>
        <td><span class="pill" :class="{ approved: 'ok', rejected: 'bad', draft: 'gray' }[o.stage] || 'info'">{{ o.stage_label }}</span></td>
        <td>{{ o.applications_count }}</td><td>{{ o.public ? 'نعم' : '—' }}</td><td><span class="pill" :class="o.status === 'open' ? 'ok' : 'gray'">{{ o.status === 'open' ? 'مفتوح' : 'مغلق' }}</span></td>
        <td class="row" style="gap:4px;flex-wrap:nowrap"><button v-if="o.can_edit" class="btn ghost xs" @click="of = { ...o }">تعديل</button>
          <template v-if="o.can_act"><button class="btn okb xs" @click="decide(o, 'approve')">{{ o.stage === 'draft' ? 'إرسال للاعتماد' : 'اعتماد' }}</button><template v-if="o.stage !== 'draft'"><button class="btn ghost xs" @click="decide(o, 'return')">إرجاع</button><button class="btn dan xs" @click="decide(o, 'reject')">رفض</button></template></template></td></tr></tbody></table></div>
    <p class="tiny muted">مصفوفة الصلاحيات (الملحق الخامس): الشاغر ضمن الخطة يعدّه المشرف المباشر، ثم الموارد البشرية (م1)، ثم المالية (م2)، ويعتمده الرئيس التنفيذي للشركة. الشاغر أو العرض خارج الخطة يضاف له الرئيس التنفيذي للمجموعة اعتمادًا نهائيًا. لا يعتمد أحد شاغرًا أعدّه بنفسه.</p>
    <p class="tiny muted">الشواغر المعتمدة العامة تظهر على <code>/api/v1/careers</code> ويمكن التقديم عليها من موقع الشركة عبر <code>POST /api/v1/careers/{id}/apply</code>.</p>
  </div>

  <Modal v-if="of" :title="of.id ? 'تعديل شاغر' : 'شاغر جديد'" @close="of = null">
    <div class="fr"><div class="fg"><label>الشركة</label><select v-model="of.company_id" class="in" :disabled="of.id && of.stage !== 'draft'"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div><div class="fg"><label>الوظيفة</label><select v-model="of.job_title_id" class="in"><option :value="null">—</option><option v-for="j in auth.meta.job_titles" :key="j.id" :value="j.id">{{ j.name }}</option></select></div>
      <div class="fg"><label>العنوان *</label><input v-model="of.title" class="in" /></div><div class="fg"><label>العدد المطلوب</label><input v-model.number="of.headcount" type="number" min="1" class="in" :disabled="of.id && of.stage !== 'draft'" /></div>
      <div class="fg"><label>موقع العمل</label><input v-model="of.work_location" class="in" /></div></div>
    <div class="row mb"><label class="chk"><input v-model="of.in_plan" type="checkbox" :disabled="of.id && of.stage !== 'draft'" /> ضمن خطة القوى العاملة المعتمدة</label><span v-if="!of.in_plan" class="tiny warn">خارج الخطة: يتطلب اعتماد الرئيس التنفيذي للمجموعة (المادة 14)</span></div>
    <div class="fg"><label>الوصف</label><textarea v-model="of.description" class="in" rows="3" /></div>
    <div class="fg"><label>معايير القبول * <span class="tiny muted">(تُحدَّد قبل الإعلان — المادة 15)</span></label><textarea v-model="of.criteria" class="in" rows="3" placeholder="المؤهل، الخبرة، المهارات، الترخيص المهني إن لزم" /></div>
    <div v-if="of.stage === 'approved'" class="row"><label class="chk"><input v-model="of.public" type="checkbox" /> يقبل التقديم العام</label><label class="chk"><select v-model="of.status" class="in" style="width:auto"><option value="open">مفتوح</option><option value="closed">مغلق</option></select></label></div>
    <p v-else class="tiny muted">يُنشر الشاغر بعد اكتمال اعتماده.</p>
    <template #foot><button class="btn sec" @click="of = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveOpening">حفظ</button></template>
  </Modal>
  <Modal v-if="af" :title="af.id ? 'تعديل متقدم' : 'متقدم جديد'" @close="af = null">
    <div class="fr"><div class="fg"><label>الاسم *</label><input v-model="af.name" class="in" /></div><div class="fg"><label>البريد</label><input v-model="af.email" class="in" style="direction:ltr" /></div><div class="fg"><label>الجوال</label><input v-model="af.phone" class="in" style="direction:ltr" /></div>
      <div class="fg"><label>الشركة</label><select v-model="af.company_id" class="in"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div><div class="fg"><label>الشاغر</label><select v-model="af.opening_id" class="in"><option :value="null">—</option><option v-for="o in openings.filter((x) => x.stage === 'approved')" :key="o.id" :value="o.id">{{ o.title }}</option></select></div><div class="fg"><label>الوظيفة</label><select v-model="af.job_title_id" class="in"><option :value="null">—</option><option v-for="j in auth.meta.job_titles" :key="j.id" :value="j.id">{{ j.name }}</option></select></div></div>
    <div class="fg"><label>إفصاح المرشح عن قرابة أو مصلحة <span class="tiny muted">(مع أي موظف أو مشارك في الاختيار — المادة 16)</span></label><input v-model="af.relation" class="in" placeholder="لا يوجد" /></div>
    <div class="fg"><label>ملاحظات</label><textarea v-model="af.notes" class="in" rows="3" /></div>
    <div class="fg"><label>السيرة الذاتية</label><input type="file" class="in" @change="cv = $event.target.files[0]" /></div>
    <template #foot><button class="btn sec" @click="af = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveApp">حفظ</button></template>
  </Modal>
  <Modal v-if="offer" :title="`العرض الوظيفي — ${offer.app.name}`" @close="offer = null">
    <div class="notice info small">المادة 17: يتضمن العرض المسمى، والشركة صاحبة العمل، وموقع العمل، والأجر والبدلات والمزايا، ونمط العمل، وشروط المباشرة، وفترة التجربة. لا يحق لغير المخوَّل تقديم وعود مالية ملزمة.</div>
    <div class="fr"><div class="fg"><label>المسمى *</label><input v-model="offer.f.title" class="in" /></div><div class="fg"><label>الشركة</label><input :value="offer.app.company" class="in" disabled /></div><div class="fg"><label>موقع العمل *</label><input v-model="offer.f.location" class="in" /></div>
      <div class="fg"><label>الأجر الأساسي *</label><input v-model.number="offer.f.basic" type="number" min="0" class="in" /></div><div class="fg"><label>نمط العمل *</label><input v-model="offer.f.work_pattern" class="in" placeholder="دوام كامل 5 أيام × 9 ساعات" /></div><div class="fg"><label>تاريخ المباشرة المتوقع</label><DateInput v-model="offer.f.start_date" /></div>
      <div class="fg"><label>فترة التجربة (يوم)</label><input v-model.number="offer.f.probation_days" type="number" min="0" max="180" class="in" /></div></div>
    <div class="fg"><label>البدلات</label>
      <div v-for="(x, i) in offer.f.allowances" :key="i" class="row mb" style="gap:6px;flex-wrap:nowrap"><input v-model="x.name" class="in" placeholder="اسم البدل" /><input v-model.number="x.amount" type="number" min="0" class="in" style="max-width:140px" /><button class="btn ghost xs" @click="offer.f.allowances.splice(i, 1)"><Icon name="x" :size="14" /></button></div>
      <button class="btn ghost xs" @click="offer.f.allowances.push({ name: '', amount: 0 })">إضافة بدل</button></div>
    <div class="fg"><label>المزايا</label><textarea v-model="offer.f.benefits" class="in" rows="2" /></div>
    <div class="fg"><label>شروط المباشرة *</label><textarea v-model="offer.f.start_conditions" class="in" rows="2" placeholder="الفحص الطبي، التحقق من المؤهلات، تسليم المستندات" /></div>
    <p v-if="offer.f.probation_days < 180" class="tiny warn">تقصير التجربة عن 180 يومًا يتطلب موافقة الموارد البشرية والرئيس التنفيذي للمجموعة (المادة 20).</p>
    <template #foot><button class="btn sec" @click="offer = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveOffer">حفظ العرض</button></template>
  </Modal>
  <EmployeeForm v-if="prefill" :initial="prefill" @close="prefill = null" @saved="linked" />
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { fileUrl } from '../api'
import EmployeeForm from '../components/EmployeeForm.vue'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import { confirmBox, promptBox } from '../dialog'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtD } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const isHr = computed(() => auth.has('super', 'hr_mgr', 'hr_staff'))
const tab = ref(isHr.value ? 'apps' : 'openings'); const apps = ref([]); const openings = ref([]); const stages = ref([]); const stage = ref(''); const openingF = ref(null)
const of = ref(null); const af = ref(null); const cv = ref(null); const busy = ref(false); const prefill = ref(null); const converting = ref(null); const offer = ref(null)
const errMsg = (e) => Object.values(e.errors || {}).flat().join(' · ') || e.message
function newOpening() { of.value = { company_id: auth.emp?.company_id || auth.meta.companies[0]?.id, job_title_id: null, title: '', description: '', criteria: '', work_location: '', headcount: 1, in_plan: true, status: 'closed', public: false, stage: 'draft' } }
const lastNote = (o) => { const x = (o.approvals || []).filter((a) => a.reason).pop(); return x ? `${x.name}: ${x.reason}` : '' }
async function load() {
  if (isHr.value && !stages.value.length) stages.value = await api.get('/recruitment/stages')
  openings.value = await api.get('/recruitment/openings')
  if (isHr.value) apps.value = await api.get('/recruitment/applications', { params: { stage: stage.value || undefined, opening_id: openingF.value || undefined } })
}
async function saveOpening() {
  busy.value = true
  try {
    const o = of.value
    const body = { company_id: o.company_id, job_title_id: o.job_title_id, title: o.title, description: o.description, criteria: o.criteria, work_location: o.work_location, headcount: o.headcount, in_plan: !!o.in_plan, public: !!o.public, ...(o.stage === 'approved' ? { status: o.status } : {}) }
    if (o.id) await api.put(`/recruitment/openings/${o.id}`, body); else await api.post('/recruitment/openings', body)
    ui.toast(o.id ? 'حُفظ الشاغر' : 'حُفظ الشاغر مسودة — أرسله للاعتماد من القائمة', 'ok'); of.value = null; load()
  } catch (e) { ui.toast(errMsg(e), 'bad') } finally { busy.value = false }
}
async function decide(o, decision) {
  let reason = null
  if (decision !== 'approve') { reason = await promptBox(decision === 'return' ? 'سبب الإرجاع' : 'سبب الرفض'); if (!reason) return }
  else if (!await confirmBox(o.stage === 'draft' ? `إرسال «${o.title}» للاعتماد؟` : `اعتماد «${o.title}» في مرحلة: ${o.stage_label}؟`)) return
  try { const r = await api.post(`/recruitment/openings/${o.id}/decide`, { decision, reason }); ui.toast(r.stage_label, 'ok'); load() } catch (e) { ui.toast(errMsg(e), 'bad') }
}
async function saveApp() {
  busy.value = true
  try { const x = new FormData(); Object.entries(af.value).forEach(([k, v]) => { if (['company_id', 'opening_id', 'job_title_id', 'name', 'email', 'phone', 'notes', 'relation', 'stage', 'rating'].includes(k) && v !== null && v !== '' && v !== undefined) x.append(k, v) }); if (cv.value) x.append('cv', cv.value); await api.post(af.value.id ? `/recruitment/applications/${af.value.id}` : '/recruitment/applications', x); ui.toast('تم الحفظ', 'ok'); af.value = null; cv.value = null; load() } catch (e) { ui.toast(errMsg(e), 'bad') } finally { busy.value = false }
}
async function setStage(a, s) { try { await api.post(`/recruitment/applications/${a.id}`, { company_id: a.company_id, name: a.name, stage: s }) } catch (e) { ui.toast(errMsg(e), 'bad') } load() }
async function rate(a, r) { await api.post(`/recruitment/applications/${a.id}`, { company_id: a.company_id, name: a.name, rating: r }); load() }
async function disclose(a) {
  const conflict = await confirmBox(`هل لديك قرابة أو مصلحة مؤثرة مع المرشح «${a.name}»؟ (المادة 16: صاحب التعارض لا يشارك في القرار النهائي)`, { title: 'الإفصاح عن تعارض المصالح', confirmText: 'نعم، لدي تعارض', cancelText: 'لا يوجد تعارض', danger: false })
  let note = null
  if (conflict) { note = await promptBox('بيّن طبيعة القرابة أو المصلحة'); if (!note) return }
  try { await api.post(`/recruitment/applications/${a.id}/disclose`, { conflict, note }); ui.toast('سُجّل الإفصاح', 'ok'); load() } catch (e) { ui.toast(errMsg(e), 'bad') }
}
function openOffer(a) {
  const o = openings.value.find((x) => x.id === a.opening_id)
  offer.value = { app: a, f: { title: a.offer?.title || o?.title || a.job || '', location: a.offer?.location || o?.work_location || '', basic: a.offer?.basic ?? null, allowances: a.offer?.allowances ? JSON.parse(JSON.stringify(a.offer.allowances)) : [],
    benefits: a.offer?.benefits || '', work_pattern: a.offer?.work_pattern || '', start_conditions: a.offer?.start_conditions || '', start_date: a.offer?.start_date || null, probation_days: a.offer?.probation_days ?? 180 } }
}
async function saveOffer() {
  busy.value = true
  try { const f = offer.value.f; await api.post(`/recruitment/applications/${offer.value.app.id}/offer`, { ...f, allowances: f.allowances.filter((x) => x.name) }); ui.toast('حُفظ العرض الوظيفي', 'ok'); offer.value = null; load() } catch (e) { ui.toast(errMsg(e), 'bad') } finally { busy.value = false }
}
async function convert(a) {
  try {
    const r = await api.get(`/recruitment/applications/${a.id}/convert`)
    ui.toast('المادة 17: أكمل في ملف الموظف: ' + Object.values(r.required || {}).join('، '), 'info', 8000)
    converting.value = a; prefill.value = r.prefill
  } catch (e) { ui.toast(errMsg(e), 'bad') }
}
async function linked(emp) {
  if (converting.value && emp?.id) {
    try { await api.post(`/recruitment/applications/${converting.value.id}/link`, { employee_id: emp.id }); ui.toast('حُوّل المتقدم إلى موظف', 'ok') } catch (e) { ui.toast(errMsg(e), 'bad', 9000) }
  }
  prefill.value = null; converting.value = null; load()
}
onMounted(load)
</script>
