<template>
  <div class="ri">
    <template v-for="(g, gi) in groups" :key="g.key">
      <div v-if="g.axis !== (groups[gi - 1]?.axis ?? null)" class="ri-axis"><Icon name="lists" :size="15" /> {{ g.axis || 'بنود عامة' }}</div>
      <div class="ri-card" :class="{ shared: g.items.length > 1 }">
        <!-- tasks of this card -->
        <div class="ri-head">
          <div style="flex:1;min-width:0">
            <div v-if="g.items.length > 1" class="tiny muted mb">منجز مشترك لـ {{ g.items.length }} مهام</div>
            <div v-for="it in g.items" :key="it.id" class="ri-task">
              <template v-if="mode === 'edit' && it.source === 'emp'">
                <div class="row" style="gap:6px;flex-wrap:nowrap"><input v-model="it.title" class="in" placeholder="عنوان البند الشخصي" /><input v-model.number="it.weight" type="number" class="in" style="max-width:80px" :max="maxEmpWeight" /><button class="btn ghost xs bad" title="حذف" @click="$emit('remove', it)"><Icon name="trash" :size="14" /></button></div>
              </template>
              <template v-else>
                <b>{{ it.title }}</b> <span class="pill gray">وزن {{ it.weight }}</span> <span v-if="it.source === 'emp'" class="pill purple">شخصي</span>
                <div v-if="it.indicator || it.target" class="tiny muted">{{ [it.indicator && 'المؤشر: ' + it.indicator, it.target && 'المستهدف: ' + it.target].filter(Boolean).join(' · ') }}</div>
              </template>
            </div>
          </div>
          <div class="ri-scores">
            <span class="pill" :class="g.items[0].pct ? 'info' : 'gray'">تقييم الموظف {{ g.items[0].pct || 0 }}%</span>
            <span v-if="g.items[0].mgr_score != null && mode !== 'review'" class="pill ok">تقييم المدير {{ g.items[0].mgr_score }}%</span>
          </div>
        </div>

        <!-- edit (employee) -->
        <div v-if="mode === 'edit'" class="fr mt">
          <div class="fg" style="grid-column:1/-1"><label>المنجز *</label><textarea :value="g.items[0].achieved" class="in pre" :rows="rows(g.items[0].achieved)" @input="set(g, 'achieved', $event.target.value)" /></div>
          <div class="fg"><label>إنجاز المؤشر</label><textarea :value="g.items[0].kpi_result" class="in pre" :rows="Math.min(6, rows(g.items[0].kpi_result))" @input="set(g, 'kpi_result', $event.target.value)" /></div>
          <div class="fg"><label>نسبة الإنجاز %</label><input :value="g.items[0].pct" type="number" min="0" max="100" class="in" @input="set(g, 'pct', $event.target.value === '' ? 0 : Number($event.target.value))" /></div>
          <div class="fg"><label>مستهدفات الشهر القادم</label><textarea :value="g.items[0].next_target" class="in" rows="2" @input="set(g, 'next_target', $event.target.value)" /></div>
          <div class="fg"><label>العقبات</label><textarea :value="g.items[0].obstacles" class="in" rows="2" @input="set(g, 'obstacles', $event.target.value)" /></div>
          <div class="fg"><label>الحلول</label><textarea :value="g.items[0].solutions" class="in" rows="2" @input="set(g, 'solutions', $event.target.value)" /></div>
          <div class="fg"><label>ملاحظات</label><textarea :value="g.items[0].notes" class="in" rows="2" @input="set(g, 'notes', $event.target.value)" /></div>
          <div class="fg"><label>الدليل (رابط أو ملف)</label><input :value="g.items[0].evidence" class="in" @input="set(g, 'evidence', $event.target.value)" /></div>
        </div>

        <!-- view / review -->
        <template v-else>
          <div class="ri-body">
            <div class="ri-block main"><div class="lbl">المنجز</div><div class="txt">{{ g.items[0].achieved || '—' }}</div></div>
            <div v-for="f in VIEW_FIELDS" v-show="g.items[0][f[0]]" :key="f[0]" class="ri-block"><div class="lbl">{{ f[1] }}</div><div class="txt">{{ g.items[0][f[0]] }}</div></div>
          </div>
          <div v-if="mode === 'review'" class="fr mt ri-review">
            <div class="fg"><label>تقييم المدير %</label><input :value="g.items[0].mgr_score" type="number" min="0" max="100" class="in" :placeholder="String(g.items[0].pct || 0)" @input="set(g, 'mgr_score', $event.target.value === '' ? null : Number($event.target.value))" /></div>
            <div class="fg" style="grid-column:span 2"><label>ملاحظة المدير <span v-if="g.items[0].mgr_score != null && g.items[0].mgr_score !== g.items[0].pct" class="req">إلزامية عند الاختلاف</span></label><input :value="g.items[0].mgr_note" class="in" @input="set(g, 'mgr_note', $event.target.value)" /></div>
          </div>
        </template>
        <div v-if="g.items[0].mgr_note && mode !== 'review'" class="notice info tiny mt">ملاحظة المدير: {{ g.items[0].mgr_note }}</div>
      </div>
    </template>
  </div>
</template>

<script setup>
// Report items grouped as in the approved form: an axis heading, then one card per task, or one card for
// several tasks of the axis that share the same achievement (shown once, edited once, saved on each task).
import { computed } from 'vue'

const props = defineProps({ items: { type: Array, required: true }, mode: { type: String, default: 'view' }, maxEmpWeight: { type: Number, default: 15 } })
defineEmits(['remove'])
const VIEW_FIELDS = [['kpi_result', 'إنجاز المؤشر'], ['next_target', 'مستهدفات الشهر القادم'], ['obstacles', 'العقبات'], ['solutions', 'الحلول'], ['notes', 'ملاحظات'], ['evidence', 'الدليل']]
const same = (a, b) => String(a || '').replace(/\s+/g, ' ').trim() === String(b || '').replace(/\s+/g, ' ').trim()

const groups = computed(() => {
  const out = []
  for (const it of props.items) {
    const last = out[out.length - 1]
    const lead = last?.items[0]
    if (lead && (lead.axis || '') === (it.axis || '') && lead.source !== 'emp' && it.source !== 'emp' && String(lead.achieved || '').trim() && same(lead.achieved, it.achieved)) last.items.push(it)
    else out.push({ key: it.id || out.length, axis: it.axis || '', items: [it] })
  }
  return out
})
const set = (g, key, v) => g.items.forEach((it) => { it[key] = v })
const rows = (t) => Math.min(18, Math.max(3, String(t || '').split('\n').length + Math.floor(String(t || '').length / 140)))
</script>

<style scoped>
.ri-axis { display: flex; align-items: center; gap: 6px; margin: 18px 0 8px; padding: 8px 12px; border-radius: 10px; background: var(--navy, #262E6B); color: #fff; font-weight: 700; font-size: 14px; }
.ri-axis:first-child { margin-top: 4px; }
.ri-card { border: 1px solid var(--line, #E8EAF3); border-radius: 12px; padding: 14px; margin-bottom: 10px; background: var(--card, #fff); }
.ri-card.shared { border-inline-start: 4px solid var(--blue, #1C84C6); }
.ri-head { display: flex; gap: 10px; align-items: flex-start; }
.ri-task + .ri-task { margin-top: 6px; padding-top: 6px; border-top: 1px dashed var(--line, #E8EAF3); }
.ri-scores { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; flex: none; }
.ri-body { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 10px; }
.ri-block { background: var(--soft, #F6F7FB); border-radius: 10px; padding: 10px 12px; min-width: 0; }
.ri-block.main { grid-column: 1 / -1; }
.ri-block .lbl { font-size: 12px; color: var(--muted, #6B7094); margin-bottom: 4px; font-weight: 600; }
.ri-block .txt, .pre { white-space: pre-line; line-height: 1.85; font-size: 13.5px; overflow-wrap: anywhere; }
.ri-review { background: #FFF8E6; border-radius: 10px; padding: 8px 10px; }
@media (max-width: 700px) { .ri-body { grid-template-columns: 1fr; } .ri-head { flex-direction: column; } .ri-scores { align-items: flex-start; flex-direction: row; } }
</style>
