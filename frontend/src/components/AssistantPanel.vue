<template>
  <button v-if="!open" class="as-fab" title="المساعد — اسأل عن أي شيء في المنصة" aria-label="المساعد" @click="toggle"><Icon name="chat" :size="22" /><span>المساعد</span></button>
  <aside v-if="open" class="as-panel" role="dialog" aria-label="المساعد">
    <header class="as-h">
      <div><b>المساعد</b><small>{{ smart ? 'يجيب من بيانات المنصة ضمن صلاحياتك' : 'وضع مختصر — يجيب عن الأسئلة الشائعة ودليل الاستخدام' }}</small></div>
      <button class="tbtn icon" title="مسح المحادثة" @click="msgs = []"><Icon name="trash" :size="16" /></button>
      <button class="tbtn icon" title="إغلاق" @click="toggle"><Icon name="x" :size="18" /></button>
    </header>
    <div ref="box" class="as-body">
      <div v-if="!msgs.length" class="as-empty">
        <p>اسألني عن رصيدك أو طلباتك أو فريقك، أو اطلب تقريرًا، أو اسأل «كيف أفعل…». أجيب فقط بما يحق لك الاطلاع عليه.</p>
        <button v-for="s in suggestions" :key="s" class="chipq" @click="send(s)">{{ s }}</button>
      </div>
      <div v-for="(m, i) in msgs" :key="i" class="as-msg" :class="m.role">
        <div v-if="m.role === 'user'">{{ m.text }}</div>
        <!-- eslint-disable-next-line vue/no-v-html — rendered from escaped text: only links, bold, lists and tables are produced -->
        <div v-else class="md" @click="onLink" v-html="md(m.text)" />
      </div>
      <div v-if="busy" class="as-msg assistant"><span class="dots"><i /><i /><i /></span></div>
    </div>
    <form class="as-f" @submit.prevent="send()">
      <textarea v-model="q" rows="1" class="in" placeholder="اكتب سؤالك…" maxlength="2000" @keydown.enter.exact.prevent="send()" />
      <button class="btn pri sm" :disabled="busy || !q.trim()"><Icon name="send" :size="15" flip-rtl /></button>
    </form>
  </aside>
</template>

<script setup>
import { nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router = useRouter()
const open = ref(false); const busy = ref(false); const q = ref(''); const msgs = ref([]); const box = ref(null)
const smart = ref(false); const suggestions = ref([])
try { msgs.value = JSON.parse(sessionStorage.getItem('hc_assistant') || '[]') } catch { msgs.value = [] }
const save = () => { try { sessionStorage.setItem('hc_assistant', JSON.stringify(msgs.value.slice(-20))) } catch { /* private mode */ } }
async function toggle() {
  open.value = !open.value
  if (open.value && !suggestions.value.length) { try { const s = await api.get('/assistant'); smart.value = s.smart; suggestions.value = s.suggestions } catch { /* offline */ } }
  scroll()
}
const scroll = () => nextTick(() => { if (box.value) box.value.scrollTop = box.value.scrollHeight })
async function send(text) {
  const message = (text ?? q.value).trim()
  if (!message || busy.value) return
  const history = msgs.value.slice(-8).map((m) => ({ role: m.role, text: m.text }))
  msgs.value.push({ role: 'user', text: message }); q.value = ''; busy.value = true; scroll()
  try {
    const r = await api.post('/assistant', { message, history })
    smart.value = r.smart
    msgs.value.push({ role: 'assistant', text: r.answer })
  } catch (e) {
    msgs.value.push({ role: 'assistant', text: e.message || 'تعذر الحصول على إجابة الآن.' })
  } finally { busy.value = false; save(); scroll() }
}
// internal links open inside the portal
function onLink(ev) {
  const a = ev.target.closest('a[data-to]')
  if (!a) return
  ev.preventDefault(); router.push(a.dataset.to)
  if (window.innerWidth < 768) open.value = false
}
// minimal, safe Markdown: the text is HTML-escaped first, then links / bold / lists / tables are formed
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
function inline(s) {
  return s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/\[([^\]]+)\]\((\/[^)\s]*)\)/g, (_, t, href) => `<a href="/app${href}" data-to="${href}">${t}</a>`)
}
function md(raw) {
  const lines = esc(String(raw || '')).split('\n'); let html = ''; let i = 0
  while (i < lines.length) {
    const l = lines[i]
    if (/^\s*\|.*\|\s*$/.test(l)) { // table
      const rows = []
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { if (!/^\s*\|[\s:|-]+\|\s*$/.test(lines[i])) rows.push(lines[i].trim().slice(1, -1).split('|').map((c) => inline(c.trim()))); i++ }
      html += '<div class="tw"><table>' + rows.map((r, k) => '<tr>' + r.map((c) => (k ? `<td>${c}</td>` : `<th>${c}</th>`)).join('') + '</tr>').join('') + '</table></div>'
      continue
    }
    if (/^\s*([-*]|\d+[.)])\s+/.test(l)) { // list
      const ordered = /^\s*\d/.test(l); const items = []
      while (i < lines.length && /^\s*([-*]|\d+[.)])\s+/.test(lines[i])) { items.push(inline(lines[i].replace(/^\s*([-*]|\d+[.)])\s+/, ''))); i++ }
      html += `<${ordered ? 'ol' : 'ul'}>` + items.map((x) => `<li>${x}</li>`).join('') + `</${ordered ? 'ol' : 'ul'}>`
      continue
    }
    if (/^#{1,4}\s/.test(l)) { html += `<b class="h">${inline(l.replace(/^#+\s/, ''))}</b>`; i++; continue }
    html += l.trim() ? `<p>${inline(l)}</p>` : ''
    i++
  }
  return html
}
</script>

<style scoped>
.as-fab { position: fixed; bottom: calc(18px + var(--safe-b, 0px)); inset-inline-end: 18px; z-index: var(--z-popover); display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border: 0; border-radius: 999px; background: var(--brand, var(--pri)); color: #fff; font: inherit; font-weight: 700; box-shadow: 0 8px 24px rgba(15, 20, 48, .3); cursor: pointer; }
.as-panel { position: fixed; bottom: 16px; inset-inline-end: 16px; z-index: var(--z-popover); width: min(420px, calc(100vw - 32px)); height: min(620px, calc(100dvh - 90px)); background: var(--card); border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 20px 60px rgba(0, 0, 0, .3); display: flex; flex-direction: column; overflow: hidden; }
.as-h { display: flex; align-items: center; gap: 6px; padding: 10px 12px; background: var(--brand, var(--pri)); color: #fff; }
.as-h > div { flex: 1; min-width: 0; } .as-h small { display: block; opacity: .85; font-size: 11px; }
.as-h .tbtn { color: #fff; background: transparent; border: 0; }
.as-body { flex: 1; overflow: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; overscroll-behavior: contain; }
.as-empty p { color: var(--muted); font-size: 13px; margin: 0 0 8px; }
.chipq { display: block; width: 100%; text-align: start; margin-bottom: 6px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 10px; background: var(--soft); color: inherit; font: inherit; font-size: 13px; cursor: pointer; }
.as-msg { max-width: 92%; padding: 8px 12px; border-radius: 12px; font-size: 14px; line-height: 1.7; white-space: pre-wrap; }
.as-msg.user { align-self: flex-end; background: var(--infobg); }
.as-msg.assistant { align-self: flex-start; background: var(--soft); white-space: normal; }
.md :deep(p) { margin: 0 0 6px; } .md :deep(ul), .md :deep(ol) { margin: 4px 0; padding-inline-start: 18px; } .md :deep(table) { font-size: 12px; } .md :deep(.h) { display: block; margin: 6px 0 4px; }
.as-f { display: flex; gap: 6px; padding: 10px; border-top: 1px solid var(--line); align-items: flex-end; }
.as-f textarea { flex: 1; resize: none; max-height: 120px; }
.dots i { display: inline-block; width: 6px; height: 6px; margin: 0 2px; border-radius: 50%; background: var(--muted); animation: b 1s infinite; } .dots i:nth-child(2) { animation-delay: .15s; } .dots i:nth-child(3) { animation-delay: .3s; }
@keyframes b { 0%, 80%, 100% { opacity: .3 } 40% { opacity: 1 } }
@media print { .as-fab, .as-panel { display: none; } }
</style>
