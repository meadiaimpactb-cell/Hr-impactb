<template>
  <div class="page-h">
    <div><h1>{{ t('الحضور والانصراف') }}</h1><p>تحضير مكاني · ختم زمني من الخادم · الاستثناءات تُراجع ولا تُرفض صامتة</p></div>
    <div class="acts"><button v-if="auth.emp?.punch_required" class="btn pri sm" @click="punchOpen = true"><Icon name="fingerprint" :size="16" /> {{ t('تسجيل حضور') }} / {{ t('تسجيل انصراف') }}</button></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ t(x[1]) }}</button></div>

  <template v-if="tab === 'mine'">
    <div class="row mb"><input v-model="period" type="month" class="in" style="width:auto" @change="load" /></div>
    <div v-if="mine" class="grid g4 mb">
      <Kpi icon="calendar" label="أيام مطلوبة" :value="mine.summary.required" color="n" />
      <Kpi icon="user-check" :label="t('حاضر')" :value="mine.summary.present" color="g" />
      <Kpi icon="late" :label="t('متأخر')" :value="mine.summary.late" :hint="`${mine.summary.late_minutes} دقيقة`" color="y" />
      <Kpi icon="absent" :label="t('غياب')" :value="mine.summary.absent" color="r" />
    </div>
    <div v-if="mine?.week" class="card">
      <h3>أسبوعي <span class="sub">{{ fmtD(mine.week.from) }} – {{ fmtD(mine.week.to) }} · اللائحة §29/§30: دقائق المرونة تُعوَّض خلال الأسبوع نفسه</span></h3>
      <div class="grid g4">
        <Kpi icon="clock" label="الساعات المطلوبة حتى الآن" :value="hm(mine.week.required_minutes)" color="n" />
        <Kpi icon="user-check" label="الساعات المنجزة" :value="hm(mine.week.worked_minutes)" color="g" />
        <Kpi icon="late" label="النقص المطلوب تعويضه" :value="hm(mine.week.shortfall_minutes)" :color="mine.week.shortfall_minutes ? 'r' : 'g'" />
        <Kpi icon="calendar" label="المرونة المستخدمة" :value="`${mine.week.flex_used} / ${mine.week.flex_budget} د`" :hint="mine.week.remote_eligible ? (mine.week.remote_used ? 'استُخدم يوم العمل عن بُعد' : 'يوم عمل عن بُعد متاح هذا الأسبوع') : ''" color="y" />
      </div>
      <p v-if="mine.week.ramadan" class="tiny muted mt">فترة رمضان: ساعات العمل المطلوبة 6 ساعات يوميًا (§24).</p>
    </div>
    <div v-if="excuses.length" class="card">
      <h3>مطلوب إبداء العذر <span class="sub">حضور من خارج النطاق لم تقبله الموارد البشرية — يُعدّ اليوم غيابًا إن لم يُقبل العذر (§27)</span></h3>
      <div v-dt class="tw"><table><thead><tr><th>اليوم</th><th>المهلة</th><th>العذر</th><th /></tr></thead>
        <tbody><tr v-for="r in excuses" :key="r.id"><td class="num">{{ fmtD(r.work_date) }}</td><td class="num">{{ fmtD(r.excuse_due) }}</td><td class="small">{{ r.excuse || '—' }}</td>
          <td><button class="btn pri xs" @click="giveExcuse(r)">{{ r.excuse ? 'تعديل العذر' : 'إبداء العذر' }}</button></td></tr></tbody></table></div>
    </div>
    <div class="card"><h3>{{ t('سجلي') }} <span class="acts"><button class="btn ghost sm" @click="corr = true">طلب تصحيح</button></span></h3><AttendanceTable editable :records="mine?.records || []" :grace="mine?.grace ?? grace" /></div>
  </template>

  <template v-else-if="tab === 'paper'">
    <div class="card">
      <h3>السجل البديل (الورقي) <span class="sub">اللائحة §27: عند تعذر استخدام المنصة يُثبت الحضور في سجل موثق بديل، ثم تُرحَّل بياناته هنا. تُسجَّل الأيام بمصدر «سجل ورقي».</span></h3>
      <div v-dt class="tw"><table>
        <thead><tr><th>الموظف</th><th>التاريخ</th><th>الحضور</th><th>الانصراف</th><th>ملاحظة</th><th /></tr></thead>
        <tbody><tr v-for="(p, i) in paperRows" :key="i">
          <td><select v-model="p.employee_id" class="in"><option :value="null">— اختر —</option><option v-for="e in empOpts" :key="e.id" :value="e.id">{{ e.name }}{{ e.emp_no ? ' · ' + e.emp_no : '' }}</option></select></td>
          <td style="min-width:160px"><DateInput v-model="p.date" /></td>
          <td><input v-model="p.in" type="time" class="in" /></td><td><input v-model="p.out" type="time" class="in" /></td>
          <td><input v-model="p.note" class="in" placeholder="رقم الصفحة أو سبب التعذر" /></td>
          <td><button class="btn ghost xs" title="حذف السطر" @click="paperRows.splice(i, 1)"><Icon name="x" :size="14" /></button></td>
        </tr></tbody>
      </table></div>
      <div class="row mt" style="gap:8px"><button class="btn sec sm" @click="addPaper">إضافة سطر</button><button class="btn pri sm" :class="{ loading: busy }" :disabled="!paperRows.length" @click="savePaper">ترحيل إلى المنصة</button></div>
    </div>
  </template>

  <template v-else-if="tab === 'corrections'">
    <div class="card">
      <h3>طلبات تصحيح الحضور <span class="sub">اللائحة §27: يُقدَّم الطلب خلال {{ auth.meta?.policy?.correction_days || 3 }} أيام عمل من العلم بالخلل — المتأخر يُميَّز ولا يُرفض، ويجوز قبوله بدليل</span></h3>
      <div v-if="!corrections.length" class="empty small">لا طلبات تصحيح</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>اليوم المعني</th><th>النوع</th><th>السبب</th><th>تاريخ التقديم</th><th>المهلة</th><th>الحالة</th></tr></thead>
        <tbody><tr v-for="c in corrections" :key="c.id"><td>{{ c.employee?.name }}</td><td class="num">{{ fmtD(c.date) }}</td><td class="small">{{ c.kind }}</td><td class="small">{{ c.reason }}</td><td class="num">{{ fmtD(c.filed_at) }}</td>
          <td><span v-if="c.late" class="pill warn">{{ c.late_label }}</span><span v-else class="pill ok">ضمن المهلة</span></td><td><span class="pill gray">{{ { pending: 'قيد الاعتماد', approved: 'معتمد', rejected: 'مرفوض', cancelled: 'ملغى', returned: 'مُعاد' }[c.status] || c.status }}</span></td></tr></tbody></table></div>
    </div>
  </template>

  <template v-else-if="tab === 'today'">
    <div class="row mb" style="gap:8px;align-items:center"><DateInput v-model="day" style="max-width:190px" @update:model-value="load" /><button v-if="day !== todayStr" class="btn ghost sm" @click="day = todayStr; load()">اليوم</button><span class="tiny muted">يتحدث تلقائيًا كل 30 ثانية. للتصفية حسب الشركة استخدم القائمة العلوية، وحسب الموظف استخدم البحث في الجدول.</span></div>
    <div v-if="today" class="grid g4 mb">
      <Kpi icon="users" label="مطلوب تحضيرهم" :value="today.stats.required" color="n" />
      <Kpi icon="user-check" :label="t('حاضر')" :value="today.stats.present" color="g" />
      <Kpi icon="late" :label="t('متأخر')" :value="today.stats.late" color="y" />
      <Kpi icon="absent" label="لم يسجّلوا بعد" :value="today.stats.missing" :hint="`${today.stats.on_leave} في إجازة`" color="r" />
    </div>
    <div class="card"><div v-dt class="tw"><table>
      <thead><tr><th>{{ t('الموظف') }}</th><th>{{ t('الشركة') }}</th><th>{{ t('الحضور') }}</th><th>{{ t('الانصراف') }}</th><th>{{ t('الحالة') }}</th><th>{{ t('الموقع') }}</th><th>المنجز اليومي</th><th /></tr></thead>
      <tbody><tr v-for="r in today?.rows || []" :key="r.employee.id">
        <td><RouterLink :to="`/employees/${r.employee.id}`">{{ r.employee.name }}</RouterLink></td><td>{{ r.company }}</td>
        <td class="num">{{ fmtT(r.record?.check_in) }}</td><td class="num">{{ r.record?.check_out ? fmtT(r.record.check_out) : '—' }}</td>
        <td><span v-if="r.on_leave" class="pill info">إجازة</span><span v-else-if="!r.record" class="pill gray">لم يُسجَّل</span>
          <span v-else-if="r.record.late_minutes > grace" class="pill warn">متأخر {{ r.record.late_minutes }} د</span><span v-else class="pill ok">حاضر</span>
          <span v-if="r.record?.pending" class="pill bad" title="البصمة خارج نطاق موقع التحضير، وهي محتسبة وظاهرة، وتحتاج مراجعة فقط">خارج النطاق</span></td>
        <td>
          <template v-if="r.record?.check_in"><span :class="r.record.geo_fail ? 'bad' : 'ok'">{{ r.record.geo_fail ? 'خارج النطاق' : 'داخل النطاق' }}</span><span v-if="r.record.distance_m != null" class="tiny muted num"> · {{ dist(r.record.distance_m) }}</span> <a v-if="r.record.lat" :href="`https://www.google.com/maps?q=${r.record.lat},${r.record.lng}`" target="_blank" rel="noopener" class="tiny">خريطة</a></template>
          <template v-else>—</template>
        </td>
        <td class="small" style="max-width:260px"><a v-if="r.record?.note" href="#" :title="r.record.note" @click.prevent="alertBox(r.record.note, { title: 'المنجز اليومي — ' + r.employee.name, icon: 'info' })">{{ r.record.note.length > 50 ? r.record.note.slice(0, 50) + '…' : r.record.note }}</a><span v-else class="muted">—</span></td>
        <td class="row" style="gap:4px;flex-wrap:nowrap;justify-content:flex-end">
          <button v-if="auth.meta?.policy?.presence_check !== false && r.record?.check_in && !r.record?.check_out" class="btn sec xs" title="طلب تحضير: يصل تنبيه للموظف ليؤكد تواجده بموقعه خلال المهلة (§28)" @click="presence(r)"><Icon name="map-pin" :size="13" /> طلب تحضير</button>
          <button v-if="auth.has('super', 'hr_mgr') || r.employee.track_viewer" class="btn ghost xs" title="مسار الحركة اليوم (سري — §28)" @click="movement(r)"><Icon name="clock" :size="13" /> المسار</button>
        </td>
      </tr></tbody>
    </table></div></div>
    <div class="card">
      <h3>طلبات التحضير <span class="sub">اللائحة §28: من لم يستجب خلال {{ auth.meta?.policy?.presence_minutes || 30 }} دقيقة يُعدّ مغادرًا دون إذن، ويُعلَّق حضوره للمراجعة ويُطلب منه إعادة التحضير</span></h3>
      <div v-if="!checks.length" class="empty small">لا طلبات تحضير في هذا اليوم</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>طلبه</th><th>الوقت</th><th>المهلة</th><th>الحالة</th><th>الرد</th><th>الموقع</th></tr></thead>
        <tbody><tr v-for="c in checks" :key="c.id"><td><b>{{ c.employee_name }}</b></td><td class="small">{{ c.requested_by_name }}</td><td class="num">{{ fmtT(c.requested_at) }}</td><td class="num">{{ fmtT(c.due_at) }}</td>
          <td><span class="pill" :class="{ pending: 'warn', confirmed: c.inside ? 'ok' : 'bad', missed: 'bad' }[c.status]">{{ c.status_label }}{{ c.status === 'confirmed' ? (c.inside ? ' · داخل النطاق' : ' · خارج النطاق') : '' }}</span></td>
          <td class="num">{{ c.responded_at ? fmtT(c.responded_at) : '—' }}</td><td><a v-if="c.lat" :href="`https://www.google.com/maps?q=${c.lat},${c.lng}`" target="_blank" rel="noopener" class="tiny">خريطة</a><span v-if="c.distance_m != null" class="tiny muted"> · {{ dist(c.distance_m) }}</span></td></tr></tbody></table></div>
    </div>
  </template>

  <Modal v-if="mv" :title="`مسار الحركة — ${mv.employee.name} · ${mv.date}`" size="wide" @close="mv = null">
    <div class="notice warn small">بيانات الموقع سرية (§28/§116): تُسجَّل من الحضور حتى الانصراف فقط، ويُقيَّد كل اطلاع في سجل التدقيق.</div>
    <div v-if="!mv.pings.length" class="empty">لا نقاط موقع مسجلة في هذا اليوم{{ auth.meta?.policy?.location_tracking ? '' : ' — تسجيل الموقع غير مفعّل (الإعدادات ← locationTrackingEnabled)' }}</div>
    <template v-else>
      <div ref="mapEl" style="height:360px;border-radius:12px;overflow:hidden;border:1px solid var(--line)" />
      <div class="row mt" style="gap:8px"><span class="pill ok">{{ mv.pings.length - mv.outside }} داخل النطاق</span><span class="pill" :class="mv.outside ? 'bad' : 'gray'">{{ mv.outside }} خارج النطاق</span><span class="tiny muted">من {{ fmtT(mv.pings[0].at) }} إلى {{ fmtT(mv.pings[mv.pings.length - 1].at) }}</span></div>
      <div v-dt class="tw mt"><table><thead><tr><th>الوقت</th><th>البعد عن الموقع</th><th>الحالة</th><th /></tr></thead>
        <tbody><tr v-for="(p, i) in mv.pings" :key="i"><td class="num">{{ fmtT(p.at) }}</td><td class="num">{{ p.distance_m != null ? dist(p.distance_m) : '—' }}</td><td><span class="pill" :class="p.inside ? 'ok' : 'bad'">{{ p.inside ? 'داخل' : 'خارج' }}</span></td><td><a :href="`https://www.google.com/maps?q=${p.lat},${p.lng}`" target="_blank" rel="noopener" class="tiny">خريطة</a></td></tr></tbody></table></div>
    </template>
    <div v-if="mv.can_grant && mv.manager_user_id" class="row mt" style="gap:8px;align-items:center">
      <span class="small">اطلاع المشرف المباشر ({{ mv.manager_name }}) على المسار:</span>
      <button class="btn sec xs" @click="grant(mv.manager_user_id, !(mv.viewers || []).includes(mv.manager_user_id))">{{ (mv.viewers || []).includes(mv.manager_user_id) ? 'سحب الصلاحية' : 'منح الصلاحية' }}</button>
    </div>
    <template #foot><button class="btn sec" @click="mv = null">إغلاق</button></template>
  </Modal>

  <template v-else-if="tab === 'month'">
    <div class="row mb"><input v-model="period" type="month" class="in" style="width:auto" @change="load" /><button class="btn sec sm" @click="exportMonth"><Icon name="download" :size="16" /> {{ t('تصدير Excel') }}</button></div>
    <div class="card"><div v-dt class="tw"><table>
      <thead><tr><th>{{ t('الموظف') }}</th><th>{{ t('الشركة') }}</th><th>أيام العمل</th><th>الحضور</th><th>التأخيرات</th><th>دقائق التأخير</th><th>الخروج المبكر</th><th>الغياب</th><th>الإجازات</th><th>معلّق</th></tr></thead>
      <tbody><tr v-for="r in month" :key="r.employee.id">
        <td><RouterLink :to="`/employees/${r.employee.id}`">{{ r.employee.name }}</RouterLink></td><td>{{ r.company }}</td>
        <td>{{ r.summary.required }}</td><td>{{ r.summary.present }}</td><td :class="{ 'warn b': r.summary.late }">{{ r.summary.late }}</td>
        <td>{{ r.summary.late_minutes }}</td><td>{{ r.summary.early_minutes }}</td><td :class="{ 'bad b': r.summary.absent }">{{ r.summary.absent }}</td>
        <td>{{ r.summary.leave }}</td><td>{{ r.summary.pending }}</td>
      </tr></tbody>
    </table></div></div>
  </template>

  <template v-else-if="tab === 'pending'">
    <div class="card"><h3>سجلات تحتاج مراجعة <span class="sub">دون اتصال · دقة ضعيفة · خارج النطاق</span></h3>
      <div v-if="auth.isHr" class="notice info small row" style="gap:8px;align-items:center">
        <span>إذا كانت سجلات «خارج النطاق» بسبب إحداثيات موقع عمل غير دقيقة: صحّح الموقع من «البيانات الأساسية ← مواقع التحضير» (الصق رابط خرائط Google)، ثم أعد الفحص هنا لتُعتمد البصمات التي أصبحت داخل النطاق تلقائيًا.</span>
        <button class="btn sec xs" :class="{ loading: rechecking }" @click="recheckGeo"><Icon name="refresh" :size="13" /> إعادة فحص النطاق</button>
      </div>
      <div v-if="!pending.length" class="empty"><div class="ic"><Icon name="check-circle" :size="44" :stroke="1.4" /></div>لا سجلات معلّقة</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>{{ t('الموظف') }}</th><th>اليوم</th><th>{{ t('الحضور') }}</th><th>السبب</th><th /></tr></thead>
        <tbody><tr v-for="r in pending" :key="r.id">
          <td>{{ r.employee?.name }}</td><td class="num">{{ fmtD(r.work_date) }}</td><td class="num">{{ fmtT(r.check_in) }}</td>
          <td class="small">{{ [r.offline && 'دون اتصال', r.geo_fail && `حضور خارج النطاق${r.distance_m != null ? ' (يبعد ' + dist(r.distance_m) + ')' : ''}`, r.out_geo_fail && `انصراف خارج النطاق${r.out_distance_m != null ? ' (يبعد ' + dist(r.out_distance_m) + ')' : ''}`, r.low_accuracy && 'دقة ضعيفة'].filter(Boolean).join(' · ') }}
            <div v-if="r.status === 'excuse_requested'" class="mt"><span class="pill warn">بانتظار عذر الموظف حتى {{ fmtD(r.excuse_due) }}</span><div v-if="r.excuse" class="tiny">العذر: {{ r.excuse }}</div></div><div class="tiny"><a v-if="r.lat" :href="`https://www.google.com/maps?q=${r.lat},${r.lng}`" target="_blank" rel="noopener">موقع الحضور على الخريطة</a><a v-if="r.out_lat" :href="`https://www.google.com/maps?q=${r.out_lat},${r.out_lng}`" target="_blank" rel="noopener" style="margin-inline-start:8px">موقع الانصراف</a></div></td>
          <td><template v-if="auth.has('hr_mgr', 'hr_staff')"><button class="btn okb xs" @click="review(r, true)">قبول</button> <button class="btn dan xs" :title="r.status === 'excuse_requested' ? 'احتساب اليوم غيابًا' : 'يُطلب من الموظف إبداء عذره أولًا (§27)'" @click="review(r, false)">{{ r.status === 'excuse_requested' ? 'احتساب غياب' : 'رفض وطلب عذر' }}</button></template><span v-else class="tiny muted">يراجعه مدير الموارد البشرية</span></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <PunchModal v-if="punchOpen" :today="mine?.today" @close="punchOpen = false" @done="load" />
  <NewRequest v-if="corr" initial="correction" @close="corr = false" />
</template>

<script setup>
import { exportXlsx } from '../excel'
import { alertBox, confirmBox, promptBox } from '../dialog'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import AttendanceTable from '../components/AttendanceTable.vue'
import Kpi from '../components/Kpi.vue'
import NewRequest from '../components/NewRequest.vue'
import Modal from '../components/Modal.vue'
import PunchModal from '../components/PunchModal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { csv, curYM, download, fmtD, fmtT, today as today_ } from '../utils'

const { t } = useI18n()
const rechecking = ref(false)
async function recheckGeo() {
  rechecking.value = true
  try { const r = await api.post('/attendance/recheck-geo'); ui.toast(`اعتُمد ${r.cleared} سجلًا أصبح داخل النطاق · بقي ${r.still_pending} للمراجعة`, 'ok'); load() } finally { rechecking.value = false }
}
const auth = useAuth()
const ui = useUi()
const isHr = computed(() => auth.has('super', 'hr_mgr', 'hr_staff'))
const tabs = computed(() => [auth.emp && ['mine', 'سجلي'], auth.isApprover && ['today', 'اليوم'], auth.isApprover && ['month', 'ملخص الشهر'], auth.isApprover && ['pending', 'خارج النطاق للمراجعة'],
  auth.isApprover && ['corrections', 'طلبات التصحيح'], isHr.value && ['paper', 'السجل البديل']].filter(Boolean))
const hm = (m) => `${Math.floor((m || 0) / 60)}:${String((m || 0) % 60).padStart(2, '0')}`
const excuses = computed(() => (mine.value?.records || []).filter((r) => r.status === 'excuse_requested'))
async function giveExcuse(r) {
  const text = await promptBox(`عذرك عن حضور يوم ${fmtD(r.work_date)} من خارج النطاق`, { value: r.excuse || '' })
  if (!text) return
  try { await api.post(`/attendance/${r.id}/excuse`, { excuse: text }); ui.toast('أُرسل العذر إلى الموارد البشرية', 'ok'); load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') }
}
const corrections = ref([])
const empOpts = ref([]); const paperRows = ref([]); const busy = ref(false)
function addPaper() { const last = paperRows.value[paperRows.value.length - 1]; paperRows.value.push({ employee_id: null, date: last?.date || today_(), in: last?.in || '', out: last?.out || '', note: '' }) }
async function savePaper() {
  const rows = paperRows.value.filter((p) => p.employee_id && p.date).map((p) => ({ employee_id: p.employee_id, date: p.date, in: p.in || null, out: p.out || null, note: p.note || null }))
  if (!rows.length) return ui.toast('أدخل الموظف والتاريخ لكل سطر', 'warn')
  busy.value = true
  try {
    const r = await api.post('/attendance/paper', { rows })
    ui.toast(`رُحّل ${r.saved} سطر${r.errors.length ? ' — ' + r.errors.join(' · ') : ''}`, r.errors.length ? 'warn' : 'ok', 6000)
    if (!r.errors.length) paperRows.value = []
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
const tab = ref(auth.isApprover ? 'today' : 'mine') // managers and HR open on today's log
const todayStr = today_()
const day = ref(todayStr)
const period = ref(curYM())
const mine = ref(null)
const today = ref(null)
const checks = ref([]); const mv = ref(null); const mapEl = ref(null)
async function presence(r) {
  if (!await confirmBox(`إرسال طلب تحضير إلى ${r.employee.name}؟ يصله تنبيه فوري ليؤكد تواجده بموقعه الحالي.`)) return
  try { const x = await api.post(`/attendance/presence/${r.employee.id}`); ui.toast(`أُرسل الطلب — المهلة ${x.minutes} دقيقة`, 'ok'); loadChecks() } catch (e) { ui.toast(e.message || 'تعذر الإرسال', 'bad') }
}
async function loadChecks() { if (!auth.isApprover) return; try { checks.value = await api.get('/attendance/presence', { params: { date: day.value } }) } catch { checks.value = [] } }
async function movement(r) {
  try {
    const d = await api.get(`/attendance/movement/${r.employee.id}`, { params: { date: day.value } })
    mv.value = { ...d, employee: r.employee }
    await nextTick(); drawMap()
  } catch (e) { ui.toast(e.message || 'لا صلاحية', 'bad') }
}
async function drawMap() {
  if (!mapEl.value || !mv.value?.pings?.length) return
  const L = (await import('leaflet')).default; await import('leaflet/dist/leaflet.css')
  const pts = mv.value.pings.map((p) => [+p.lat, +p.lng])
  const map = L.map(mapEl.value, { zoomControl: true }).setView(pts[0], 15)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map)
  for (const f of mv.value.fence || []) L.circle([f.lat, f.lng], { radius: f.radius, color: '#1C84C6', fillColor: '#4FC9F0', fillOpacity: .15, weight: 2 }).addTo(map).bindTooltip(f.name)
  L.polyline(pts, { color: '#D6455D', weight: 3 }).addTo(map)
  mv.value.pings.forEach((p, i) => L.circleMarker([+p.lat, +p.lng], { radius: 5, color: p.inside ? '#17936B' : '#D6483B', fillColor: p.inside ? '#17936B' : '#D6483B', fillOpacity: 1 }).addTo(map).bindTooltip(`${fmtT(p.at)} · ${p.distance_m != null ? dist(p.distance_m) : ''}`))
  map.fitBounds(L.latLngBounds(pts).pad(0.2))
}
async function grant(userId, on) { const r = await api.put(`/attendance/movement/${mv.value.employee.id}/viewers`, { user_id: userId, grant: on }); mv.value.viewers = r.viewers; ui.toast(on ? 'مُنحت الصلاحية' : 'سُحبت الصلاحية', 'ok') }
const month = ref([])
const pending = ref([])
const punchOpen = ref(false)
const corr = ref(false)
const grace = computed(() => auth.meta?.policy?.grace_minutes ?? 0)
const dist = (m) => (m >= 1000 ? (m / 1000).toFixed(1) + ' كم' : Math.round(m) + ' م')
const cp = () => ({ company_id: ui.company || undefined })

async function load() {
  if (tab.value === 'mine') mine.value = await api.get('/attendance/me', { params: { period: period.value } })
  else if (tab.value === 'today') { loadChecks(); today.value = await api.get('/attendance/today', { params: { ...cp(), date: day.value } }) }
  else if (tab.value === 'month') month.value = await api.get('/attendance/month', { params: { period: period.value, ...cp() } })
  else if (tab.value === 'corrections') corrections.value = await api.get('/attendance/corrections')
  else if (tab.value === 'paper') { if (!empOpts.value.length) empOpts.value = await api.get('/employees/options', { params: cp() }); if (!paperRows.value.length) addPaper() }
  else pending.value = await api.get('/attendance/pending')
}
async function review(r, accept) {
  const final = r.status === 'excuse_requested'
  const reason = accept ? null : await promptBox(final ? 'سبب احتساب اليوم غيابًا' : 'سبب عدم القبول (يُطلب من الموظف إبداء عذره)')
  if (!accept && !reason) return
  try {
    await api.post(`/attendance/${r.id}/review`, { accept, reason })
    ui.toast(accept ? 'قُبل السجل' : final ? 'احتُسب اليوم غيابًا' : 'طُلب من الموظف إبداء عذره', 'ok')
  } catch (e) { ui.toast(e.message || 'تعذر الحفظ', 'bad') }
  load()
}
function exportMonth() {
  // same columns, same order as the table on screen
  exportXlsx(`attendance-${period.value}`, [['الموظف', 'الشركة', 'أيام العمل', 'الحضور', 'التأخيرات', 'دقائق التأخير', 'الخروج المبكر', 'الغياب', 'الإجازات', 'معلّق'],
    ...month.value.map((r) => [r.employee.name, r.company, r.summary.required, r.summary.present, r.summary.late, r.summary.late_minutes, r.summary.early_minutes, r.summary.absent, r.summary.leave, r.summary.pending])]), { title: 'ملخص الحضور والانصراف الشهري', period: period.value.split('-').reverse().join('/') }
}
let timer = null
onMounted(() => { load(); timer = setInterval(() => { if (tab.value === 'today' && day.value === todayStr && document.visibilityState === 'visible') load().catch(() => {}) }, 30000) })
onUnmounted(() => clearInterval(timer))
watch(() => ui.company, load)
</script>
