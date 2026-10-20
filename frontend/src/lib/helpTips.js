// «؟» help icons next to every field label, section heading and table column that has an explanation.
// Hover shows the text; click (or tap) opens it in a dialog. The texts live in helpTexts.js (one place to edit).
import { alertBox } from '../dialog'

let TEXTS = null
const load = async () => (TEXTS ??= (await import('./helpTexts.js').catch(() => ({ default: {} }))).default || {})

export const normalise = (s) => String(s || '').split(/\s[—–]\s?/)[0].replace(/\([^)]*\)/g, ' ').replace(/[*:؟?…]/g, ' ').replace(/\s+/g, ' ').trim()

// own text of an element, without the text of nested inputs / icons / pills / existing tips
function ownText(el) {
  let t = ''
  el.childNodes.forEach((n) => {
    if (n.nodeType === 3) t += n.textContent
    else if (n.nodeType === 1 && !n.matches('input,select,textarea,button,svg,.hlp,.pill,.sub,.tiny,.req,.muted,.e')) t += n.textContent
  })
  return normalise(t)
}

function decorate(root) {
  if (!TEXTS) return
  root.querySelectorAll('.fg > label, .card > h3, .card h3, th, .kv th, .page-h h1').forEach((el) => {
    if (el.dataset.hlp) return
    el.dataset.hlp = '1'
    const key = ownText(el)
    const text = key && TEXTS[key]
    if (!text) return
    const b = document.createElement('span')
    b.className = 'hlp'
    b.setAttribute('role', 'button')
    b.setAttribute('tabindex', '0')
    b.setAttribute('aria-label', 'شرح: ' + key)
    b.title = text
    b.textContent = '؟'
    const open = (e) => { e.preventDefault(); e.stopPropagation(); alertBox(text, { title: key, icon: 'info' }) }
    b.addEventListener('click', open)
    b.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') open(e) })
    el.appendChild(b)
  })
}

export async function startHelpTips() {
  await load()
  let pending = false
  const run = () => { pending = false; decorate(document.body) }
  new MutationObserver(() => { if (!pending) { pending = true; requestAnimationFrame(run) } }).observe(document.body, { childList: true, subtree: true })
  run()
}
