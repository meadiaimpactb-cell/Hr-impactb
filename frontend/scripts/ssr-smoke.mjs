// Render every view once on the server with a real session (admin + employee) to catch template/runtime errors
// that `vite build` cannot see. Usage: node scripts/ssr-smoke.mjs [login]
import { createServer } from 'vite'
import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createPinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import fs from 'node:fs'
import path from 'node:path'

let current = ''
const rejections = []
process.on('unhandledRejection', (r) => rejections.push(`${current}: ${r?.status || ''} ${r?.message || r}`))
const API = 'http://127.0.0.1:8000/api/v1'
const login = process.argv[2] || 'admin'
const store = {}
globalThis.localStorage = { getItem: (k) => store[k] ?? null, setItem: (k, v) => (store[k] = String(v)), removeItem: (k) => delete store[k] }
globalThis.location = { origin: 'http://127.0.0.1:5173', pathname: '/', href: '' }
globalThis.window = globalThis
const el = () => ({ setAttribute() {}, removeAttribute() {}, classList: { add() {}, remove() {}, toggle() {}, contains: () => false }, style: {}, dataset: {}, appendChild() {}, click() {}, remove() {} })
globalThis.document = { documentElement: el(), body: el(), head: el(), querySelector: () => null, querySelectorAll: () => [], createElement: el, getElementById: () => null, addEventListener() {}, removeEventListener() {}, title: '' }
globalThis.matchMedia = () => ({ matches: false, addEventListener() {}, addListener() {} })


const tok = (await (await fetch(`${API}/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ login, password: 'hc2026' }) })).json()).token
store.hc_token = tok
const H = { Authorization: `Bearer ${tok}`, Accept: 'application/json' }
const user = await (await fetch(`${API}/auth/me`, { headers: H })).json()
const meta = await (await fetch(`${API}/meta`, { headers: H })).json()

const vite = await createServer({ server: { middlewareMode: true }, appType: 'custom', logLevel: 'error' })
const { default: api } = await vite.ssrLoadModule('/src/api.js')
api.defaults.baseURL = API
const { i18n } = await vite.ssrLoadModule('/src/i18n.js')
const { default: Icon } = await vite.ssrLoadModule('/src/components/Icon.vue')
const { default: DateInput } = await vite.ssrLoadModule('/src/components/DateInput.vue')
const { useAuth } = await vite.ssrLoadModule('/src/stores/auth.js')

const views = fs.readdirSync(path.resolve('src/views')).filter((f) => f.endsWith('.vue'))
const props = { 'EmployeeProfile.vue': { id: '8' }, 'PayslipPrint.vue': { id: '1' }, 'LetterPrint.vue': { id: '1' } }
let failed = 0
for (const v of views) {
  current = v
  try {
    const { default: Comp } = await vite.ssrLoadModule(`/src/views/${v}`)
    const pinia = createPinia()
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    const app = createSSRApp({ render: () => h(Comp, props[v] || {}) }).component('Icon', Icon).component('DateInput', DateInput).directive('dt', {}).use(pinia).use(router).use(i18n)
    const auth = useAuth(pinia)
    auth.user = user; auth.meta = meta; auth.loaded = true
    await router.push('/'); await router.isReady()
    const html = await renderToString(app)
    console.log(`ok   ${v} (${html.length} chars)`)
  } catch (e) {
    failed++
    console.log(`FAIL ${v}: ${e.message.split('\n')[0]}`)
  }
}
await new Promise((r) => setTimeout(r, 1500))
if (rejections.length) console.log(['setup-time API rejections (non-fatal in browser):', ...rejections].join(' | '))
await vite.close()
console.log(failed ? `${failed} view(s) failed` : 'all views rendered')
process.exit(failed ? 1 : 0)
