import { createApp } from 'vue'
import Landing from './views/Landing.vue'

// Public marketing page (served at /). Separate entry so it ships without the portal bundle.
createApp(Landing).mount('#landing')
