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
    <div class="card"><h3>{{ t('سجلي') }} <span class="acts"><button class="btn ghost sm" @click="corr = true">طلب تصحيح</button></span></h3><AttendanceTable :records="mine?.records || []" :grace="mine?.grace ?? grace" /></div>
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
      <thead><tr><th>{{ t('الموظف') }}</th><th>{{ t('الشركة') }}</th><th>{{ t('الحضور') }}</th><th>{{ t('الانصراف') }}</th><th>{{ t('الحالة') }}</th><th>{{ t('الموقع') }}</th><th /></tr></thead>
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
      <div v-if="!pending.length" class="empty"><div class="ic"><Icon name="check-circle" :size="44" :stroke="1.4" /></div>لا سجلات معلّقة</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>{{ t('الموظف') }}</th><th>اليوم</th><th>{{ t('الحضور') }}</th><th>السبب</th><th /></tr></thead>
        <tbody><tr v-for="r in pending" :key="r.id">
          <td>{{ r.employee?.name }}</td><td>{{ r.work_date }}</td><td class="num">{{ fmtT(r.check_in) }}</td>
          <td class="small">{{ [r.offline && 'دون اتصال', r.geo_fail && `حضور خارج النطاق${r.distance_m != null ? ' (يبعد ' + dist(r.distance_m) + ')' : ''}`, r.out_geo_fail && `انصراف خارج النطاق${r.out_distance_m != null ? ' (يبعد ' + dist(r.out_distance_m) + ')' : ''}`, r.low_accuracy && 'دقة ضعيفة'].filter(Boolean).join(' · ') }}<div class="tiny"><a v-if="r.lat" :href="`https://www.google.com/maps?q=${r.lat},${r.lng}`" target="_blank" rel="noopener">موقع الحضور على الخريطة</a><a v-if="r.out_lat" :href="`https://www.google.com/maps?q=${r.out_lat},${r.out_lng}`" target="_blank" rel="noopener" style="margin-inline-start:8px">موقع الانصراف</a></div></td>
          <td><template v-if="auth.has('hr_mgr', 'hr_staff')"><button class="btn okb xs" @click="review(r, true)">قبول</button> <button class="btn dan xs" @click="review(r, false)">رفض</button></template><span v-else class="tiny muted">يراجعه مدير الموارد البشرية</span></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>

  <PunchModal v-if="punchOpen" :today="mine?.today" @close="punchOpen = false" @done="load" />
  <NewRequest v-if="corr" initial="correction" @close="corr = false" />
</template>

<script setup>
import { exportXlsx } from '../excel'
import { promptBox } from '../dialog'
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
import { csv, curYM, download, fmtT, today as today_ } from '../utils'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const tabs = computed(() => [auth.emp && ['mine', 'سجلي'], auth.isApprover && ['today', 'اليوم'], auth.isApprover && ['month', 'ملخص الشهر'], auth.isApprover && ['pending', 'خارج النطاق للمراجعة']].filter(Boolean))
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
  else pending.value = await api.get('/attendance/pending')
}
async function review(r, accept) {
  const reason = accept ? null : await promptBox('سبب الرفض')
  if (!accept && !reason) return
  await api.post(`/attendance/${r.id}/review`, { accept, reason })
  ui.toast(accept ? 'قُبل السجل' : 'رُفض السجل', 'ok')
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
