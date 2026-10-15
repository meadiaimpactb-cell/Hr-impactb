<template>
  <div class="page-h">
    <div><h1>{{ t('التوظيف') }}</h1><p>شواغر لكل شركة (مع صفحة تقديم عامة اختيارية) ومتقدمون يمرون بمراحل من القوائم العامة، وتحويل المقبول إلى ملف موظف مُعبّأ مسبقًا</p></div>
    <div class="acts"><button class="btn sec sm" @click="of = { company_id: auth.meta.companies[0]?.id, job_title_id: null, title: '', description: '', headcount: 1, status: 'open', public: false }"><Icon name="plus" :size="16" /> شاغر</button><button class="btn pri sm" @click="af = { company_id: auth.meta.companies[0]?.id, opening_id: null, job_title_id: null, name: '', email: '', phone: '', notes: '' }"><Icon name="plus" :size="16" /> متقدم</button></div>
  </div>
  <div class="tabs"><button :class="{ on: tab === 'apps' }" @click="tab = 'apps'">المتقدمون</button><button :class="{ on: tab === 'openings' }" @click="tab = 'openings'">الشواغر</button></div>

  <template v-if="tab === 'apps'">
    <div class="grid mb" :style="`grid-template-columns:repeat(${stages.length},1fr);gap:10px`">
      <Kpi v-for="s in stages" :key="s.code" :label="s.name" :value="apps.filter((a) => a.stage === s.code).length" :color="s.code === 'accepted' ? 'g' : s.code === 'rejected' ? 'r' : 'b'" />
    </div>
    <div class="card">
      <div class="row mb"><select v-model="stage" class="in" style="width:auto" @change="load"><option value="">كل المراحل</option><option v-for="s in stages" :key="s.code" :value="s.code">{{ s.name }}</option></select><select v-model="openingF" class="in" style="width:auto" @change="load"><option :value="null">كل الشواغر</option><option v-for="o in openings" :key="o.id" :value="o.id">{{ o.title }}</option></select></div>
      <div v-if="!apps.length" class="empty">لا متقدمين</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>المتقدم</th><th>الشاغر</th><th>الشركة</th><th>التواصل</th><th>المرحلة</th><th>التقييم</th><th>المصدر</th><th>التاريخ</th><th /></tr></thead>
        <tbody><tr v-for="a in apps" :key="a.id">
          <td><b>{{ a.name }}</b><div v-if="a.notes" class="tiny muted">{{ a.notes.slice(0, 60) }}</div></td><td>{{ a.opening || a.job || '—' }}</td><td class="small">{{ a.company }}</td><td class="small" style="direction:ltr">{{ a.email }}<br />{{ a.phone }}</td>
          <td><select :value="a.stage" class="in" style="width:auto" :disabled="!!a.employee_id" @change="setStage(a, $event.target.value)"><option v-for="s in stages" :key="s.code" :value="s.code">{{ s.name }}</option></select></td>
          <td><span v-for="i in 5" :key="i" style="cursor:pointer" :class="i <= (a.rating || 0) ? 'ok' : 'muted'" @click="rate(a, i)">★</span></td><td>{{ a.source === 'public' ? 'صفحة التقديم' : 'يدوي' }}</td><td class="small">{{ fmtD(a.created_at) }}</td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><a v-if="a.cv" href="#" class="btn ghost xs" @click.prevent="fileUrl(a.cv)"><Icon name="download" :size="13" /> CV</a><button class="btn ghost xs" @click="af = { ...a }">تعديل</button><button v-if="a.stage === 'accepted' && !a.employee_id" class="btn okb xs" @click="convert(a)">تحويل إلى موظف</button><span v-if="a.employee_id" class="pill ok">مُعيَّن</span></td>
        </tr></tbody></table></div>
    </div>
  </template>
  <div v-else class="card">
    <div v-if="!openings.length" class="empty">لا شواغر</div>
    <div v-dt v-else class="tw"><table><thead><tr><th>الشاغر</th><th>الشركة</th><th>الوظيفة</th><th>العدد</th><th>المتقدمون</th><th>عام</th><th>الحالة</th><th /></tr></thead>
      <tbody><tr v-for="o in openings" :key="o.id"><td><b>{{ o.title }}</b></td><td>{{ o.company_name }}</td><td>{{ o.job }}</td><td>{{ o.headcount }}</td><td>{{ o.applications_count }}</td><td>{{ o.public ? 'نعم' : '—' }}</td><td><span class="pill" :class="o.status === 'open' ? 'ok' : 'gray'">{{ o.status === 'open' ? 'مفتوح' : 'مغلق' }}</span></td><td><button class="btn ghost xs" @click="of = { ...o }">تعديل</button></td></tr></tbody></table></div>
    <p class="tiny muted">الشواغر العامة تظهر على <code>/api/v1/careers</code> ويمكن التقديم عليها من موقع الشركة عبر <code>POST /api/v1/careers/{id}/apply</code>.</p>
  </div>

  <Modal v-if="of" :title="of.id ? 'تعديل شاغر' : 'شاغر جديد'" @close="of = null">
    <div class="fr"><div class="fg"><label>الشركة</label><select v-model="of.company_id" class="in"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div><div class="fg"><label>الوظيفة</label><select v-model="of.job_title_id" class="in"><option :value="null">—</option><option v-for="j in auth.meta.job_titles" :key="j.id" :value="j.id">{{ j.name }}</option></select></div>
      <div class="fg"><label>العنوان *</label><input v-model="of.title" class="in" /></div><div class="fg"><label>العدد المطلوب</label><input v-model.number="of.headcount" type="number" min="1" class="in" /></div></div>
    <div class="fg"><label>الوصف</label><textarea v-model="of.description" class="in" rows="4" /></div>
    <div class="row"><label class="chk"><input v-model="of.public" type="checkbox" /> يقبل التقديم العام</label><label class="chk"><select v-model="of.status" class="in" style="width:auto"><option value="open">مفتوح</option><option value="closed">مغلق</option></select></label></div>
    <template #foot><button class="btn sec" @click="of = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveOpening">حفظ</button></template>
  </Modal>
  <Modal v-if="af" :title="af.id ? 'تعديل متقدم' : 'متقدم جديد'" @close="af = null">
    <div class="fr"><div class="fg"><label>الاسم *</label><input v-model="af.name" class="in" /></div><div class="fg"><label>البريد</label><input v-model="af.email" class="in" style="direction:ltr" /></div><div class="fg"><label>الجوال</label><input v-model="af.phone" class="in" style="direction:ltr" /></div>
      <div class="fg"><label>الشركة</label><select v-model="af.company_id" class="in"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div><div class="fg"><label>الشاغر</label><select v-model="af.opening_id" class="in"><option :value="null">—</option><option v-for="o in openings" :key="o.id" :value="o.id">{{ o.title }}</option></select></div><div class="fg"><label>الوظيفة</label><select v-model="af.job_title_id" class="in"><option :value="null">—</option><option v-for="j in auth.meta.job_titles" :key="j.id" :value="j.id">{{ j.name }}</option></select></div></div>
    <div class="fg"><label>ملاحظات</label><textarea v-model="af.notes" class="in" rows="3" /></div>
    <div class="fg"><label>السيرة الذاتية</label><input type="file" class="in" @change="cv = $event.target.files[0]" /></div>
    <template #foot><button class="btn sec" @click="af = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveApp">حفظ</button></template>
  </Modal>
  <EmployeeForm v-if="prefill" :initial="prefill" @close="prefill = null" @saved="linked" />
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { fileUrl } from '../api'
import EmployeeForm from '../components/EmployeeForm.vue'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtD } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const tab = ref('apps'); const apps = ref([]); const openings = ref([]); const stages = ref([]); const stage = ref(''); const openingF = ref(null)
const of = ref(null); const af = ref(null); const cv = ref(null); const busy = ref(false); const prefill = ref(null); const converting = ref(null)
async function load() {
  if (!stages.value.length) stages.value = await api.get('/recruitment/stages')
  ;[apps.value, openings.value] = await Promise.all([api.get('/recruitment/applications', { params: { stage: stage.value || undefined, opening_id: openingF.value || undefined } }), api.get('/recruitment/openings')])
}
async function saveOpening() { busy.value = true; try { const o = of.value; if (o.id) await api.put(`/recruitment/openings/${o.id}`, o); else await api.post('/recruitment/openings', o); ui.toast('حُفظ الشاغر', 'ok'); of.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false } }
async function saveApp() {
  busy.value = true
  try { const x = new FormData(); Object.entries(af.value).forEach(([k, v]) => { if (['company_id', 'opening_id', 'job_title_id', 'name', 'email', 'phone', 'notes', 'stage', 'rating'].includes(k) && v !== null && v !== '' && v !== undefined) x.append(k, v) }); if (cv.value) x.append('cv', cv.value); await api.post(af.value.id ? `/recruitment/applications/${af.value.id}` : '/recruitment/applications', x); ui.toast('تم الحفظ', 'ok'); af.value = null; cv.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
async function setStage(a, s) { await api.post(`/recruitment/applications/${a.id}`, { company_id: a.company_id, name: a.name, stage: s }); load() }
async function rate(a, r) { await api.post(`/recruitment/applications/${a.id}`, { company_id: a.company_id, name: a.name, rating: r }); load() }
async function convert(a) { const r = await api.get(`/recruitment/applications/${a.id}/convert`); converting.value = a; prefill.value = r.prefill }
async function linked(emp) { if (converting.value && emp?.id) await api.post(`/recruitment/applications/${converting.value.id}/link`, { employee_id: emp.id }); prefill.value = null; converting.value = null; ui.toast('حُوّل المتقدم إلى موظف', 'ok'); load() }
onMounted(load)
</script>
