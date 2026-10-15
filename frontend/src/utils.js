export const pad = (n) => String(n).padStart(2, '0')
export const today = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` }
export const curYM = () => today().slice(0, 7)
export const fmtD = (d) => { if (!d) return '—'; const x = new Date(d); return isNaN(x) ? d : `${pad(x.getDate())}/${pad(x.getMonth() + 1)}/${x.getFullYear()}` }
export const fmtT = (d) => { if (!d) return '—'; const x = new Date(d); return `${pad(x.getHours())}:${pad(x.getMinutes())}` }
export const fmtDT = (d) => (d ? `${fmtD(d)} ${fmtT(d)}` : '—')
export const money = (n, dec = 2) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec })
export const DAYS_AR = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
export const MONTHS_AR = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
export const fmtYM = (p) => { if (!p) return ''; const [y, m] = p.split('-'); return `${MONTHS_AR[+m - 1]} ${y}` }
export const CONTRACTS = { full: 'دوام كامل', part: 'دوام جزئي', collab: 'متعاون بالمهام' } // fallback; the live list is meta.lookups.contract_types
export const contractLabel = (code, meta) => (meta?.lookups?.contract_types || []).find((c) => c.code === code)?.name || CONTRACTS[code] || code || '—'
export const STATUS = {
  pending: ['بانتظار الاعتماد', 'warn'], approved: ['معتمد', 'ok'], returned: ['مُرجَع', 'bad'], cancelled: ['ملغى', 'gray'],
  active: ['نشط', 'ok'], onboarding: ['قيد الانضمام', 'info'], exiting: ['قيد الخروج', 'warn'], terminated: ['منتهي الخدمة', 'gray'],
}
export function csv(rows) {
  return rows.map((r) => r.map((c) => { c = String(c ?? ''); return /[",\n]/.test(c) ? `"${c.replace(/"/g, '""')}"` : c }).join(',')).join('\n')
}
export function download(name, content, mime = 'text/csv') {
  const blob = new Blob([mime.includes('csv') ? '﻿' + content : content], { type: `${mime};charset=utf-8` })
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 500)
}
/** Arabic-aware normalisation for fast client-side search. */
export const norm = (s) => String(s || '').toLowerCase().replace(/[ً-ْـ]/g, '').replace(/[إأآا]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').trim()
export function getPos() {
  return new Promise((res) => {
    if (!navigator.geolocation) return res(null)
    navigator.geolocation.getCurrentPosition((p) => res({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) }), () => res(null), { enableHighAccuracy: true, timeout: 8000 })
  })
}
