<template>
  <div v-if="!records.length" class="empty">لا سجلات في هذه الفترة</div>
  <div v-dt v-else class="tw"><table>
    <thead><tr><th>اليوم</th><th>الحضور</th><th>الانصراف</th><th>التأخير</th><th>الموقع</th><th>الحالة</th><th>المنجز اليومي</th><th v-if="$slots.actions" /></tr></thead>
    <tbody><tr v-for="r in records" :key="r.id">
      <td>{{ DAYS_AR[new Date(r.work_date).getDay()] }} <span class="num">{{ r.work_date.slice(5) }}</span></td>
      <td class="num">{{ fmtT(r.check_in) }}</td>
      <td class="num">{{ r.check_out ? fmtT(r.check_out) : '—' }}</td>
      <td>{{ r.late_minutes ? r.late_minutes + ' د' : '—' }}</td>
      <td>
        <template v-if="r.check_in">
          <span :class="r.geo_fail ? 'bad' : 'ok'"><Icon :name="r.geo_fail ? 'x-circle' : 'check-circle'" :size="13" /> {{ r.geo_fail ? 'خارج النطاق' : (r.location?.name ? 'داخل نطاق ' + r.location.name : 'داخل النطاق') }}</span>
          <span v-if="r.distance_m != null" class="tiny muted num"> · {{ dist(r.distance_m) }}</span>
          <a v-if="r.lat" :href="`https://www.google.com/maps?q=${r.lat},${r.lng}`" target="_blank" rel="noopener" class="tiny" title="موقع تسجيل الحضور على الخريطة"> خريطة</a>
          <div v-if="r.check_out && r.out_lat" class="tiny" :class="r.out_geo_fail ? 'bad' : 'muted'">الانصراف: {{ r.out_geo_fail ? 'خارج النطاق' : 'داخل النطاق' }}<span v-if="r.out_distance_m != null" class="num"> · {{ dist(r.out_distance_m) }}</span> <a :href="`https://www.google.com/maps?q=${r.out_lat},${r.out_lng}`" target="_blank" rel="noopener">خريطة</a></div>
          <div v-if="r.accuracy" class="tiny muted">دقة GPS ±{{ r.accuracy }} م</div>
        </template>
        <template v-else>—</template>
      </td>
      <td>
        <span v-if="r.status === 'absent'" class="pill bad">غياب</span>
        <span v-else-if="r.late_minutes > grace" class="pill warn">متأخر</span>
        <span v-else class="pill ok">حاضر</span>
        <span v-if="r.note_pending" class="pill bad" title="التحضير معلّق حتى كتابة المنجز اليومي">معلّق — المنجز اليومي</span>
        <span v-if="r.pending" class="pill warn">للمراجعة</span><span v-if="r.corrected" class="pill info">مصحح</span><span v-if="r.geo_fail || r.out_geo_fail" class="pill bad">خارج النطاق</span>
      </td>
      <td class="small" style="max-width:280px">
        <a v-if="r.note" href="#" class="note-link" :title="r.note" @click.prevent="showNote(r)">{{ r.note.length > 60 ? r.note.slice(0, 60) + '…' : r.note }}</a><span v-else>—</span>
        <button v-if="editable && r.note_pending" class="btn okb xs" @click="editNote(r)"><Icon name="edit" :size="12" /> اكتب المنجز</button>
        <button v-else-if="editable && isToday(r)" class="btn ghost xs" title="تعديل منجز اليوم" @click="editNote(r)"><Icon name="edit" :size="12" /></button>
      </td>
      <td v-if="$slots.actions"><slot name="actions" :r="r" /></td>
    </tr></tbody>
  </table></div>
</template>

<script setup>
const dist = (m) => (m >= 1000 ? (m / 1000).toFixed(1) + ' كم' : Math.round(m) + ' م')
import { DAYS_AR, fmtT, fmtD } from '../utils'
import api from '../api'
import { alertBox, promptBox } from '../dialog'
// editable: the employee's own table — today's daily achievement can be rewritten (§33)
const props = defineProps({ records: { type: Array, default: () => [] }, grace: { type: Number, default: 0 }, editable: Boolean, noteMin: { type: Number, default: 20 } })
const emit = defineEmits(['changed'])
const isToday = (r) => String(r.work_date).slice(0, 10) === new Date().toLocaleDateString('en-CA')
function showNote(r) { alertBox(r.note, { title: 'المنجز اليومي — ' + fmtD(r.work_date), icon: 'info' }) }
async function editNote(r) {
  const note = await promptBox(`المنجز اليومي — ${isToday(r) ? 'اليوم' : fmtD(r.work_date)}`, { text: r.note_pending ? 'تحضير هذا اليوم معلّق حتى تكتب ما أنجزته فيه' : '', value: r.note || '', multiline: true, minLength: props.noteMin, confirmText: 'حفظ' })
  if (!note) return
  try { const x = await api.post(`/attendance/${r.id}/note`, { note }); r.note = x.note; r.note_pending = x.note_pending; emit('changed') } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
void props
</script>
