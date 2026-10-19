<template>
  <header class="topbar">
    <button ref="hamBtn" class="tbtn icon ham" aria-label="القائمة" :aria-expanded="ui.sideOpen" @click="ui.sideOpen = !ui.sideOpen"><Icon name="menu" /></button>
    <div class="brand" :title="brandCo ? brandCo.name : ''">
      <img v-if="brandCo?.logo_url" class="co-logo" :src="brandCo.logo_url" :alt="brandCo.name" />
      <img v-else src="/logo-mark.png" alt="" />
      <div v-if="brandCo"><div>{{ ui.lang === 'en' && brandCo.name_en ? brandCo.name_en : brandCo.name }}</div><small>HC — {{ t('إدارة رأس المال البشري') }} · {{ t('مجموعة وادي الأثر المالية') }}</small></div>
      <div v-else><div>HC — {{ t('إدارة رأس المال البشري') }}</div><small>{{ t('مجموعة وادي الأثر المالية') }}</small></div>
    </div>
    <select v-if="companies.length > 1" class="tsel hide-sm" :value="ui.company" @change="ui.setCompany($event.target.value); reload()">
      <option :value="0">{{ t('كل الشركات') }}</option>
      <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
    </select>
    <span class="spacer" />
    <button class="tbtn" :title="t('طلب جديد')" @click="newReq = true"><Icon name="plus" :size="16" /><span class="hide-sm">{{ t('طلب جديد') }}</span></button>
    <RouterLink class="tbtn icon" to="/notifications" :title="t('مركز الإشعارات')"><Icon name="bell" /><span v-if="ui.unread" class="dot">{{ ui.unread }}</span></RouterLink>
    <RouterLink v-if="auth.isApprover" class="tbtn icon" to="/approvals" :title="t('صندوق الموافقات')"><Icon name="approvals" /><span v-if="inbox" class="dot">{{ inbox }}</span></RouterLink>
    <button class="tbtn icon lg-only" :title="t('الوضع الليلي')" @click="ui.toggleTheme()"><Icon :name="ui.dark ? 'sun' : 'moon'" /></button>
    <button class="tbtn lg-only" :title="t('اللغة')" @click="ui.setLang(ui.lang === 'ar' ? 'en' : 'ar')"><Icon name="languages" :size="16" />{{ ui.lang === 'ar' ? 'EN' : 'ع' }}</button>
    <RouterLink class="tbtn hide-sm" to="/me"><span class="avatar" style="width:22px;height:22px;font-size:11px">{{ auth.user?.name?.[0] }}</span>{{ auth.user?.name?.split(' ')[0] }} <span class="tiny" style="opacity:.75">· {{ auth.user?.role_label }}</span></RouterLink>
    <button class="tbtn lg-only" :title="t('خروج')" @click="logout"><Icon name="logout" :size="16" flip-rtl /><span class="hide-sm">{{ t('خروج') }}</span></button>
    <!-- small screens: the rest of the header actions live in one menu, nothing is dropped -->
    <div ref="moreWrap" class="more-wrap">
      <button class="tbtn icon" :title="t('المزيد')" aria-label="المزيد" :aria-expanded="moreOpen" @click="moreOpen = !moreOpen"><Icon name="more" /></button>
      <div v-if="moreOpen" class="more-menu" role="menu">
        <RouterLink class="mi" to="/me" @click="moreOpen = false"><span class="avatar">{{ auth.user?.name?.[0] }}</span><span style="min-width:0"><b>{{ auth.user?.name }}</b><span class="tiny muted" style="display:block">{{ auth.user?.role_label }}</span></span></RouterLink>
        <hr />
        <select v-if="companies.length > 1" class="in msel" :value="ui.company" aria-label="الشركة" @change="ui.setCompany($event.target.value); reload(); moreOpen = false">
          <option :value="0">{{ t('كل الشركات') }}</option>
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <button class="mi" @click="ui.toggleTheme(); moreOpen = false"><Icon :name="ui.dark ? 'sun' : 'moon'" :size="18" /> {{ ui.dark ? 'الوضع الفاتح' : t('الوضع الليلي') }}</button>
        <button class="mi" @click="ui.setLang(ui.lang === 'ar' ? 'en' : 'ar'); moreOpen = false"><Icon name="languages" :size="18" /> {{ ui.lang === 'ar' ? 'English' : 'العربية' }}</button>
        <hr />
        <button class="mi" @click="logout"><Icon name="logout" :size="18" flip-rtl /> {{ t('خروج') }}</button>
      </div>
    </div>
  </header>
  <div class="shell">
    <div class="side-bd" :class="{ on: ui.sideOpen }" @click="ui.sideOpen = false" />
    <aside ref="sideEl" class="side" :class="{ open: ui.sideOpen }" @click="ui.sideOpen = false">
      <div class="side-x"><span>{{ t('القائمة') }}</span><button type="button" class="x" aria-label="إغلاق" @click.stop="ui.sideOpen = false"><Icon name="x" :size="18" /></button></div>
      <template v-for="g in nav" :key="g.g">
        <h6>{{ t(g.g) }}</h6>
        <RouterLink v-for="i in g.items" :key="i.to" :to="i.to" class="navi" :exact-active-class="i.to === '/' ? 'router-link-active' : ''" :active-class="i.to === '/' ? '' : 'router-link-active'">
          <span class="ic"><Icon :name="i.ic" /></span>{{ t(i.label) }}
          <span v-if="i.to === '/approvals' && inbox" class="cnt">{{ inbox }}</span>
          <span v-if="i.to === '/notifications' && ui.unread" class="cnt">{{ ui.unread }}</span>
          <span v-if="i.to === '/mail' && mailUnread" class="cnt">{{ mailUnread }}</span>
        </RouterLink>
      </template>
    </aside>
    <main class="main">
      <div v-if="route.path !== '/'" class="backbar">
        <button type="button" class="btn ghost xs" @click="goBack"><Icon name="arrow-right" :size="15" flip-rtl /> رجوع</button>
        <span class="tiny muted">{{ crumb }}</span>
      </div>
      <RouterView :key="viewKey" />
    </main>
  </div>
  <NewRequest v-if="newReq" @close="newReq = false" />
  <!-- Announcements that require acknowledgement block the UI until acknowledged -->
  <div v-if="acks.length" class="mo" style="z-index:200">
    <div class="box">
      <div class="mh"><h3><Icon name="megaphone" :size="18" /> إعلان يتطلب إقرارك</h3></div>
      <div class="mb"><b>{{ acks[0].title }}</b><div class="tiny muted">{{ acks[0].sender }}</div><p style="white-space:pre-wrap">{{ acks[0].body }}</p></div>
      <div class="mf"><span class="tiny muted">{{ acks.length > 1 ? `و${acks.length - 1} إعلانات أخرى` : '' }}</span><button class="btn okb" @click="ackOne"><Icon name="check-circle" :size="15" /> أقرّ بالاطلاع</button></div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { lockScroll } from '../lib/scrollLock'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import NewRequest from './NewRequest.vue'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const router = useRouter()
const route = useRoute()
// back to the previous screen inside the portal; falls back to the dashboard when opened directly
function goBack() { window.history.state?.back ? router.back() : router.push('/') }
const crumb = computed(() => { const it = NAV.flatMap((g) => g.items).filter((i) => i.to !== '/' && route.path.startsWith(i.to)).sort((a, b) => b.to.length - a.to.length)[0]; return it ? t(it.label) : '' })
const inbox = ref(0)
const newReq = ref(false)
const viewKey = ref(0)
const mailUnread = ref(0)
const acks = ref([])
async function ackOne() { const a = acks.value[0]; await api.post(`/announcements/${a.id}/ack`); acks.value = acks.value.slice(1) }
const companies = computed(() => auth.meta?.companies || [])
// Company identity (policy §47: "تظهر صفحات الموظفين بهوية شركتهم وشعارها وألوانها"): an employee always sees their own company;
// HR/the group CEO see the identity of the company they filtered on, and the group identity when viewing all companies.
const brandCo = computed(() => {
  const own = auth.user?.employee?.company
  const wide = auth.has('super') || auth.has('hr_mgr') || auth.has('hr_staff') || auth.has('fin')
  if (wide) return ui.company ? companies.value.find((c) => c.id === ui.company) || null : null
  return own || companies.value.find((c) => c.id === auth.user?.employee?.company_id) || null
})
const applyBrand = (c) => {
  const st = document.documentElement.style
  if (c?.color) st.setProperty('--brand', c.color); else st.removeProperty('--brand')
  if (c?.color2) st.setProperty('--brand2', c.color2); else st.removeProperty('--brand2')
}
watch(brandCo, applyBrand, { immediate: true })
onUnmounted(() => applyBrand(null))
// drawer + "more" menu behaviour on small screens: scroll lock, Escape, outside click, close on route change, focus handling
const moreOpen = ref(false); const moreWrap = ref(null); const sideEl = ref(null); const hamBtn = ref(null)
const isDrawer = () => window.innerWidth < 1024
watch(() => ui.sideOpen, async (open) => {
  if (!isDrawer()) return
  lockScroll(open)
  if (open) { await nextTick(); sideEl.value?.querySelector('.navi.router-link-active, .navi')?.focus({ preventScroll: true }) } else hamBtn.value?.focus({ preventScroll: true })
})
const onKey = (e) => { if (e.key === 'Escape') { if (ui.sideOpen) ui.sideOpen = false; moreOpen.value = false } }
const onDocClick = (e) => { if (moreOpen.value && moreWrap.value && !moreWrap.value.contains(e.target)) moreOpen.value = false }
const onResize = () => { if (!isDrawer() && ui.sideOpen) { ui.sideOpen = false; lockScroll(false) } }
router.afterEach(() => { if (ui.sideOpen) ui.sideOpen = false; moreOpen.value = false })

const ALL = ['super', 'hr_mgr', 'hr_staff', 'ceo', 'mgr', 'fin', 'custodian', 'emp']
const NAV = [
  { g: 'الرئيسية', items: [
    { to: '/', ic: 'home', label: 'لوحة المعلومات', roles: ALL },
    { to: '/approvals', ic: 'approvals', label: 'صندوق الموافقات', approver: true },
    { to: '/requests', ic: 'requests', label: 'طلباتي', roles: ALL },
    { to: '/me', ic: 'user', label: 'ملفي', roles: ALL },
    { to: '/downloads', ic: 'download', label: 'مركز التنزيل', roles: ALL },
  ] },
  { g: 'التواصل', items: [
    { to: '/notifications', ic: 'bell', label: 'مركز الإشعارات', roles: ALL },
    { to: '/announcements', ic: 'megaphone', label: 'الإعلانات', roles: ALL },
    { to: '/mail', ic: 'mail', label: 'البريد الداخلي', roles: ALL },
  ] },
  { g: 'الموظفون', items: [
    { to: '/employees', ic: 'users', label: 'الموظفون', approver: true },
    { to: '/documents', ic: 'folder', label: 'الوثائق والتنبيهات', roles: ALL },
    { to: '/letters', ic: 'signature', label: 'الخطابات الرسمية', roles: ALL },
    { to: '/insurance', ic: 'insurance', label: 'التأمين الطبي', roles: ALL },
    { to: '/recruitment', ic: 'user-check', label: 'التوظيف', roles: ['super', 'hr_mgr', 'hr_staff'] },
  ] },
  { g: 'التشغيل اليومي', items: [
    { to: '/attendance', ic: 'clock', label: 'الحضور والانصراف', roles: ALL },
    { to: '/leaves', ic: 'leave', label: 'الإجازات', roles: ALL },
  ] },
  { g: 'الأداء والانضباط', items: [
    { to: '/reports', ic: 'report', label: 'التقارير الدورية', roles: ALL },
    { to: '/evaluation', ic: 'chart', label: 'التقييم والأوزان', roles: ALL },
    { to: '/development', ic: 'training', label: 'التطوير والتدريب', roles: ALL },
    { to: '/violations', ic: 'gavel', label: 'المخالفات والانضباط', roles: ALL },
    { to: '/incentives', ic: 'award', label: 'الحوافز والإشادات', roles: ALL },
    { to: '/improvement', ic: 'trend', label: 'خطط تحسين الأداء', roles: ALL },
    { to: '/terminations', ic: 'exit', label: 'إنهاء الخدمة والتصفية', approver: true },
    { to: '/projects', ic: 'kanban', label: 'المشاريع', roles: ALL },
    { to: '/reports-center', ic: 'sheet', label: 'مركز التقارير', approver: true },
  ] },
  { g: 'المالية والعهد', items: [
    { to: '/payroll', ic: 'money', label: 'الرواتب والقسائم', roles: ALL },
    { to: '/custody', ic: 'briefcase', label: 'العهد', roles: ALL },
  ] },
  { g: 'الإدارة', items: [
    { to: '/users', ic: 'shield', label: 'المستخدمون والصلاحيات', roles: ['super', 'hr_mgr'] },
    { to: '/import', ic: 'upload', label: 'الاستيراد', roles: ['super', 'hr_mgr'] },
    { to: '/app-versions', ic: 'download', label: 'إصدارات التطبيق', roles: ['super', 'hr_mgr'] },
    { to: '/companies', ic: 'building', label: 'الشركات والتصنيفات', roles: ['super', 'hr_mgr'] },
    { to: '/lookups', ic: 'database', label: 'البيانات الأساسية', roles: ['super', 'hr_mgr'] },
    { to: '/flows', ic: 'workflow', label: 'أنواع الطلبات ومسارات الاعتماد', roles: ['super', 'hr_mgr'] },
    { to: '/integrations', ic: 'plug', label: 'التكامل مع أودو', roles: ['super', 'hr_mgr'] },
    { to: '/settings', ic: 'settings', label: 'الإعدادات والسياسات', roles: ['super', 'hr_mgr'] },
    { to: '/audit', ic: 'audit', label: 'سجل التدقيق', roles: ['super', 'hr_mgr'] },
  ] },
]
const nav = computed(() => NAV.map((g) => ({ ...g, items: g.items.filter((i) => (i.approver ? auth.isApprover : auth.has(...i.roles))) })).filter((g) => g.items.length))

async function poll() {
  try {
    ui.unread = (await api.get('/notifications/unread')).count
    if (auth.isApprover) inbox.value = (await api.get('/requests/inbox')).length
    mailUnread.value = (await api.get('/mail/unread')).count
    acks.value = await api.get('/announcements/pending-ack')
  } catch { /* ignore */ }
}
let timer
onMounted(() => { poll(); timer = setInterval(poll, 60000); document.addEventListener('keydown', onKey); document.addEventListener('click', onDocClick); window.addEventListener('resize', onResize); window.addEventListener('hc:mail-read', poll) })
onUnmounted(() => { clearInterval(timer); document.removeEventListener('keydown', onKey); document.removeEventListener('click', onDocClick); window.removeEventListener('resize', onResize); if (ui.sideOpen) lockScroll(false) })
const reload = () => viewKey.value++
async function logout() { await auth.logout(); window.location.assign('/') } // back to the public landing page (outside the SPA base)
</script>
