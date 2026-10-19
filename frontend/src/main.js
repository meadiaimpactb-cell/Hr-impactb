import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import DateInput from './components/DateInput.vue'
import Icon from './components/Icon.vue'
import Avatar from './components/Avatar.vue'
import vDt from './directives/datatable'
import router from './router'
import { i18n } from './i18n'
import { installZodArabic } from './lib/validation'
import { installTouchHelpers } from './lib/touch'
installZodArabic()
installTouchHelpers()
import './styles.css'

// API refusals (403 permission / 422 validation) are already shown to the user as a toast by the api client; a screen that
// did not catch them must not end up with an unhandled error — it simply stays empty.
window.addEventListener('unhandledrejection', (e) => { if (e.reason && [401, 403, 404, 422].includes(e.reason.status)) e.preventDefault() })

const app = createApp(App)
// a lifecycle hook that hit an API refusal is not a crash (the toast already explains it); anything else is still reported
app.config.errorHandler = (err, _vm, info) => { if (err && [401, 403, 404, 422].includes(err.status)) return; console.error(err, info) }
app.component('Icon', Icon).component('Avatar', Avatar).component('DateInput', DateInput).directive('dt', vDt).use(createPinia()).use(router).use(i18n).mount('#app')
