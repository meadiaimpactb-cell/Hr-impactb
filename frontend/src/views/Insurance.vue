<template>
  <div class="page-h">
    <div><h1>{{ t('التأمين الطبي') }}</h1><p>وثائق التأمين لكل شركة وأعضاؤها؛ الفئة وعدد التابعين من باقة الشركة مع إمكانية الاستثناء لكل موظف · حالات الانتهاء وسجل الإضافة والإلغاء</p></div>
    <div class="acts"><button v-if="auth.isHr" class="btn pri sm" @click="pf = { company_id: auth.meta.companies[0]?.id, provider: '', policy_no: '', classes: ['A', 'B', 'C'], from: '', to: '', cost: 0, active: true }"><Icon name="plus" :size="16" /> وثيقة تأمين</button></div>
  </div>
  <template v-if="!auth.isApprover">
    <div v-if="mine" class="card">
      <h3>تأميني الطبي</h3>
      <div v-if="!mine.insurance" class="notice info">لم تُضف بعد إلى وثيقة تأمين. فئتك حسب الباقة: <b>{{ mine.tier_class }}</b> · التابعون المسموح بهم: <b>{{ mine.tier_dependents }}</b></div>
      <div v-else class="grid g4"><Kpi label="شركة التأمين" :value="mine.policy?.provider" color="b" /><Kpi label="الفئة" :value="mine.insurance.class" color="g" /><Kpi label="التابعون" :value="mine.insurance.dependents" :hint="`المسموح ${mine.tier_dependents}`" color="y" /><Kpi label="رقم البطاقة" :value="mine.insurance.card || '—'" :hint="mine.policy ? `تنتهي ${mine.policy.to}` : ''" color="p" /></div>
    </div>
  </template>
  <template v-else>
    <div class="grid g4 mb">
      <Kpi label="الوثائق" :value="policies.length" color="b" /><Kpi label="مؤمَّنون" :value="members.filter((m) => m.insurance).length" :hint="`من ${members.length}`" color="g" />
      <Kpi label="غير مؤمَّنين" :value="members.filter((m) => !m.insurance).length" color="r" /><Kpi label="التكلفة السنوية" :value="money(policies.reduce((s, p) => s + Number(p.cost), 0), 0)" color="y" />
    </div>
    <div class="card">
      <h3>الوثائق</h3>
      <div v-if="!policies.length" class="empty">لا وثائق تأمين</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الشركة</th><th>شركة التأمين</th><th>الرقم</th><th>الفئات</th><th>من</th><th>إلى</th><th>الحالة</th><th>الأعضاء</th><th>التابعون</th><th>التكلفة</th><th /></tr></thead>
        <tbody><tr v-for="p in policies" :key="p.id"><td><b>{{ p.company }}</b></td><td>{{ p.provider }}</td><td class="num">{{ p.policy_no }}</td><td>{{ p.classes.join(' / ') }}</td><td class="num">{{ p.from }}</td><td class="num">{{ p.to }}</td><td><span class="pill" :class="p.state.color">{{ p.state.label }} ({{ p.days_left }})</span></td><td>{{ p.members_count }}</td><td>{{ p.dependents_count }}</td><td class="num">{{ money(p.cost, 0) }}</td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><button class="btn ghost xs" @click="pf = { ...p }"><Icon name="edit" :size="14" /></button><button class="btn ghost xs" @click="logOpen = p">السجل</button></td></tr></tbody></table></div>
    </div>
    <div class="card">
      <h3>الموظفون</h3>
      <div v-dt class="tw"><table><thead><tr><th>الموظف</th><th>الشركة</th><th>فئة الباقة</th><th>تابعو الباقة</th><th>الوثيقة</th><th>الفئة الفعلية</th><th>التابعون</th><th>البطاقة</th><th /></tr></thead>
        <tbody><tr v-for="m in members" :key="m.employee.id"><td><b>{{ m.employee.name }}</b></td><td class="small">{{ m.company }}</td><td>{{ m.tier_class || '—' }}</td><td>{{ m.tier_dependents ?? '—' }}</td>
          <td>{{ policyName(m.insurance?.policy_id) }}</td><td>{{ m.insurance?.class || '—' }}</td><td>{{ m.insurance?.dependents ?? '—' }}</td><td class="num">{{ m.insurance?.card || '—' }}</td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><button v-if="auth.isHr" class="btn sec xs" @click="mf = { employee: m.employee, policy_id: m.insurance?.policy_id || policies.find((p) => p.company_id === m.company_id)?.id, class: m.insurance?.class || m.tier_class, dependents: m.insurance?.dependents ?? m.tier_dependents, card: m.insurance?.card || '' }">{{ m.insurance ? 'تعديل' : 'إضافة' }}</button><button v-if="auth.isHr && m.insurance" class="btn ghost xs bad" @click="removeMember(m)">إلغاء</button></td></tr></tbody></table></div>
    </div>
  </template>

  <Modal v-if="pf" :title="pf.id ? 'تعديل وثيقة' : 'وثيقة تأمين جديدة'" @close="pf = null">
    <div class="fr">
      <div class="fg"><label>الشركة</label><select v-model="pf.company_id" class="in"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <div class="fg"><label>شركة التأمين *</label><input v-model="pf.provider" class="in" /></div>
      <div class="fg"><label>رقم الوثيقة *</label><input v-model="pf.policy_no" class="in" /></div>
      <div class="fg"><label>التكلفة السنوية</label><input v-model.number="pf.cost" type="number" class="in" /></div>
      <div class="fg"><label>من *</label><DateInput v-model="pf.from" /></div><div class="fg"><label>إلى *</label><DateInput v-model="pf.to" /></div>
    </div>
    <div class="fg"><label>الفئات</label><div class="row"><label v-for="c in classes" :key="c.code" class="chk"><input v-model="pf.classes" type="checkbox" :value="c.code" /> {{ c.name }}</label></div></div>
    <template #foot><button class="btn sec" @click="pf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="savePolicy">حفظ</button></template>
  </Modal>

  <Modal v-if="mf" :title="`تأمين: ${mf.employee.name}`" size="narrow" @close="mf = null">
    <div class="fg"><label>الوثيقة</label><select v-model="mf.policy_id" class="in"><option v-for="p in policies" :key="p.id" :value="p.id">{{ p.company }} — {{ p.provider }} ({{ p.policy_no }})</option></select></div>
    <div class="fr"><div class="fg"><label>الفئة</label><select v-model="mf.class" class="in"><option v-for="c in classes" :key="c.code" :value="c.code">{{ c.name }}</option></select></div><div class="fg"><label>التابعون</label><input v-model.number="mf.dependents" type="number" min="0" class="in" /></div></div>
    <div class="fg"><label>رقم البطاقة</label><input v-model="mf.card" class="in" /></div>
    <template #foot><button class="btn sec" @click="mf = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveMember">حفظ</button></template>
  </Modal>

  <Modal v-if="logOpen" :title="`سجل الوثيقة ${logOpen.policy_no}`" size="narrow" @close="logOpen = null">
    <ul class="timeline"><li v-for="(l, i) in [...(logOpen.log || [])].reverse()" :key="i"><b>{{ l.ev }}</b> {{ l.employee }} <span class="tiny muted">{{ fmtDT(l.at) }} · {{ l.by }}</span></li></ul>
    <div v-if="!logOpen.log?.length" class="empty">لا حركات</div>
    <template #foot><button class="btn sec" @click="logOpen = null">إغلاق</button></template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtDT, money } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const policies = ref([]); const members = ref([]); const classes = ref([]); const mine = ref(null); const pf = ref(null); const mf = ref(null); const logOpen = ref(null); const busy = ref(false)
const policyName = (id) => { const p = policies.value.find((x) => x.id === id); return p ? `${p.provider} (${p.policy_no})` : '—' }
async function load() {
  if (auth.isApprover) { const r = await api.get('/insurance'); policies.value = r.policies; members.value = r.members; classes.value = r.classes } else mine.value = await api.get('/insurance/mine')
}
async function savePolicy() { busy.value = true; try { if (pf.value.id) await api.put(`/insurance/${pf.value.id}`, pf.value); else await api.post('/insurance', pf.value); ui.toast('حُفظت الوثيقة', 'ok'); pf.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false } }
async function saveMember() { busy.value = true; try { await api.post(`/insurance/members/${mf.value.employee.id}`, { action: 'set', policy_id: mf.value.policy_id, class: mf.value.class, dependents: mf.value.dependents, card: mf.value.card }); ui.toast('تم', 'ok'); mf.value = null; load() } finally { busy.value = false } }
async function removeMember(m) { if (!await confirmBox(`إلغاء تأمين ${m.employee.name}؟`)) return; await api.post(`/insurance/members/${m.employee.id}`, { action: 'remove' }); load() }
onMounted(load)
</script>
