import { defineStore } from 'pinia'
import { i18n } from '../i18n'

let seq = 0

export const useUi = defineStore('ui', {
  state: () => ({
    toasts: [],
    lang: localStorage.getItem('hc_lang') || 'ar',
    company: Number(localStorage.getItem('hc_company')) || 0,
    sideOpen: false,
    unread: 0,
    dark: (() => { const t = document.documentElement.dataset.theme; return t ? t === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches })(),
  }),
  actions: {
    toast(msg, kind = '', ms = 3500) {
      const id = ++seq
      this.toasts.push({ id, msg, kind })
      setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id) }, ms)
    },
    setLang(l) {
      this.lang = l
      localStorage.setItem('hc_lang', l)
      i18n.global.locale.value = l
      document.documentElement.lang = l
      document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr'
    },
    toggleTheme() {
      const cur = document.documentElement.dataset.theme
      const dark = cur ? cur === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches
      const next = dark ? 'light' : 'dark'
      document.documentElement.dataset.theme = next
      this.dark = next === 'dark'
      localStorage.setItem('hc_theme', next)
    },
    setCompany(id) {
      this.company = Number(id) || 0
      localStorage.setItem('hc_company', this.company)
    },
  },
})
