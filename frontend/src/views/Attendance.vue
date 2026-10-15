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
      <thead><tr><th>{{ t('الموظف') }}</th><th>{{ t('الشركة') }}</th><th>{{ t('الحضور') }}</th><th>{{ t('الانصراف') }}</th><th>{{ t('الحالة') }}</th><th>{{ t('الموقع') }}</th></tr></thead>
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
      </tr></tbody>
    </table></div></div>
  </template>

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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import AttendanceTable from '../components/AttendanceTable.vue'
import Kpi from '../components/Kpi.vue'
import NewRequest from '../components/NewRequest.vue'
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
const month = ref([])
const pending = ref([])
const punchOpen = ref(false)
const corr = ref(false)
const grace = computed(() => auth.meta?.policy?.grace_minutes ?? 0)
const dist = (m) => (m >= 1000 ? (m / 1000).toFixed(1) + ' كم' : Math.round(m) + ' م')
const cp = () => ({ company_id: ui.company || undefined })

async function load() {
  if (tab.value === 'mine') mine.value = await api.get('/attendance/me', { params: { period: period.value } })
  else if (tab.value === 'today') today.value = await api.get('/attendance/today', { params: { ...cp(), date: day.value } })
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
