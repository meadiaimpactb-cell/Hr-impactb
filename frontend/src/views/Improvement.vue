<template>
  <div class="page-h">
    <div><h1>معالجة ضعف الأداء وخطط التحسين</h1><p>اللائحة §75: شهر أحمر ← جلسة تصحيح وخطة دعم 30 يومًا · شهران متتاليان أو ثلاثة خلال ستة أو نتيجة سنوية أقل من 70 ← خطة تحسين 60–90 يومًا بمراجعة كل أسبوعين يعتمدها الرئيس التنفيذي للشركة · تنجح الخطة بـ 70 فأكثر في آخر شهرين، وإلا تمديد واحد ≤ 30 يومًا أو نقل أو إجراء تعاقدي مشروع</p></div>
    <div class="acts"><button v-if="auth.isApprover" class="btn pri sm" @click="openForm()"><Icon name="plus" :size="16" /> خطة جديدة</button></div>
  </div>

  <template v-if="auth.isApprover">
    <div v-if="suggestions.length" class="card">
      <h3>يستلزمون معالجة الآن <span class="sub">حالات حمراء منشورة بلا خطة مفتوحة</span></h3>
      <div v-dt class="tw"><table><thead><tr><th>الموظف</th><th>السبب</th><th>آخر شهر</th><th>الدرجة</th><th /></tr></thead>
        <tbody><tr v-for="s in suggestions" :key="s.employee.id"><td><b>{{ s.employee.name }}</b></td><td>{{ s.reason }}</td><td class="num">{{ s.last }}</td><td class="num bad"><b>{{ s.score }}</b></td><td><button class="btn sec xs" @click="openForm(s)">{{ s.stage === 'session' ? 'جلسة تصحيح' : 'خطة تحسين' }}</button></td></tr></tbody></table></div>
    </div>
    <div class="card">
      <h3>الخطط</h3>
      <div v-if="!plans.length" class="empty">لا خطط</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>المرحلة</th><th>من → إلى</th><th>الأهداف</th><th>المراجعات</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="p in plans" :key="p.id"><td><b>{{ p.employee?.name }}</b><span class="tiny muted">{{ p.company }}</span></td><td class="small">{{ p.stage_label }}</td><td class="num small">{{ p.start }} → {{ p.end }}<div v-if="['active', 'extended'].includes(p.status)" class="tiny muted">متبقٍ {{ p.days_left }} يوم</div></td>
          <td class="small">{{ (p.goals || []).filter((g) => g.status === 'done').length }}/{{ (p.goals || []).length }} منجز</td><td class="num">{{ (p.reviews || []).length }}</td><td><span class="pill" :class="SC[p.status]">{{ p.status_label }}</span></td>
          <td><button class="btn ghost xs" @click="d = p">فتح</button></td></tr></tbody></table></div>
    </div>
  </template>
  <div v-else class="card">
    <div v-if="!mine.length" class="empty"><div class="ic"><Icon name="chart" :size="40" :stroke="1.4" /></div>لا خطط تحسين في ملفك</div>
    <div v-for="p in mine" :key="p.id" class="card" style="margin:0 0 10px">
      <div class="row" style="justify-content:space-between"><b>{{ p.stage_label }}</b><span class="pill" :class="SC[p.status]">{{ p.status_label }}</span></div>
      <div class="small muted">{{ p.start }} → {{ p.end }} · {{ p.trigger }}</div>
      <ul class="small"><li v-for="(g, i) in p.goals" :key="i">{{ g.goal }} <span class="tiny muted">{{ g.measure }} {{ g.due ? '· حتى ' + g.due : '' }}</span> <span class="pill" :class="GC[g.status]">{{ GL[g.status] }}</span></li></ul>
      <div v-if="p.support" class="small"><b>الدعم:</b> {{ p.support }}</div>
      <div v-for="(r, i) in p.reviews" :key="i" class="tiny muted">{{ r.at }} — {{ r.by }}: {{ r.note }}</div>
      <div v-if="p.outcome" class="notice info small mt">{{ p.outcome }}</div>
    </div>
  </div>

  <Modal v-if="d" :title="`${d.stage_label} — ${d.employee?.name}`" size="wide" @close="d = null">
    <div class="row mb" style="gap:6px"><span class="pill" :class="SC[d.status]">{{ d.status_label }}</span><span class="small muted">{{ d.start }} → {{ d.end }} · {{ d.trigger }}</span></div>
    <div v-if="d.support" class="small mb"><b>الدعم والتدريب:</b> {{ d.support }}</div>
    <div class="tw"><table><thead><tr><th>الهدف</th><th>المقياس</th><th>الموعد</th><th>الحالة</th></tr></thead>
      <tbody><tr v-for="(g, i) in d.goals" :key="i"><td>{{ g.goal }}</td><td class="small">{{ g.measure }}</td><td class="num small">{{ g.due || '—' }}</td>
        <td><select v-if="['active', 'extended'].includes(d.status)" v-model="g.status" class="in" style="min-height:30px;padding:2px 6px"><option v-for="(l, k) in GL" :key="k" :value="k">{{ l }}</option></select><span v-else class="pill" :class="GC[g.status]">{{ GL[g.status] }}</span></td></tr></tbody></table></div>
    <h4 style="margin:12px 0 4px">المراجعات (كل أسبوعين)</h4>
    <div v-if="!(d.reviews || []).length" class="small muted">لا مراجعات بعد</div>
    <div v-for="(r, i) in d.reviews" :key="i" class="small">{{ r.at }} — <b>{{ r.by }}</b>: {{ r.note }}</div>
    <div v-if="['active', 'extended'].includes(d.status)" class="fg mt"><label>مراجعة جديدة</label><textarea v-model="rev" class="in" rows="2" placeholder="ما تحقق، وما يحتاج دعمًا…" /></div>
    <div v-if="['active', 'extended'].includes(d.status) && (auth.isHr || auth.has('super', 'ceo'))" class="fr mt">
      <div class="fg"><label>قرار الإغلاق</label><select v-model="cl.decision" class="in"><option value="succeeded">نجحت الخطة (≥ 70 في آخر شهرين)</option><option value="extend" :disabled="d.status === 'extended'">تمديد مرة واحدة ≤ 30 يومًا</option><option value="failed">لم تنجح — نقل إلى دور مناسب / إجراء تعاقدي</option><option value="closed">إغلاق</option></select></div>
      <div v-if="cl.decision === 'extend'" class="fg"><label>تاريخ النهاية الجديد</label><DateInput v-model="cl.new_end" :min="d.end" /></div>
      <div class="fg" style="grid-column:span 2"><label>النتيجة والقرار *</label><input v-model="cl.outcome" class="in" /></div>
    </div>
    <div v-if="d.outcome" class="notice info small mt">{{ d.outcome }}</div>
    <template #foot>
      <button class="btn sec" @click="d = null">إغلاق</button>
      <button v-if="d.status === 'draft' && auth.has('super', 'ceo')" class="btn okb" :class="{ loading: busy }" @click="act('approve')">اعتماد الخطة</button>
      <button v-if="['active', 'extended'].includes(d.status)" class="btn pri" :class="{ loading: busy }" @click="act('review', { note: rev, goals: d.goals })">حفظ المراجعة</button>
      <button v-if="['active', 'extended'].includes(d.status) && (auth.isHr || auth.has('super', 'ceo'))" class="btn dan" :class="{ loading: busy }" @click="act('close', cl)">تنفيذ القرار</button>
    </template>
  </Modal>

  <Modal v-if="f" :title="f.stage === 'session' ? 'جلسة تصحيح وخطة دعم (30 يومًا)' : 'خطة تحسين أداء (60–90 يومًا)'" size="wide" @close="f = null">
    <div class="fr"><div class="fg"><label>الموظف *</label><SearchSelect v-model="f.employee_id" :options="empOptions" /></div><div class="fg"><label>المرحلة</label><select v-model="f.stage" class="in" @change="f.end = defaultEnd(f.start, f.stage)"><option value="session">جلسة تصحيح (شهر أحمر)</option><option value="plan">خطة تحسين (شهران متتاليان / 3 في 6)</option></select></div></div>
    <div class="fg"><label>سبب الخطة</label><input v-model="f.trigger" class="in" /></div>
    <div class="fr"><div class="fg"><label>البداية *</label><DateInput v-model="f.start" @update:model-value="f.end = defaultEnd(f.start, f.stage)" /></div><div class="fg"><label>النهاية *</label><DateInput v-model="f.end" :min="f.start" /></div></div>
    <h4 style="margin:10px 0 4px">الأهداف القابلة للقياس <button class="btn ghost xs" @click="f.goals.push({ goal: '', measure: '', due: '' })"><Icon name="plus" :size="13" /> هدف</button></h4>
    <div v-for="(g, i) in f.goals" :key="i" class="fr3" style="margin-bottom:4px"><input v-model="g.goal" class="in" placeholder="الهدف" /><input v-model="g.measure" class="in" placeholder="المقياس" /><div class="row" style="gap:4px;flex-wrap:nowrap"><DateInput v-model="g.due" /><button class="btn ghost xs" @click="f.goals.splice(i, 1)"><Icon name="x" :size="13" /></button></div></div>
    <div class="fg mt"><label>الدعم والتدريب المقدَّم</label><textarea v-model="f.support" class="in" rows="2" /></div>
    <p class="tiny muted">خطة التحسين تحتاج اعتماد الرئيس التنفيذي للشركة ثم تُبلَّغ للموظف كتابيًا. خطة الدعم بعد جلسة التصحيح تُفعَّل مباشرة.</p>
    <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="save">حفظ</button></template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import DateInput from '../components/DateInput.vue'
import Modal from '../components/Modal.vue'
import SearchSelect from '../components/SearchSelect.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { today } from '../utils'

const auth = useAuth(); const ui = useUi()
const SC = { draft: 'gray', active: 'info', extended: 'warn', succeeded: 'ok', failed: 'bad', closed: 'gray' }
const GL = { open: 'مفتوح', progress: 'قيد التنفيذ', done: 'تحقق', missed: 'لم يتحقق' }
const GC = { open: 'gray', progress: 'info', done: 'ok', missed: 'bad' }
const plans = ref([]); const suggestions = ref([]); const mine = ref([]); const d = ref(null); const f = ref(null); const emps = ref([]); const busy = ref(false); const rev = ref('')
const cl = ref({ decision: 'succeeded', outcome: '', new_end: '' })
const empOptions = computed(() => emps.value.map((e) => ({ value: e.id, label: e.name, sub: e.emp_no })))
const addDays = (iso, n) => { const x = new Date(iso); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10) }
const defaultEnd = (start, stage) => addDays(start || today(), stage === 'session' ? 30 : 75)
async function load() {
  if (auth.isApprover) { const r = await api.get('/improvement'); plans.value = r.plans; suggestions.value = r.suggestions }
  else mine.value = await api.get('/improvement/mine')
}
async function openForm(s) {
  if (!emps.value.length) emps.value = await api.get('/employees/options')
  const stage = s?.stage || 'plan'
  f.value = { employee_id: s?.employee?.id || null, stage, trigger: s?.reason || '', start: today(), end: defaultEnd(today(), stage), goals: [{ goal: '', measure: '', due: '' }], support: '' }
}
async function save() {
  if (!f.value.employee_id || !f.value.goals.some((g) => g.goal?.trim())) return ui.toast('الموظف وهدف واحد على الأقل إلزاميان', 'warn')
  busy.value = true
  try { await api.post('/improvement', { ...f.value, goals: f.value.goals.filter((g) => g.goal?.trim()).map((g) => ({ ...g, due: g.due || null })) }); ui.toast('حُفظت الخطة', 'ok'); f.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function act(action, body) {
  if (action === 'review' && !rev.value.trim()) return ui.toast('اكتب المراجعة', 'warn')
  if (action === 'close' && !cl.value.outcome?.trim()) return ui.toast('النتيجة والقرار إلزاميان', 'warn')
  busy.value = true
  try { d.value = await api.post(`/improvement/${d.value.id}/${action}`, body); rev.value = ''; ui.toast('تم', 'ok'); load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
onMounted(load)
</script>
