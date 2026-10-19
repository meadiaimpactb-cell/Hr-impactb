<template>
  <div class="card">
    <h3><Icon name="palette" :size="18" /> هوية الشركات والألوان</h3>
    <p class="small muted">يرى كل موظف المنصة بشعار شركته وألوانها. التغيير يظهر فورًا لكل موظفي الشركة.</p>
    <div v-if="!canEdit" class="notice warn small">تعديل الهوية صلاحية الرئيس التنفيذي للمجموعة. يمكنك الاطلاع فقط.</div>
    <div class="ids">
      <div v-for="c in list" :key="c.id" class="idc">
        <div class="bar" :style="{ background: `linear-gradient(90deg, ${c.color || '#1A2363'}, ${c.color2 || '#4FCAF2'})` }">
          <img v-if="c.logo_url" :src="c.logo_url" :alt="c.name" />
          <span v-else class="nologo">{{ c.code }}</span>
        </div>
        <b class="small">{{ c.name }}</b>
        <div class="row" style="gap:10px;align-items:center;flex-wrap:wrap">
          <label class="tiny muted cl">الأساسي <input type="color" class="cp" :value="c.color || '#1A2363'" :disabled="!canEdit" @input="c.color = $event.target.value" @change="save(c)" /></label>
          <label class="tiny muted cl">الثانوي <input type="color" class="cp" :value="c.color2 || '#4FCAF2'" :disabled="!canEdit" @input="c.color2 = $event.target.value" @change="save(c)" /></label>
        </div>
        <div v-if="canEdit" class="row" style="gap:6px">
          <button type="button" class="btn sec xs" :class="{ loading: busy === c.id }" @click="pick(c)"><Icon name="upload" :size="13" /> {{ c.logo_url ? 'تغيير الشعار' : 'رفع شعار' }}</button>
          <button v-if="c.logo_url" type="button" class="btn ghost xs" title="إزالة الشعار" @click="removeLogo(c)"><Icon name="trash" :size="13" /></button>
        </div>
      </div>
    </div>
    <p class="tiny muted mt">يفضَّل شعار PNG بخلفية شفافة.</p>
    <input ref="inp" type="file" accept=".png,.svg,.webp,.jpg,.jpeg" hidden @change="upload" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import { alertBox, confirmBox } from '../dialog'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'

const auth = useAuth(); const ui = useUi()
const canEdit = computed(() => auth.has('super'))
const list = ref([]); const busy = ref(null); const inp = ref(null); let target = null
async function load() { list.value = (await api.get('/companies', { allCompanies: true })).filter((c) => c.active !== false) }
async function save(c) {
  try { await api.put(`/companies/${c.id}/identity`, { color: c.color, color2: c.color2 }, { allCompanies: true }); ui.toast(`حُفظت ألوان ${c.name}`, 'ok'); auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }); load() }
}
function pick(c) { target = c; inp.value?.click() }
async function upload(ev) {
  const f = ev.target.files?.[0]; ev.target.value = ''
  if (!f || !target) return
  const fd = new FormData(); fd.append('logo', f); busy.value = target.id
  try { const r = await api.post(`/companies/${target.id}/logo`, fd, { allCompanies: true }); target.logo_url = r.logo_url; ui.toast('حُدّث الشعار', 'ok'); auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) } finally { busy.value = null }
}
async function removeLogo(c) {
  if (!(await confirmBox(`إزالة شعار ${c.name}؟`, { confirmText: 'إزالة' }))) return
  try { const r = await api.delete(`/companies/${c.id}/logo`, { allCompanies: true }); c.logo_url = r.logo_url; auth.loadMeta() } catch (e) { alertBox(e.message, { icon: 'error' }) }
}
onMounted(load)
</script>

<style scoped>
.ids { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr)); gap: 12px; }
.idc { border: 1px solid var(--line); border-radius: 12px; padding: 10px; display: flex; flex-direction: column; gap: 8px; }
.bar { height: 64px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.bar img { max-height: 48px; max-width: 80%; object-fit: contain; filter: drop-shadow(0 1px 2px rgba(0,0,0,.15)); }
.nologo { color: #fff; font-weight: 800; }
.cl { display: inline-flex; align-items: center; gap: 4px; }
.cp { width: 34px; height: 26px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: none; cursor: pointer; }
</style>
