<template>
  <div class="dt">
    <!-- toolbar -->
    <div class="dt-bar">
      <div class="search dt-search"><Icon name="search" :size="16" /><input v-model="q" class="in" :placeholder="searchPlaceholder" @input="page = 1" /></div>
      <slot name="filters" />
      <div class="dt-tools">
        <div class="dt-pop-wrap">
          <button type="button" class="btn ghost sm" :aria-expanded="colsOpen" @click="colsOpen = !colsOpen"><Icon name="columns" :size="16" /> الأعمدة</button>
          <div v-if="colsOpen" class="dt-pop" @mouseleave="colsOpen = false" @click.stop>
            <label v-for="c in columns.filter((c) => c.label)" :key="c.key" class="chk"><input type="checkbox" :checked="!hidden.includes(c.key)" @change="toggleCol(c.key)" /> {{ c.label }}</label>
          </div>
        </div>
        <button v-if="exportName" type="button" class="btn ghost sm" @click="exportCsv"><Icon name="download" :size="16" /> تصدير Excel</button>
      </div>
    </div>

    <!-- bulk bar -->
    <transition name="dt-fade">
      <div v-if="selectable && selected.length" class="dt-bulk">
        <b>{{ selected.length }}</b> محدد
        <button v-if="selected.length < filtered.length" type="button" class="btn ghost xs" @click="selectAllFiltered">تحديد كل النتائج ({{ filtered.length }})</button>
        <button type="button" class="btn ghost xs" @click="clear">إلغاء التحديد</button>
        <span class="dt-sp" />
        <slot name="bulk" :selected="selectedRows" :clear="clear" />
      </div>
    </transition>

    <div class="tw dt-wrap">
      <table>
        <thead>
          <tr>
            <th v-if="selectable" class="dt-ck"><input type="checkbox" :checked="pageAllSelected" :indeterminate.prop="pageSomeSelected" aria-label="تحديد الصفحة" @change="togglePage($event.target.checked)" /></th>
            <th v-for="c in visibleCols" :key="c.key" :class="[c.class, { sortable: c.sortable !== false && c.label }]" :style="c.width ? { width: c.width } : null" @click="c.sortable !== false && c.label && sortBy(c.key)">
              {{ c.label }}<Icon v-if="sort.key === c.key" :name="sort.dir === 'asc' ? 'chevron-up' : 'chevron-down'" :size="13" class="dt-sort" />
            </th>
          </tr>
        </thead>
        <tbody v-if="loading"><tr v-for="i in 6" :key="i"><td :colspan="visibleCols.length + (selectable ? 1 : 0)"><div class="skel" /></td></tr></tbody>
        <tbody v-else-if="!paged.length"><tr><td :colspan="visibleCols.length + (selectable ? 1 : 0)" class="empty">{{ q ? 'لا نتائج مطابقة للبحث' : emptyText }}</td></tr></tbody>
        <tbody v-else>
          <tr v-for="r in paged" :key="r[rowKey]" :class="{ sel: isSel(r), click: !!$attrs.onRowClick }" @click="$emit('row-click', r)">
            <td v-if="selectable" class="dt-ck" @click.stop="toggle(r)"><input type="checkbox" :checked="isSel(r)" aria-label="تحديد" @click.stop @change="toggle(r)" /></td>
            <td v-for="c in visibleCols" :key="c.key" :class="c.class">
              <slot :name="'cell-' + c.key" :row="r" :value="val(r, c)">{{ val(r, c) ?? '—' }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- pagination -->
    <div class="dt-foot">
      <span class="tiny muted">{{ filtered.length ? `${(page - 1) * size + 1}–${Math.min(page * size, filtered.length)} من ${filtered.length}` : '0 نتيجة' }}{{ filtered.length !== rows.length ? ` (الإجمالي ${rows.length})` : '' }}</span>
      <div class="dt-pages">
        <select v-model.number="size" class="in xs" aria-label="عدد الصفوف" @change="page = 1"><option v-for="n in sizes" :key="n" :value="n">{{ n }} / صفحة</option></select>
        <button type="button" class="btn ghost xs" :disabled="page === 1" aria-label="الأولى" @click="page = 1">«</button>
        <button type="button" class="btn ghost xs" :disabled="page === 1" aria-label="السابقة" @click="page--">‹</button>
        <button v-for="p in pageList" :key="p" type="button" class="btn xs" :class="p === page ? 'pri' : 'ghost'" :disabled="p === '…'" @click="p !== '…' && (page = p)">{{ p }}</button>
        <button type="button" class="btn ghost xs" :disabled="page === pages" aria-label="التالية" @click="page++">›</button>
        <button type="button" class="btn ghost xs" :disabled="page === pages" aria-label="الأخيرة" @click="page = pages">»</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { exportXlsx } from '../excel'
import { norm } from '../utils'

/**
 * Client-side data table: search, sort, pagination, column chooser, multi-select with bulk slot, CSV export.
 * columns: [{ key, label, value?(row), search?: false, sortable?: false, class?, width?, export?: false }]
 * Cells are overridable with slots named `cell-<key>`. Page size and hidden columns are remembered per table (`storeKey`).
 */
const props = defineProps({
  rows: { type: Array, default: () => [] }, columns: { type: Array, required: true }, rowKey: { type: String, default: 'id' },
  selectable: Boolean, loading: Boolean, exportName: { type: String, default: '' }, storeKey: { type: String, default: '' },
  searchPlaceholder: { type: String, default: 'بحث…' }, emptyText: { type: String, default: 'لا توجد بيانات' }, sizes: { type: Array, default: () => [10, 25, 50, 100] },
})
defineEmits(['row-click'])
const pref = (k, d) => { try { return JSON.parse(localStorage.getItem(`dt:${props.storeKey}:${k}`)) ?? d } catch { return d } }
const save = (k, v) => { try { props.storeKey && localStorage.setItem(`dt:${props.storeKey}:${k}`, JSON.stringify(v)) } catch { /* private mode */ } }

const q = ref('')
const page = ref(1)
const size = ref(pref('size', 25))
const hidden = ref(pref('hidden', props.columns.filter((c) => c.hidden).map((c) => c.key)))
const sort = ref({ key: '', dir: 'asc' })
const sel = ref(new Set())
const colsOpen = ref(false)
watch(size, (v) => save('size', v))
watch(hidden, (v) => save('hidden', v), { deep: true })

const val = (r, c) => (c.value ? c.value(r) : c.key.split('.').reduce((o, k) => o?.[k], r))
const visibleCols = computed(() => props.columns.filter((c) => !hidden.value.includes(c.key)))
const filtered = computed(() => {
  const s = norm(q.value)
  let out = s ? props.rows.filter((r) => props.columns.some((c) => c.search !== false && norm(String(val(r, c) ?? '')).includes(s))) : props.rows.slice()
  if (sort.value.key) {
    const c = props.columns.find((x) => x.key === sort.value.key)
    const dir = sort.value.dir === 'asc' ? 1 : -1
    out = out.sort((a, b) => { const x = val(a, c) ?? '', y = val(b, c) ?? ''; return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'ar', { numeric: true })) * dir })
  }
  return out
})
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / size.value)))
watch(pages, (p) => { if (page.value > p) page.value = p })
const paged = computed(() => filtered.value.slice((page.value - 1) * size.value, page.value * size.value))
const pageList = computed(() => {
  const n = pages.value, p = page.value
  if (n <= 7) return Array.from({ length: n }, (_, i) => i + 1)
  const set = [1, p - 1, p, p + 1, n].filter((x) => x >= 1 && x <= n)
  const out = []
  ;[...new Set(set)].sort((a, b) => a - b).forEach((x, i, arr) => { if (i && x - arr[i - 1] > 1) out.push('…'); out.push(x) })
  return out
})

function sortBy(k) { sort.value = sort.value.key === k ? { key: k, dir: sort.value.dir === 'asc' ? 'desc' : 'asc' } : { key: k, dir: 'asc' } }
function toggleCol(k) { hidden.value = hidden.value.includes(k) ? hidden.value.filter((x) => x !== k) : [...hidden.value, k] }

// selection keeps ids, so it survives paging, searching and reloads
const isSel = (r) => sel.value.has(r[props.rowKey])
function toggle(r) { const s = new Set(sel.value); s.has(r[props.rowKey]) ? s.delete(r[props.rowKey]) : s.add(r[props.rowKey]); sel.value = s }
const pageAllSelected = computed(() => paged.value.length > 0 && paged.value.every(isSel))
const pageSomeSelected = computed(() => !pageAllSelected.value && paged.value.some(isSel))
function togglePage(on) { const s = new Set(sel.value); paged.value.forEach((r) => (on ? s.add(r[props.rowKey]) : s.delete(r[props.rowKey]))); sel.value = s }
function selectAllFiltered() { sel.value = new Set(filtered.value.map((r) => r[props.rowKey])) }
function clear() { sel.value = new Set() }
const selected = computed(() => [...sel.value].filter((id) => props.rows.some((r) => r[props.rowKey] === id)))
const selectedRows = computed(() => props.rows.filter((r) => sel.value.has(r[props.rowKey])))

function exportCsv() {
  const cols = visibleCols.value.filter((c) => c.label && c.export !== false)
  const data = (selectedRows.value.length ? selectedRows.value : filtered.value).map((r) => cols.map((c) => (c.exportValue ? c.exportValue(r) : val(r, c)) ?? ''))
  exportXlsx(props.exportName, [cols.map((c) => c.label), ...data])
}
defineExpose({ clear })
</script>

<style scoped>
.dt-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
.dt-search { flex: 1; min-width: 220px; }
.dt-tools { display: flex; gap: 6px; margin-inline-start: auto; }
.dt-pop-wrap { position: relative; }
.dt-pop { position: absolute; inset-inline-end: 0; top: calc(100% + 4px); background: var(--card); border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 12px 32px rgba(15, 20, 48, .16); padding: 8px 12px; z-index: var(--z-dropdown); min-width: 200px; max-width: calc(100vw - 32px); max-height: 60dvh; overflow: auto; display: grid; gap: 4px; }
.dt-bulk { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; background: var(--infobg); border: 1px solid var(--line); border-radius: 12px; padding: 8px 12px; margin-bottom: 10px; font-size: 13px; }
.dt-sp { flex: 1; }
.dt-wrap th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.dt-wrap th.sortable:hover { color: var(--deep); }
.dt-sort { vertical-align: middle; margin-inline-start: 4px; color: var(--deep); }
.dt-ck { width: 44px; min-width: 44px; text-align: center; cursor: pointer; }
.dt-wrap tr.sel td { background: var(--infobg); }
.dt-wrap tr.click { cursor: pointer; }
.dt-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
.dt-pages { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.dt-pages .in.xs { width: auto; min-height: 30px; padding: 2px 8px; font-size: 12.5px; }
@media (pointer: coarse) { .dt-pages .in.xs { min-height: 44px; font-size: 16px; } .dt-pages .btn.xs { min-height: 44px; min-width: 44px; } }
.dt-pages .btn.xs { min-width: 32px; justify-content: center; }
.dt-fade-enter-active, .dt-fade-leave-active { transition: opacity .15s; }
.dt-fade-enter-from, .dt-fade-leave-to { opacity: 0; }
</style>
