<template>
  <div class="lp">
    <div class="row mb" style="gap:8px">
      <div class="search" style="flex:1"><Icon name="search" :size="16" /><input v-model="q" class="in" placeholder="ابحث عن عنوان أو مكان… ثم Enter" @keydown.enter.prevent="geocode" /></div>
      <button type="button" class="btn sec sm" :class="{ loading: locating }" @click="useMyLocation"><Icon name="map-pin" :size="16" /> موقعي الحالي</button>
    </div>
    <div ref="el" class="map" />
    <div class="row mt" style="gap:12px;align-items:center">
      <div class="fg" style="flex:1;margin:0"><label>نطاق التحضير المسموح: <b>{{ radius }} م</b></label><input v-model.number="radius" type="range" min="50" max="2000" step="25" style="width:100%" @input="redraw(); emitAll()" /></div>
      <div class="tiny muted num" style="direction:ltr">{{ lat?.toFixed(6) }}, {{ lng?.toFixed(6) }}</div>
    </div>
    <p class="tiny muted" style="margin:4px 0 0">انقر على الخريطة لتحديد نقطة الموقع، أو اسحب الدائرة.</p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useUi } from '../stores/ui'

// v-model:lat / v-model:lng / v-model:radius — a point with an allowed radius, on an OpenStreetMap base map.
const props = defineProps({ lat: Number, lng: Number, radius: { type: Number, default: 150 } })
const emit = defineEmits(['update:lat', 'update:lng', 'update:radius'])
const ui = useUi()
const el = ref(null)
const q = ref('')
const locating = ref(false)
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
