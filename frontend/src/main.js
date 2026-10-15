import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import DateInput from './components/DateInput.vue'
import Icon from './components/Icon.vue'
import vDt from './directives/datatable'
import router from './router'
import { i18n } from './i18n'
import { installZodArabic } from './lib/validation'
import { installTouchHelpers } from './lib/touch'
installZodArabic()
installTouchHelpers()
import './styles.css'

createApp(App).component('Icon', Icon).component('DateInput', DateInput).directive('dt', vDt).use(createPinia()).use(router).use(i18n).mount('#app')
