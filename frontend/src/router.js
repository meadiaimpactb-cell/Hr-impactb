import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from './stores/auth'

// Lazy-loaded routes → small initial bundle, fast first paint.
const routes = [
  { path: '/login', name: 'login', component: () => import('./views/Login.vue'), meta: { guest: true } },
  { path: '/welcome', name: 'welcome', component: () => import('./views/Welcome.vue'), meta: { guest: true } },
  { path: '/forgot', name: 'forgot', component: () => import('./views/Forgot.vue'), meta: { guest: true } },
  { path: '/print/payslip/:id', name: 'print-payslip', component: () => import('./views/PayslipPrint.vue'), props: true },
  { path: '/print/letter/:id', name: 'print-letter', component: () => import('./views/LetterPrint.vue'), props: true },
  { path: '/print/settlement/:id', name: 'print-settlement', component: () => import('./views/SettlementPrint.vue'), props: true },
  {
    path: '/',
    component: () => import('./components/AppLayout.vue'),
    children: [
      { path: '', name: 'dash', component: () => import('./views/Dashboard.vue') },
      { path: 'approvals', name: 'approvals', component: () => import('./views/Approvals.vue') },
      { path: 'requests', name: 'requests', component: () => import('./views/MyRequests.vue') },
      { path: 'notifications', name: 'notifications', component: () => import('./views/Notifications.vue') },
      { path: 'employees', name: 'employees', component: () => import('./views/Employees.vue') },
      { path: 'employees/:id', name: 'employee', component: () => import('./views/EmployeeProfile.vue'), props: true },
      { path: 'attendance', name: 'attendance', component: () => import('./views/Attendance.vue') },
      { path: 'leaves', name: 'leaves', component: () => import('./views/Leaves.vue') },
      { path: 'payroll', name: 'payroll', component: () => import('./views/Payroll.vue') },
      { path: 'custody', name: 'custody', component: () => import('./views/Custody.vue') },
      { path: 'reports', name: 'reports', component: () => import('./views/Reports.vue') },
      { path: 'reports/:id(\\d+)', name: 'report-view', component: () => import('./views/ReportView.vue'), props: true },
      { path: 'evaluation', name: 'evaluation', component: () => import('./views/Evaluation.vue') },
      { path: 'development', name: 'development', component: () => import('./views/Development.vue') },
      { path: 'violations', name: 'violations', component: () => import('./views/Violations.vue') },
      { path: 'terminations', name: 'terminations', component: () => import('./views/Terminations.vue'), meta: { approver: true } },
      { path: 'documents', name: 'documents', component: () => import('./views/Documents.vue') },
      { path: 'announcements', name: 'announcements', component: () => import('./views/Announcements.vue') },
      { path: 'mail', name: 'mail', component: () => import('./views/Mail.vue') },
      { path: 'letters', name: 'letters', component: () => import('./views/Letters.vue') },
      { path: 'insurance', name: 'insurance', component: () => import('./views/Insurance.vue') },
      { path: 'recruitment', name: 'recruitment', component: () => import('./views/Recruitment.vue'), meta: { roles: ['super', 'hr_mgr', 'hr_staff'] } },
      { path: 'projects', name: 'projects', component: () => import('./views/Projects.vue') },
      { path: 'import', name: 'import', component: () => import('./views/Import.vue'), meta: { roles: ['super', 'hr_mgr'] } },
      { path: 'reports-center', name: 'reports-center', component: () => import('./views/ReportsCenter.vue') },
      { path: 'companies', name: 'companies', component: () => import('./views/Companies.vue'), meta: { roles: ['super', 'hr_mgr'] } },
      { path: 'settings', name: 'settings', component: () => import('./views/Settings.vue'), meta: { roles: ['super', 'hr_mgr'] } },
      { path: 'users', name: 'users', component: () => import('./views/Users.vue'), meta: { roles: ['super', 'hr_mgr'] } },
      { path: 'lookups', name: 'lookups', component: () => import('./views/Lookups.vue'), meta: { roles: ['super', 'hr_mgr'] } },
      { path: 'flows', name: 'flows', component: () => import('./views/Flows.vue'), meta: { roles: ['super', 'hr_mgr'] } },
      { path: 'integrations', name: 'integrations', component: () => import('./views/Integrations.vue'), meta: { roles: ['super', 'hr_mgr'] } },
      { path: 'audit', name: 'audit', component: () => import('./views/Audit.vue'), meta: { roles: ['super', 'hr_mgr'] } },
      { path: 'downloads', name: 'downloads', component: () => import('./views/Downloads.vue') },
      { path: 'me', name: 'me', component: () => import('./views/MyProfile.vue') },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({ history: createWebHistory('/app/'), routes, scrollBehavior: () => ({ top: 0 }) })

// New release live while this tab is open: the next page change does a full load, so nobody keeps working on an old version.
let stale = false
let lastCheck = 0
async function checkVersion() {
  if (Date.now() - lastCheck < 60000) return
  lastCheck = Date.now()
  try {
    const r = await fetch(`/static/version.json?t=${Date.now()}`, { cache: 'no-store' })
    if (r.ok && (await r.json()).build !== __BUILD_ID__) stale = true
  } catch { /* offline: try later */ }
}
if (typeof window !== 'undefined') {
  setInterval(checkVersion, 5 * 60000)
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') checkVersion() })
}
router.afterEach((to) => { if (stale) window.location.assign(router.resolve(to.fullPath).href) })
router.beforeEach(async (to) => {
  checkVersion()
  const auth = useAuth()
  if (!auth.loaded) await auth.fetch()
  if (!to.meta.guest && !auth.user) return { name: 'login' }
  if (to.meta.guest && auth.user) return { name: 'dash' }
  if (auth.user?.must_change_password && to.name !== 'me' && !to.meta.guest) return { name: 'me' }
  if (to.meta.roles && !auth.has(...to.meta.roles)) return { name: 'dash' }
  if (to.meta.approver && !auth.isApprover) return { name: 'dash' }
})

export default router
