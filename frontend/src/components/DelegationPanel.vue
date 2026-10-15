<template>
  <div class="card">
    <h3>تفويض اعتماداتي <span class="sub">أثناء غيابك تنتقل طلباتك إلى من تفوّضه، ويظهر في سجل القرار أنه مفوَّض عنك</span>
      <span class="acts"><button class="btn pri sm" @click="open"><Icon name="repeat" :size="16" /> تفويض جديد</button></span>
    </h3>
    <div v-if="!rows.length" class="empty"><div class="ic"><Icon name="repeat" :size="40" :stroke="1.4" /></div>لا تفويضات</div>
    <div v-dt v-else class="tw"><table>
      <thead><tr><th>من</th><th>إلى</th><th>العمليات</th><th>الفترة</th><th>السبب</th><th>الحالة</th><th /></tr></thead>
      <tbody><tr v-for="d in rows" :key="d.id">
        <td>{{ d.from_user?.name }}</td><td>{{ d.to_user?.name }}</td>
        <td class="small">{{ d.ops?.length ? d.ops.map((o) => ops[o] || o).join('، ') : 'كل الاعتمادات القابلة للتفويض' }}</td>
        <td class="small num">{{ d.valid_from }} — {{ d.valid_to }}</td><td class="small">{{ d.reason }}</td>
        <td><span class="pill" :class="active(d) ? 'ok' : d.status === 'revoked' ? 'bad' : 'gray'">{{ active(d) ? 'سارٍ' : d.status === 'revoked' ? 'مسحوب' : 'منتهٍ' }}</span></td>
        <td><button v-if="active(d) && d.from_user_id === auth.user.id" class="btn dan xs" @click="revoke(d)">سحب</button></td>
      </tr></tbody>
    </table></div>

    <Modal v-if="f" title="تفويض اعتماداتي" @close="f = null">
      <div class="fg"><label>المفوَّض إليه *</label><SearchSelect v-model="f.to_user_id" :options="users" /></div>
      <div class="fr">
        <div class="fg"><label>من</label><DateInput v-model="f.valid_from" /></div>
        <div class="fg"><label>إلى *</label><DateInput v-model="f.valid_to" :min="f.valid_from" /></div>
      </div>
      <div class="fg"><label>العمليات (لا شيء = كل ما هو قابل للتفويض)</label>
        <div class="row"><label v-for="(l, op) in ops" :key="op" class="chk small"><input v-model="f.ops" type="checkbox" :value="op" /> {{ l }}</label></div>
      </div>
      <div class="fg"><label>السبب *</label><input v-model="f.reason" class="in" placeholder="مثال: إجازة سنوية" /></div>
      <template #foot><button class="btn sec" @click="f = null">إلغاء</button><button class="btn pri" @click="save">تفويض</button></template>
    </Modal>
  </div>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import api from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { today } from '../utils'
import Modal from './Modal.vue'
import SearchSelect from './SearchSelect.vue'

const auth = useAuth()
const ui = useUi()
const rows = ref([])
const users = ref([])
const f = ref(null)
const ops = computed(() => Object.fromEntries(Object.entries(auth.meta?.ops || {}).filter(([k]) => !['SEC', 'ADM'].includes(k))))
const active = (d) => d.status === 'active' && d.valid_to >= today()

async function load() { rows.value = await api.get('/delegations') }
async function open() {
  if (!users.value.length) users.value = (await api.get('/users/options')).map((u) => ({ value: u.id, label: u.name, sub: `${u.login} · ${u.role}` }))
  f.value = { valid_from: today(), valid_to: '', ops: [], reason: '', to_user_id: null }
}
async function save() {
  if (!f.value.to_user_id || !f.value.valid_to || !f.value.reason.trim()) return ui.toast('أكمل الحقول الإلزامية', 'warn')
  await api.post('/delegations', f.value)
  ui.toast('تم التفويض', 'ok')
  f.value = null
  load()
}
async function revoke(d) {
  if (!await confirmBox('سحب التفويض الآن؟')) return
  await api.post(`/delegations/${d.id}/revoke`)
  load()
}
onMounted(load)
</script>
