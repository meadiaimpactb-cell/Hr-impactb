<template>
  <div class="lp">
    <div class="row mb" style="gap:8px">
      <div class="search" style="flex:1"><Icon name="search" :size="16" /><input v-model="q" class="in" placeholder="الصق رابط خرائط Google أو الإحداثيات، أو ابحث عن عنوان… ثم Enter" @keydown.enter.prevent="geocode" /></div>
      <button type="button" class="btn sec sm" @click="geocode"><Icon name="search" :size="15" /> تحديد</button>
      <button type="button" class="btn sec sm" :class="{ loading: locating }" title="ينقل نقطة الموقع إلى مكانك الآن" @click="useMyLocation"><Icon name="map-pin" :size="16" /> موقعي الحالي</button>
      <button type="button" class="btn ghost sm" :class="{ loading: measuring }" title="يقيس المسافة بين جهازك ونقطة الموقع دون تغييرها" @click="measure"><Icon name="activity" :size="15" /> قِس بُعدي</button>
    </div>
    <div v-if="mine" class="notice small" :class="mine.inside ? 'ok' : 'warn'" style="margin:0 0 8px">
      أنت تبعد <b class="num">{{ mine.dist }}</b> م عن نقطة الموقع (دقة جهازك ±{{ mine.acc }} م) — {{ mine.inside ? 'داخل النطاق المسموح' : 'خارج النطاق المسموح (' + radius + ' م)' }}
      <a :href="`https://www.google.com/maps?q=${lat},${lng}`" target="_blank" rel="noopener" class="tiny" style="margin-inline-start:8px">فتح النقطة في خرائط Google</a>
    </div>
    <div ref="el" class="map" />
    <div class="row mt" style="gap:12px;align-items:center">
      <div class="fg" style="flex:1;margin:0"><label>نطاق التحضير المسموح: <b>{{ radius }} م</b></label><input v-model.number="radius" type="range" min="50" max="2000" step="25" style="width:100%" @input="redraw(); emitAll()" /></div>
      <div class="tiny muted num" style="direction:ltr">{{ lat?.toFixed(6) }}, {{ lng?.toFixed(6) }}</div>
    </div>
    <p class="tiny muted" style="margin:4px 0 0">الأسهل: افتح موقع الشركة في خرائط Google ← مشاركة ← نسخ الرابط، ثم الصقه أعلاه. أو انقر على الخريطة لتحديد النقطة يدويًا.</p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useUi } from '../stores/ui'
import api from '../api'

// v-model:lat / v-model:lng / v-model:radius — a point with an allowed radius, on an OpenStreetMap base map.
const props = defineProps({ lat: Number, lng: Number, radius: { type: Number, default: 150 } })
const emit = defineEmits(['update:lat', 'update:lng', 'update:radius'])
const ui = useUi()
const el = ref(null)
const q = ref('')
const locating = ref(false)
const measuring = ref(false)
const mine = ref(null)
// great-circle distance in metres (same formula as the server's fence check)
function dist(a1, b1, a2, b2) { const R = 6371000; const r = Math.PI / 180; const x = Math.sin((a2 - a1) * r / 2) ** 2 + Math.cos(a1 * r) * Math.cos(a2 * r) * Math.sin((b2 - b1) * r / 2) ** 2; return Math.round(2 * R * Math.asin(Math.sqrt(x))) }
function measure() {
  if (!navigator.geolocation) return ui.toast('المتصفح لا يدعم تحديد الموقع', 'bad')
  measuring.value = true
  navigator.geolocation.getCurrentPosition((p) => {
    measuring.value = false
    const d = dist(p.coords.latitude, p.coords.longitude, lat.value, lng.value)
    mine.value = { dist: d, acc: Math.round(p.coords.accuracy), inside: d <= radius.value + Math.min(p.coords.accuracy, 50) }
  }, () => { measuring.value = false; ui.toast('تعذر تحديد موقعك — اسمح للمتصفح بالوصول إلى الموقع', 'bad') }, { enableHighAccuracy: true, timeout: 15000 })
}
const lat = ref(props.lat || 24.7136)
const lng = ref(props.lng || 46.6753)
const radius = ref(props.radius || 150)
let L, map, marker, circle

function redraw() {
  marker?.setLatLng([lat.value, lng.value])
  circle?.setLatLng([lat.value, lng.value]).setRadius(radius.value)
}
function emitAll() { emit('update:lat', +lat.value.toFixed(6)); emit('update:lng', +lng.value.toFixed(6)); emit('update:radius', radius.value) }
function setPoint(a, b, zoom) { lat.value = a; lng.value = b; redraw(); emitAll(); map.setView([a, b], zoom || Math.max(map.getZoom(), 15)) }

onMounted(async () => {
  // loaded on the client only: leaflet touches `window` at import time, which breaks server-side rendering checks
  L = (await import('leaflet')).default; await import('leaflet/dist/leaflet.css')
  if (!el.value) return
  map = L.map(el.value, { zoomControl: true, attributionControl: true }).setView([lat.value, lng.value], props.lat ? 16 : 11)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map)
  marker = L.circleMarker([lat.value, lng.value], { radius: 7, color: '#1C84C6', fillColor: '#1C84C6', fillOpacity: 1 }).addTo(map)
  circle = L.circle([lat.value, lng.value], { radius: radius.value, color: '#1C84C6', fillColor: '#4FC9F0', fillOpacity: .18, weight: 2 }).addTo(map)
  map.on('click', (e) => setPoint(e.latlng.lat, e.latlng.lng))
  setTimeout(() => map.invalidateSize(), 50) // inside a modal the container is sized after mount
  if (!props.lat) emitAll()
})
onBeforeUnmount(() => map?.remove())
watch(() => [props.lat, props.lng], ([a, b]) => { if (a && b && (a !== lat.value || b !== lng.value)) { lat.value = a; lng.value = b; redraw(); map?.setView([a, b]) } })

function useMyLocation() {
  if (!navigator.geolocation) return ui.toast('المتصفح لا يدعم تحديد الموقع', 'bad')
  locating.value = true
  navigator.geolocation.getCurrentPosition((p) => { locating.value = false; setPoint(p.coords.latitude, p.coords.longitude, 17) }, () => { locating.value = false; ui.toast('تعذر تحديد موقعك — اسمح للمتصفح بالوصول إلى الموقع', 'bad') }, { enableHighAccuracy: true, timeout: 10000 })
}
async function geocode() {
  if (!q.value.trim()) return
  // a Google Maps link (short or full), an embed code, or coordinates → exact point
  if (/google|goo\.gl|maps|°|^\s*-?\d{1,2}\.\d+\s*[, ]\s*-?\d{1,3}\.\d+\s*$/i.test(q.value)) {
    try { const r = await api.post('/geo/resolve', { text: q.value }); mine.value = null; setPoint(r.lat, r.lng, 18); ui.toast('حُدّدت النقطة من الرابط', 'ok'); return } catch (e) { return ui.toast(e.message, 'bad') }
  }
  try {
    const r = await fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=1&accept-language=ar&countrycodes=sa&q=${encodeURIComponent(q.value)}`, { headers: { Accept: 'application/json' } }).then((x) => x.json())
    if (!r.length) return ui.toast('لم يُعثر على المكان', 'warn')
    setPoint(+r[0].lat, +r[0].lon, 16)
  } catch { ui.toast('تعذر البحث عن المكان', 'bad') }
}
</script>

<style scoped>
.map { height: min(320px, 50dvh); border-radius: 12px; border: 1px solid var(--line); overflow: hidden; z-index: 0; }
</style>
