import { getCountries, getCountryCallingCode } from 'libphonenumber-js'

// Every country known to the phone metadata, named in the UI language by the browser itself (no list to maintain).
const names = (lang) => { try { return new Intl.DisplayNames([lang === 'en' ? 'en' : 'ar'], { type: 'region' }) } catch { return null } }
let cache = {}

export function countryName(code, lang = 'ar') {
  if (!code) return ''
  const n = names(lang)
  try { return n?.of(code.toUpperCase()) || code } catch { return code }
}

/** [{ value: 'SA', label: 'المملكة العربية السعودية', sub: '+966' }, …] — Saudi Arabia and GCC first. */
export function countryOptions(lang = 'ar') {
  if (cache[lang]) return cache[lang]
  const first = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'EG', 'JO', 'YE', 'SD', 'SY', 'LB', 'IQ', 'MA', 'DZ', 'TN', 'LY', 'PS', 'PK', 'IN', 'BD', 'PH', 'ID', 'LK', 'NP', 'ET', 'ER', 'SO', 'TR', 'GB', 'US']
  const all = getCountries().map((c) => ({ value: c, label: countryName(c, lang), sub: '+' + getCountryCallingCode(c) }))
  const rank = (c) => { const i = first.indexOf(c.value); return i === -1 ? 999 : i }
  cache[lang] = all.sort((a, b) => rank(a) - rank(b) || a.label.localeCompare(b.label, lang === 'en' ? 'en' : 'ar'))
  return cache[lang]
}

/** Nationality is stored as an ISO code; older rows hold free text, which is shown as is. */
export const nationalityLabel = (v, lang = 'ar') => (v && /^[A-Za-z]{2}$/.test(v) ? countryName(v, lang) : v || '—')
