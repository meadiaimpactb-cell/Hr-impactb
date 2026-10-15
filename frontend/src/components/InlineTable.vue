<template>
  <div class="tw"><table class="info-t"><tbody>
    <tr v-for="r in rows" :key="r.label"><th style="width:200px">{{ r.label }}</th>
      <td :class="{ editable: can(r) && !(cur && cur.key === r.key) }" @click="start(r)">
        <div v-if="cur && r.key && cur.key === r.key" class="row" style="gap:6px;flex-wrap:nowrap" @click.stop>
          <SearchSelect v-if="r.type === 'select'" v-model="cur.value" :options="r.options()" style="flex:1;min-width:220px" />
          <DateInput v-else-if="r.type === 'date'" v-model="cur.value" style="max-width:220px" />
          <select v-else-if="r.type === 'bool'" v-model="cur.value" class="in" style="max-width:220px"><option :value="true">{{ r.yes }}</option><option :value="false">{{ r.no }}</option></select>
          <template v-else>
            <input v-model.trim="cur.value" class="in" :type="r.type || 'text'" :dir="r.ltr ? 'ltr' : null" :list="r.list ? r.list.id : null" :placeholder="r.placeholder || ''" style="flex:1;min-width:220px" @keydown.enter="save" @keydown.esc="cur = null" />
            <datalist v-if="r.list" :id="r.list.id"><option v-for="x in r.list.items" :key="x" :value="x" /></datalist>
          </template>
          <button class="btn pri xs" :class="{ loading: busy }" :disabled="busy" @click="save"><Icon name="check" :size="14" /> حفظ</button>
          <button class="btn ghost xs" title="إلغاء" @click="cur = null"><Icon name="x" :size="14" /></button>
        </div>
        <template v-else><span :dir="r.ltr ? 'ltr' : null">{{ r.text ?? '—' }}</span> <Icon v-if="can(r)" name="edit" :size="13" class="pen" /></template>
      </td></tr>
  </tbody></table></div>
</template>

<script setup>
// Key/value table where a row with `key` can be edited in place: click the value, change it, Save (or Enter).
// rows: [{ label, text, key?, value?, type?: 'text'|'tel'|'email'|'number'|'date'|'bool'|'select', options?: () => [{value,label}], yes?, no?, ltr?, list?: {id, items} }]
// `submit(key, value)` must return a promise; the editor closes when it resolves.
import { ref } from 'vue'
import SearchSelect from './SearchSelect.vue'

const props = defineProps({ rows: { type: Array, required: true }, can: { type: Function, default: (r) => !!r.key }, submit: { type: Function, required: true } })
const emit = defineEmits(['start'])
const cur = ref(null)
const busy = ref(false)

function start(r) {
  if (!props.can(r) || cur.value?.key === r.key) return
  cur.value = { key: r.key, value: r.value ?? (r.type === 'bool' ? false : '') }
  emit('start', r)
}
async function save() {
  if (!cur.value) return
  busy.value = true
  try { await props.submit(cur.value.key, cur.value.value === '' ? null : cur.value.value); cur.value = null } catch { /* message already shown */ } finally { busy.value = false }
}
</script>

<style scoped>
.info-t td.editable { cursor: pointer; }
.info-t td.editable:hover { background: var(--soft, #F1F3F9); }
.pen { opacity: .35; margin-inline-start: 6px; vertical-align: middle; }
.info-t td.editable:hover .pen { opacity: .9; color: var(--blue, #1C84C6); }
</style>
