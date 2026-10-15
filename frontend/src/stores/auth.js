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
