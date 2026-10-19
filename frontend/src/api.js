import axios from 'axios'
import { useUi } from './stores/ui'

const api = axios.create({ baseURL: '/api/v1', headers: { Accept: 'application/json', 'X-Channel': 'web' } })

api.interceptors.request.use((cfg) => {
  const token = localStorage.getItem('hc_token')
  if (token) cfg.headers.Authorization = `Bearer ${token}`
  cfg.headers['X-Locale'] = localStorage.getItem('hc_lang') || 'ar'
  const co = Number(localStorage.getItem('hc_company')) || 0 // company selector: the whole system narrows to it
  if (co && !cfg.allCompanies) cfg.headers['X-Company'] = co // allCompanies: a request that must see every company (e.g. reassignment targets)
  return cfg
})

api.interceptors.response.use(
  (r) => r.data,
  (err) => {
    const ui = useUi()
    const status = err.response?.status
    const data = err.response?.data || {}
    if (status === 401) {
      localStorage.removeItem('hc_token')
      if (!location.pathname.startsWith('/login')) location.href = '/login'
    } else if (status === 422) {
      ui.toast(data.message || 'تحقق من الحقول', 'warn')
    } else if (status === 403) {
      ui.toast(data.message && data.message !== 'This action is unauthorized.' ? data.message : 'ليست لديك صلاحية لهذا الإجراء', 'bad')
    } else {
      ui.toast(data.message || 'تعذر الاتصال بالخادم', 'bad')
    }
    return Promise.reject({ status, errors: data.errors || {}, message: data.message ?? data.error, error: data.error })
  },
)

/** Open a private upload: fetched with the bearer token and shown in a new tab. */
export async function fileUrl(path) {
  const blob = await api.get('/files', { params: { path }, responseType: 'blob' })
  const url = URL.createObjectURL(blob)
  window.open(url, '_blank')
  setTimeout(() => URL.revokeObjectURL(url), 60000)
}

export default api
