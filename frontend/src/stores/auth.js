import { defineStore } from 'pinia'
import api from '../api'

export const useAuth = defineStore('auth', {
  state: () => ({ user: null, meta: null, loaded: false }),
  getters: {
    roles: (s) => s.user?.roles || [],
    emp: (s) => s.user?.employee || null,
    isHr: (s) => (s.user?.roles || []).some((r) => ['super', 'hr_mgr', 'hr_staff'].includes(r)),
    isAdmin: (s) => (s.user?.roles || []).some((r) => ['super', 'hr_mgr'].includes(r)),
    isApprover: (s) => (s.user?.roles || []).some((r) => r !== 'emp') || s.user?.has_reports,
    // may the user see others' records in this area (team / scope)? (older sessions without the list: yes)
    // an action allowed in an area (any company) and a page hidden from this user — from the permissions editor
    can: (s) => (op, action = 'view') => ['super', 'hr_mgr'].some((r) => (s.user?.roles || []).includes(r)) || (s.user?.can_do?.[op] || []).includes(action),
    hiddenPage: (s) => (path) => (s.user?.hidden_pages || []).some((p) => path === p || (p !== '/' && path.startsWith(p + '/'))),
    canView: (s) => (op) => !s.user?.can_view || s.user.can_view.includes(op) || !!s.user?.has_reports,
  },
  actions: {
    has(...r) { return this.roles.some((x) => r.includes(x)) },
    async login(login, password) {
      const res = await api.post('/auth/login', { login, password })
      localStorage.setItem('hc_token', res.token)
      this.user = res.user
      await this.loadMeta()
    },
    async fetch() {
      if (!localStorage.getItem('hc_token')) { this.loaded = true; return }
      try {
        this.user = await api.get('/auth/me')
        await this.loadMeta()
      } catch { this.user = null }
      this.loaded = true
    },
    async loadMeta() { this.meta = await api.get('/meta') },
    async logout() {
      try { await api.post('/auth/logout') } catch { /* ignore */ }
      localStorage.removeItem('hc_token')
      this.user = null
    },
  },
})
