<template>
  <div class="page-h">
    <div><h1>{{ t('التقارير الدورية') }}</h1><p>تقرير شهري لكل موظف من قالب مرن (بنود بأوزان + بنود شخصية) يمر بالمدير ثم الموارد البشرية ثم الرئيس ويولّد التقييم آليًا · تقرير أسبوعي قصير يؤثر في محور الانضباط</p></div>
    <div class="acts"><input v-model="period" type="month" class="in" style="width:auto" @change="load" /></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <!-- My monthly report -->
  <template v-if="tab === 'mine'">
    <div v-if="!rep"><div class="skel" v-for="i in 4" :key="i" /></div>
    <template v-else>
      <div class="row mb">
        <span class="pill" :class="STATUS_COLOR[rep.status]">{{ rep.status_label }}</span>
        <span v-if="rep.late" class="pill bad">متأخر عن موعد الرفع (يوم {{ rep.template?.due_day }})</span>
        <span class="small muted">القالب: {{ rep.template?.name }}</span>
        <span v-if="rep.return_note" class="notice bad" style="margin:0">سبب الإرجاع: {{ rep.return_note }}</span>
        <span v-if="rep.locked" class="pill bad">الشهر مقفل — اطلب فتحه من «طلب جديد ← فتح شهر مقفل»</span><span v-else-if="rep.reopen_until" class="pill warn">مفتوح استثنائيًا حتى {{ rep.reopen_until }}</span>
      </div>
      <div v-if="rep.preview" class="grid g5 mb">
        <Kpi label="الإنجاز" :value="rep.preview.axes.achieve" :hint="`من ${rep.preview.weights.achieve}`" color="b" />
        <Kpi label="الحضور" :value="rep.preview.exempt_attendance ? 'معفى' : rep.preview.axes.attend" :hint="`من ${rep.preview.weights.attend}`" color="g" />
        <Kpi label="التطوير" :value="rep.preview.axes.dev" :hint="`من ${rep.preview.weights.dev}`" color="p" />
        <Kpi label="الانضباط" :value="rep.preview.axes.disc" :hint="`من ${rep.preview.weights.disc}`" color="y" />
        <Kpi label="الدرجة المتوقعة" :value="rep.preview.score" :hint="rep.preview.grade.label" :color="rep.preview.grade.pass ? 'g' : 'r'" />
      </div>
      <div v-if="rep.can_edit" class="notice info row" style="gap:10px;align-items:center;flex-wrap:wrap">
        <span><b>التعبئة عبر Excel:</b> حمّل نموذجك الشهري، وفيه مهامك ومؤشراتك جاهزة. عبّئ المنجز وإنجاز المؤشر والمستهدفات والعقبات والحلول والملاحظات، ثم ارفعه هنا مباشرة دون بريد.</span>
        <span class="spacer" />
        <button class="btn sec xs" @click="downloadTemplate"><Icon name="download" :size="14" /> تحميل نموذج Excel</button>
        <label class="btn pri xs" style="cursor:pointer"><Icon name="upload" :size="14" /> رفع النموذج المعبّأ<input type="file" accept=".xlsx,.xls,.csv" hidden @change="uploadTemplate" /></label>
      </div>
      <div class="card">
        <h3>بنود التقرير <span class="sub">مجموع الأوزان {{ totalWeight }}</span>
          <span v-if="rep.can_edit && rep.template?.allow_employee_items && empItems < rep.template.max_employee_items" class="acts"><button class="btn sec xs" @click="addItem"><Icon name="plus" :size="14" /> بند شخصي (حتى {{ rep.template.max_employee_items }}، وزن ≤ {{ rep.template.employee_item_weight_max }})</button></span>
        </h3>
        <ReportItems :items="rep.items" :mode="rep.can_edit ? 'edit' : 'view'" :max-emp-weight="rep.template?.employee_item_weight_max || 15" @remove="(it) => rep.items.splice(rep.items.indexOf(it), 1)" />
      </div>
      <div class="card">
        <h3>التطوير هذا الشهر</h3>
        <div class="fr">
          <div class="fg"><label>البرنامج</label><input v-model="rep.dev.program" class="in" :disabled="!rep.can_edit" /></div>
          <div class="fg"><label>الجهة</label><input v-model="rep.dev.provider" class="in" :disabled="!rep.can_edit" /></div>
        </div>
        <div class="row"><label class="chk"><input v-model="rep.dev.completed" type="checkbox" :disabled="!rep.can_edit" /> أكملت البرنامج</label><label class="chk"><input v-model="rep.dev.applied" type="checkbox" :disabled="!rep.can_edit" /> طبّقت ما تعلمته في العمل</label></div>
        <div class="fg"><label>ملاحظات</label><textarea v-model="rep.dev.notes" class="in" rows="2" :disabled="!rep.can_edit" /></div>
      </div>
      <div v-if="rep.timeline.length" class="card"><h3>المسار</h3><ul class="timeline"><li v-for="(x, i) in rep.timeline" :key="i"><b>{{ x.label }}</b> <span class="tiny muted">{{ fmtDT(x.at) }}</span></li></ul></div>
      <div v-if="rep.can_edit" class="row">
        <button class="btn sec" :class="{ loading: busy }" @click="save(false)"><Icon name="check" :size="15" /> حفظ مسودة</button>
        <button class="btn pri" :class="{ loading: busy }" @click="save(true)"><Icon name="send" :size="15" flip-rtl /> إرسال للمدير</button>
      </div>
    </template>
    <div class="card mt">
      <h3>تقاريري السابقة</h3>
      <div v-if="!history.length" class="empty">لا تقارير</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الشهر</th><th>الحالة</th><th>البنود</th><th>رُفع</th></tr></thead>
        <tbody><tr v-for="h in history" :key="h.id"><td><b>{{ fmtYM(h.period) }}</b></td><td><span class="pill" :class="STATUS_COLOR[h.status]">{{ h.status_label }}</span><span v-if="h.late" class="pill bad">متأخر</span></td><td>{{ h.items_done }}/{{ h.items_total }}</td><td class="small">{{ fmtDT(h.submitted_at) }}</td></tr></tbody></table></div>
    </div>
  </template>

  <!-- Team monthly reports -->
  <template v-else-if="tab === 'team'">
    <div class="grid g4 mb">
      <Kpi label="لم يُرفع" :value="team.filter((x) => !x.report || ['draft', 'returned'].includes(x.report.status)).length" color="r" />
      <Kpi label="بانتظار المراجعة" :value="team.filter((x) => x.report && ['submitted', 'mgr_reviewed', 'hr_reviewed'].includes(x.report.status)).length" color="y" />
      <Kpi label="معتمد" :value="team.filter((x) => x.report?.status === 'approved').length" color="g" />
      <Kpi label="الإجمالي" :value="team.length" color="b" />
    </div>
    <div class="row mb" style="gap:8px;flex-wrap:wrap">
      <button v-if="auth.isHr" class="btn sec sm" @click="remind"><Icon name="bell" :size="14" /> تذكير المتأخرين عن {{ fmtYM(period) }}</button>
      <span class="spacer" />
      <button class="btn sec sm" :disabled="!picked.length" :class="{ loading: dl === 'sel' }" @click="downloadReports(picked, 'sel')"><Icon name="download" :size="14" /> تحميل المحدد ({{ picked.length }})</button>
      <button class="btn pri sm" :disabled="!team.length" :class="{ loading: dl === 'all' }" @click="downloadReports(team.map((x) => x.employee.id), 'all')"><Icon name="download" :size="14" /> تحميل تقارير الكل ({{ team.length }})</button>
    </div>
    <div class="card">
      <div v-dt class="tw"><table><thead><tr><th style="width:34px"><input type="checkbox" :checked="team.length && picked.length === team.length" title="تحديد الكل" @change="picked = $event.target.checked ? team.map((x) => x.employee.id) : []" /></th><th>الموظف</th><th>الشركة</th><th>الحالة</th><th>المرحلة الحالية</th><th>البنود</th><th /></tr></thead>
        <tbody><tr v-for="x in team" :key="x.employee.id">
          <td><input v-model="picked" type="checkbox" :value="x.employee.id" /></td>
          <td><b>{{ x.employee.name }}</b><span class="tiny muted">{{ x.employee.emp_no }}</span></td><td>{{ x.company }}</td>
          <td><span v-if="x.report" class="pill" :class="STATUS_COLOR[x.report.status]">{{ x.report.status_label }}</span><span v-else class="pill gray">لم يبدأ</span></td>
          <td class="small">{{ x.report ? STAGE_ACTOR[x.report.status] : 'الموظف' }}</td>
          <td>{{ x.report ? `${x.report.items_done}/${x.report.items_total}` : '—' }}</td>
          <td><div class="row" style="gap:4px;flex-wrap:nowrap;justify-content:flex-end"><button v-if="x.report" class="btn ghost xs" @click="openReview(x.report.id)">فتح</button><button class="btn ghost xs" :title="x.report ? 'تحميل التقرير Excel' : 'تحميل النموذج الفارغ'" :class="{ loading: dl === x.employee.id }" @click="downloadReports([x.employee.id], x.employee.id)"><Icon name="download" :size="14" /></button></div></td>
        </tr></tbody></table></div>
    </div>
  </template>

  <!-- Weekly -->
  <template v-else-if="tab === 'weekly'">
    <div class="card">
      <h3>أسابيعي <span class="sub">اللائحة §34: الخطة في أول يوم من الأسبوع ويعتمدها المشرف، والمنجز في آخر يوم منه أو أول يوم من الأسبوع التالي بحد أقصى · المتأخر برتقالي وغير المرفوع أحمر</span></h3>
      <div v-if="!weeks.length" class="empty">لا أسابيع مطلوبة منك هذا الشهر</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الأسبوع</th><th>الخطة</th><th>التقرير</th><th>البنود</th><th>ما أُنجز</th><th /></tr></thead>
        <tbody><tr v-for="w in weeks" :key="w.id">
          <td class="num">{{ w.week_start }} → {{ w.week_end }}</td>
          <td><span class="pill" :class="{ none: 'gray', submitted: 'info', approved: 'ok' }[w.plan_status || 'none']">{{ PLAN[w.plan_status || 'none'] }}</span></td>
          <td><span class="pill" :class="{ submitted: 'ok', late: 'warn', missing: 'bad' }[w.status]">{{ WEEK[w.status] }}</span></td>
          <td class="small">{{ (w.items || []).length ? `${(w.items || []).length} بند` : '—' }}</td>
          <td class="small">{{ w.done || (w.items || []).filter((i) => i.achieved).map((i) => i.achieved).join(' · ') || '—' }}</td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><button v-if="(w.plan_status || 'none') === 'none'" class="btn sec xs" @click="openWeek(w, 'plan')">خطة الأسبوع</button><button class="btn ghost xs" @click="openWeek(w, 'report')">{{ w.status === 'missing' ? 'رفع المنجز' : 'تعديل' }}</button></td>
        </tr></tbody></table></div>
    </div>
    <div v-if="auth.isApprover" class="card">
      <h3>لوحة أسابيع الفريق</h3>
      <div v-dt class="tw"><table><thead><tr><th>الموظف</th><th v-for="ws in weekStarts" :key="ws" class="num">{{ ws.slice(5) }}</th></tr></thead>
        <tbody><tr v-for="(rows, name) in teamWeeksByEmp" :key="name"><td><b>{{ name }}</b></td>
          <td v-for="ws in weekStarts" :key="ws">
            <template v-if="rows[ws]"><span class="pill" :class="{ submitted: 'ok', late: 'warn', missing: 'bad' }[rows[ws].status]" style="cursor:pointer" @click="teamWeek = rows[ws]">{{ WEEK[rows[ws].status] }}</span><span v-if="rows[ws].plan_status === 'submitted'" class="pill info" style="cursor:pointer" title="خطة بانتظار اعتمادك" @click="teamWeek = rows[ws]">خطة</span></template>
            <span v-else class="muted">—</span>
          </td>
        </tr></tbody></table></div>
    </div>
  </template>

  <!-- Templates -->
  <template v-else>
    <div class="card">
      <h3>قوالب التقارير <span class="sub">يُطبَّق نموذج الموظف أولًا، ثم الوظيفة، ثم الشركة، ثم المجموعة</span><span class="acts"><button class="btn sec xs" @click="tplForm = { name: '', scope: 'group', scope_id: null, items: [{ title: '', target: '', indicator: '', weight: 100 }], allow_employee_items: true, max_employee_items: 3, employee_item_weight_max: 15, due_day: 5, active: true }"><Icon name="plus" :size="14" /> قالب</button></span></h3>
      <div v-dt class="tw"><table><thead><tr><th>القالب</th><th>النطاق</th><th>البنود</th><th>بنود شخصية</th><th>موعد الرفع</th><th>النسخة</th><th /></tr></thead>
        <tbody><tr v-for="tp in templates" :key="tp.id">
          <td><b>{{ tp.name }}</b></td><td>{{ SCOPE[tp.scope] }} {{ scopeName(tp) }}</td><td class="small"><b>{{ tp.items.length }} بند</b>: {{ tp.items.slice(0, 3).map((i) => i.title).join(' · ') }}{{ tp.items.length > 3 ? ' …' : '' }}</td>
          <td>{{ tp.allow_employee_items ? `حتى ${tp.max_employee_items} بوزن ≤ ${tp.employee_item_weight_max}` : '—' }}</td><td>يوم {{ tp.due_day }}</td><td>v{{ tp.version }}</td>
          <td><button class="btn ghost xs" @click="tplForm = JSON.parse(JSON.stringify(tp))">تعديل</button></td>
        </tr></tbody></table></div>
    </div>
  </template>


  <Modal v-if="weekForm" :title="`${weekMode === 'plan' ? 'خطة' : 'تقرير'} الأسبوع ${weekForm.week_start}`" size="wide" @close="weekForm = null">
    <div class="row mb" style="gap:6px"><button class="btn sec xs" @click="fromMonthly"><Icon name="download" :size="13" /> جلب البنود من التقرير الشهري</button><button class="btn ghost xs" @click="weekForm.items.push({ axis: '', title: '', kpi: '', target: '', achieved: '' })"><Icon name="plus" :size="13" /> بند</button><span class="tiny muted">المحور · البند · المؤشر · المستهدف الأسبوعي{{ weekMode === 'report' ? ' · المنجز' : '' }}</span></div>
    <div class="tw"><table class="wk"><thead><tr><th>المحور</th><th>البند</th><th>المؤشر</th><th>المستهدف الأسبوعي</th><th v-if="weekMode === 'report'">المنجز</th><th /></tr></thead>
      <tbody><tr v-for="(it, i) in weekForm.items" :key="i">
        <td><input v-model="it.axis" class="in" /></td><td><input v-model="it.title" class="in" /></td><td><input v-model="it.kpi" class="in" /></td><td><input v-model="it.target" class="in" /></td>
        <td v-if="weekMode === 'report'"><textarea v-model="it.achieved" class="in" rows="1" /></td>
        <td><button class="btn ghost xs" @click="weekForm.items.splice(i, 1)"><Icon name="x" :size="13" /></button></td>
      </tr></tbody></table></div>
    <template v-if="weekMode === 'report'">
      <div class="fg mt"><label>ملخص ما أُنجز</label><textarea v-model="weekForm.done" class="in" rows="2" /></div>
      <div class="fr"><div class="fg"><label>العقبات</label><input v-model="weekForm.blockers" class="in" /></div><div class="fg"><label>خطة الأسبوع القادم</label><input v-model="weekForm.next" class="in" /></div></div>
    </template>
    <template #foot><button class="btn sec" @click="weekForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="weekMode === 'plan' ? submitPlan() : submitWeek()">{{ weekMode === 'plan' ? 'رفع الخطة لاعتماد المشرف' : 'رفع التقرير' }}</button></template>
  </Modal>

  <Modal v-if="teamWeek" :title="`${teamWeek.employee?.name} — أسبوع ${teamWeek.week_start}`" size="wide" @close="teamWeek = null">
    <div class="row mb" style="gap:6px"><span class="pill" :class="{ none: 'gray', submitted: 'info', approved: 'ok' }[teamWeek.plan_status || 'none']">الخطة: {{ PLAN[teamWeek.plan_status || 'none'] }}</span><span class="pill" :class="{ submitted: 'ok', late: 'warn', missing: 'bad' }[teamWeek.status]">التقرير: {{ WEEK[teamWeek.status] }}</span></div>
    <div v-if="(teamWeek.items || []).length" class="tw"><table><thead><tr><th>المحور</th><th>البند</th><th>المؤشر</th><th>المستهدف</th><th>المنجز</th></tr></thead>
      <tbody><tr v-for="(it, i) in teamWeek.items" :key="i"><td>{{ it.axis }}</td><td>{{ it.title }}</td><td>{{ it.kpi }}</td><td>{{ it.target }}</td><td>{{ it.achieved || '—' }}</td></tr></tbody></table></div>
    <div v-if="teamWeek.done" class="small mt"><b>الملخص:</b> {{ teamWeek.done }}</div><div v-if="teamWeek.blockers" class="small"><b>العقبات:</b> {{ teamWeek.blockers }}</div><div v-if="teamWeek.next" class="small"><b>الأسبوع القادم:</b> {{ teamWeek.next }}</div>
    <div v-if="teamWeek.plan_status === 'submitted'" class="fg mt"><label>ملاحظة للموظف</label><input v-model="planNote" class="in" /></div>
    <template #foot><button class="btn sec" @click="teamWeek = null">إغلاق</button><button v-if="teamWeek.plan_status === 'submitted'" class="btn pri" :class="{ loading: busy }" @click="approvePlan">اعتماد الخطة</button></template>
  </Modal>

  <Modal v-if="tplForm" :title="tplForm.id ? 'تعديل قالب' : 'قالب جديد'" size="wide" @close="tplForm = null">
    <div class="fr3">
      <div class="fg"><label>الاسم *</label><input v-model="tplForm.name" class="in" /></div>
      <div class="fg"><label>النطاق</label><select v-model="tplForm.scope" class="in"><option v-for="(l, k) in SCOPE" :key="k" :value="k">{{ l }}</option></select></div>
      <div v-if="tplForm.scope === 'company'" class="fg"><label>الشركة</label><select v-model="tplForm.scope_id" class="in"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <div v-else-if="tplForm.scope === 'employee'" class="fg"><label>الموظف</label><select v-model="tplForm.scope_id" class="in"><option v-for="e in staff" :key="e.id" :value="e.id">{{ e.name }}</option></select></div>
      <div v-else-if="tplForm.scope === 'job'" class="fg"><label>الوظيفة</label><select v-model="tplForm.scope_id" class="in"><option v-for="j in auth.meta.job_titles" :key="j.id" :value="j.id">{{ j.name }}</option></select></div>
      <div class="fg"><label>موعد الرفع (يوم من الشهر التالي)</label><input v-model.number="tplForm.due_day" type="number" min="1" max="28" class="in" /></div>
      <div class="fg"><label>حد البنود الشخصية</label><input v-model.number="tplForm.max_employee_items" type="number" min="0" class="in" /></div>
      <div class="fg"><label>أقصى وزن للبند الشخصي</label><input v-model.number="tplForm.employee_item_weight_max" type="number" min="0" class="in" /></div>
    </div>
    <div v-dt class="tw mb"><table><thead><tr><th>المحور</th><th>المهمة الوظيفية</th><th>المستهدف</th><th>المؤشر</th><th>الوزن</th><th /></tr></thead>
      <tbody><tr v-for="(it, i) in tplForm.items" :key="i"><td><input v-model="it.axis" class="in" /></td><td><input v-model="it.title" class="in" /></td><td><input v-model="it.target" class="in" /></td><td><input v-model="it.indicator" class="in" /></td><td><input v-model.number="it.weight" type="number" class="in" style="width:80px" /></td><td><button class="btn ghost xs bad" @click="tplForm.items.splice(i, 1)"><Icon name="trash" :size="14" /></button></td></tr></tbody></table></div>
    <div class="row"><button class="btn sec sm" @click="tplForm.items.push({ axis: tplForm.items[tplForm.items.length - 1]?.axis || '', title: '', target: '', indicator: '', weight: 0 })"><Icon name="plus" :size="14" /> بند</button><button class="btn ghost sm" @click="evenWeights">توزيع الأوزان بالتساوي</button><span class="small" :class="tplTotal === 100 ? 'ok' : 'bad'">مجموع الأوزان: {{ tplTotal }} / 100</span><label class="chk"><input v-model="tplForm.allow_employee_items" type="checkbox" /> يسمح ببنود شخصية</label><label class="chk"><input v-model="tplForm.active" type="checkbox" /> مفعّل</label></div>
    <template #foot><button class="btn sec" @click="tplForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" :disabled="tplTotal !== 100" @click="saveTpl">حفظ نسخة</button></template>
  </Modal>
</template>

<script setup>
import { applyReportRows, exportReportForm, exportReportForms, readXlsx } from '../excel'
import { alertBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import ReportItems from '../components/ReportItems.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { curYM, fmtDT, fmtYM } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const router = useRouter()
const STATUS_COLOR = { draft: 'gray', submitted: 'warn', mgr_reviewed: 'info', hr_reviewed: 'info', approved: 'ok', returned: 'bad' }
const STAGE_ACTOR = { draft: 'الموظف', returned: 'الموظف', submitted: 'المدير المباشر', mgr_reviewed: 'الموارد البشرية', hr_reviewed: 'رئيس الشركة', approved: 'منشور' }
const ROLE = { mgr: 'المدير المباشر', hr: 'الموارد البشرية', ceo: 'رئيس الشركة' }
const WEEK = { submitted: 'في الموعد', late: 'متأخر', missing: 'لم يُرفع' }
const PLAN = { none: 'لم تُرفع', submitted: 'بانتظار المشرف', approved: 'معتمدة' }
const weekMode = ref('report'); const teamWeek = ref(null); const planNote = ref('')
function openWeek(w, mode) { weekMode.value = mode; weekForm.value = { ...w, items: (w.items || []).map((i) => ({ ...i })) } }
async function fromMonthly() { const items = await api.get(`/reports/weekly/${weekForm.value.id}/from-monthly`); if (!items.length) return ui.toast('لا بنود في التقرير الشهري بعد', 'warn'); weekForm.value.items = items }
async function submitPlan() {
  if (!weekForm.value.items.some((i) => i.title?.trim())) return ui.toast('أضف بندًا واحدًا على الأقل', 'warn')
  busy.value = true
  try { await api.post(`/reports/weekly/${weekForm.value.id}/plan`, { items: weekForm.value.items.filter((i) => i.title?.trim()) }); ui.toast('رُفعت الخطة لاعتماد المشرف', 'ok'); weekForm.value = null; load() } catch (e) { ui.toast(e.message || 'تعذر الرفع', 'bad') } finally { busy.value = false }
}
async function approvePlan() {
  busy.value = true
  try { await api.post(`/reports/weekly/${teamWeek.value.id}/plan/approve`, { note: planNote.value || null }); ui.toast('اعتُمدت الخطة', 'ok'); teamWeek.value = null; planNote.value = ''; load() } catch (e) { ui.toast(e.message || 'تعذر الاعتماد', 'bad') } finally { busy.value = false }
}
const SCOPE = { group: 'المجموعة', company: 'شركة', job: 'وظيفة', employee: 'موظف بعينه' }
const prevMonth = () => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }
const period = ref(auth.emp ? curYM() : prevMonth())
const tabs = computed(() => [auth.emp && ['mine', 'تقريري الشهري'], auth.isApprover && ['team', 'تقارير الفريق'], auth.emp && ['weekly', 'الأسبوعي'], auth.isAdmin && ['templates', 'قوالب التقارير']].filter(Boolean))
const tab = ref(auth.emp ? 'mine' : 'team')
const rep = ref(null); const history = ref([]); const team = ref([]); const weeks = ref([]); const teamWeeks = ref([]); const templates = ref([])
const rv = ref(null); const rvf = ref({}); const adj = ref({ hr_bonus: 0, reason: '' }); const weekForm = ref(null); const tplForm = ref(null); const busy = ref(false)
const totalWeight = computed(() => (rep.value?.items || []).reduce((s, i) => s + (Number(i.weight) || 0), 0))
const empItems = computed(() => (rep.value?.items || []).filter((i) => i.source === 'emp').length)
const tplTotal = computed(() => (tplForm.value?.items || []).reduce((s, i) => s + (Number(i.weight) || 0), 0))
const weekStarts = computed(() => [...new Set(teamWeeks.value.map((w) => w.week_start))].sort())
const teamWeeksByEmp = computed(() => teamWeeks.value.reduce((a, w) => { (a[w.employee?.name] ||= {})[w.week_start] = w; return a }, {}))
const scopeName = (tp) => tp.scope === 'employee' ? (staff.value.find((e) => e.id === tp.scope_id)?.name || '') : tp.scope === 'company' ? auth.meta.companies.find((c) => c.id === tp.scope_id)?.name : tp.scope === 'job' ? auth.meta.job_titles.find((j) => j.id === tp.scope_id)?.name : ''

async function load() {
  const p = { params: { period: period.value } }
  if (tab.value === 'mine') [rep.value, history.value] = await Promise.all([api.get('/reports/monthly/mine', p), api.get('/reports/monthly/history')])
  else if (tab.value === 'team') { picked.value = []; team.value = await api.get('/reports/monthly', { params: { period: period.value, company_id: ui.company || undefined } }) }
  else if (tab.value === 'weekly') { weeks.value = await api.get('/reports/weekly/mine', p); if (auth.isApprover) teamWeeks.value = await api.get('/reports/weekly/team', p) }
  else { [templates.value] = await Promise.all([api.get('/reports/templates'), loadStaff()]) }
}
// Excel round-trip in the group's monthly report layout: the employee's own obligations pre-filled, the rest to fill in
const jobName = computed(() => auth.meta?.job_titles?.find((j) => j.id === auth.emp?.job_title_id)?.name || '')
async function downloadTemplate() {
  const company = auth.meta?.companies?.find((c) => c.id === auth.emp?.company_id)?.name || ''
  await exportReportForm({ name: auth.emp?.name, company, job: jobName.value, manager: auth.emp?.manager?.name, period: rep.value.period }, rep.value.items)
}
// admin downloads: one employee, the selection, or the whole team, as one workbook (a sheet per employee, approved layout)
const picked = ref([])
const dl = ref(null)
async function downloadReports(ids, key) {
  if (!ids.length) return
  dl.value = key
  try {
    const list = await api.post('/reports/monthly/export', { period: period.value, employee_ids: ids })
    if (list.length === 1) await exportReportForm(list[0].info, list[0].items, { kind: 'التقرير الشهري', category: 'reports' })
    else await exportReportForms(list, `التقارير الشهرية ${period.value} (${list.length} موظفين)`)
  } finally { dl.value = null }
}
async function uploadTemplate(ev) {
  const file = ev.target.files[0]; ev.target.value = ''
  if (!file) return
  try {
    const n = applyReportRows(rep.value.items, await readXlsx(file))
    if (!n) return alertBox('لم يُطابَق أي صف مع بنود تقريرك. استخدم النموذج المحمّل من هذه الصفحة، ولا تغيّر عمودي «رقم البند» و«المهام الوظيفية».', { icon: 'warning' })
    await save(false)
    ui.toast(`استُورد ${n} بندًا وحُفظت المسودة. راجعها ثم اضغط «إرسال للمدير»`, 'ok', 6000)
  } catch (x) { alertBox(x.message || 'تعذرت قراءة الملف', { icon: 'error' }) }
}
function addItem() { rep.value.items.push({ title: '', weight: 5, source: 'emp', achieved: '', kpi_result: '', pct: 0, evidence: '', obstacles: '', solutions: '', next_target: '', notes: '' }) }
async function save(submit) {
  busy.value = true
  try {
    rep.value = await api.put(`/reports/monthly/${rep.value.id}`, { items: rep.value.items, dev: rep.value.dev })
    if (submit) { rep.value = await api.post(`/reports/monthly/${rep.value.id}/submit`); ui.toast('أُرسل التقرير إلى مديرك', 'ok') } else ui.toast('حُفظت المسودة', 'ok')
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function remind() { const r = await api.post('/reports/remind', { period: period.value }); ui.toast(`أُرسل تذكير إلى ${r.reminded} موظفًا ومديريهم`, 'ok') }
async function adjust() { if (!adj.value.reason?.trim()) return ui.toast('سبب التعديل إلزامي', 'warn'); busy.value = true; try { rv.value = await api.post(`/reports/monthly/${rv.value.id}/adjust`, adj.value); ui.toast('عُدّل ونُشر التقييم', 'ok'); load() } finally { busy.value = false } }
function openReview(id) { router.push(`/reports/${id}`) } // full page: long reports read better than in a dialog
async function review(action) {
  if (action === 'return' && !rvf.value.note?.trim()) return ui.toast('سبب الإرجاع إلزامي', 'warn')
  if (action === 'approve' && rv.value.review_role === 'mgr' && rv.value.items.some((i) => i.mgr_score != null && i.mgr_score !== i.pct && !i.mgr_note?.trim())) return ui.toast('اكتب ملاحظة لكل بند اختلف فيه تقييمك عن تقييم الموظف', 'warn', 5000)
  busy.value = true
  try {
    await api.post(`/reports/monthly/${rv.value.id}/review`, { action, ...rvf.value, items: rv.value.items.map((i) => ({ id: i.id, mgr_score: i.mgr_score, mgr_note: i.mgr_note })) })
    ui.toast(action === 'approve' ? 'تم الاعتماد' : 'أُرجع التقرير', 'ok'); rv.value = null; load()
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function submitWeek() {
  const w = weekForm.value
  if (!w.done?.trim() && !w.items.some((i) => i.achieved?.trim())) return ui.toast('اكتب المنجز مقابل البنود أو ملخص ما أُنجز', 'warn')
  busy.value = true
  try { await api.post(`/reports/weekly/${w.id}`, { done: w.done, next: w.next, blockers: w.blockers, items: w.items.filter((i) => i.title?.trim()) }); ui.toast('رُفع التقرير الأسبوعي', 'ok'); weekForm.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
const staff = ref([])
async function loadStaff() { if (!staff.value.length) staff.value = ((await api.get('/employees', { params: { per_page: 1000 } })).data || []).map((e) => ({ id: e.id, name: e.name })) }
function evenWeights() { const n = tplForm.value.items.length; const b = Math.floor(100 / n); tplForm.value.items.forEach((it, i) => { it.weight = b + (i < 100 - b * n ? 1 : 0) }) }
async function saveTpl() {
  const x = tplForm.value
  busy.value = true
  try { if (x.id) await api.put(`/reports/templates/${x.id}`, x); else await api.post('/reports/templates', x); ui.toast('حُفظ القالب', 'ok'); tplForm.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
onMounted(load)
</script>

<style scoped>
.wk input, .wk textarea { min-height: 32px; padding: 4px 8px; font-size: 13px; }
.wk td { padding: 3px; vertical-align: top; }
</style>
