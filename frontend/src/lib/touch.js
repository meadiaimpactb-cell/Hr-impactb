// Small-screen and touch helpers applied once for the whole app (no component changes needed):
//  - numeric / phone / email fields get the right mobile keyboard (inputmode) and Enter key hint;
//  - Arabic-Indic (٠–٩) and Persian (۰–۹) digits typed into numeric fields become Western digits before
//    Vue reads the value, so validation and saving always see 0–9;
//  - on phones the focused field is scrolled to the middle of the screen so the on-screen keyboard or a
//    sticky footer never covers it.
const DIGITS = /[٠-٩۰-۹]/g
const toLatin = (s) => s.replace(DIGITS, (d) => String(d.charCodeAt(0) & 0xf))

// validation feedback: the first field error or error notice is announced and, on phones, scrolled into view
function surfaceErrors(root) {
  const first = root.matches?.('.fg .e, .notice.bad, .in.err') ? root : root.querySelector?.('.fg .e, .notice.bad, .in.err')
  if (!first) return
  if (first.classList.contains('notice')) first.setAttribute('role', 'alert')
  else if (first.classList.contains('e')) first.setAttribute('role', 'alert')
  if (window.innerWidth < 1024 && !first.closest('.mo')) first.scrollIntoView({ block: 'center', behavior: 'smooth' })
}

function enhance(root) {
  surfaceErrors(root)
  const q = (sel) => (root.matches?.(sel) ? [root] : []).concat([...(root.querySelectorAll?.(sel) || [])])
  q('input[type=number]:not([inputmode])').forEach((i) => i.setAttribute('inputmode', i.step && !/^\d+$/.test(i.step) ? 'decimal' : 'numeric'))
  q('input[type=tel]:not([inputmode])').forEach((i) => i.setAttribute('inputmode', 'tel'))
  q('input[type=email]:not([inputmode])').forEach((i) => i.setAttribute('inputmode', 'email'))
  q('input[type=search]:not([enterkeyhint])').forEach((i) => i.setAttribute('enterkeyhint', 'search'))
  q('input[type=url]:not([inputmode])').forEach((i) => i.setAttribute('inputmode', 'url'))
}

export function installTouchHelpers() {
  enhance(document.body)
  new MutationObserver((muts) => muts.forEach((m) => m.addedNodes.forEach((n) => n.nodeType === 1 && enhance(n)))).observe(document.body, { childList: true, subtree: true })

  // capture phase: runs before Vue's v-model listener on the element
  document.addEventListener('input', (e) => {
    const t = e.target
    if (!(t instanceof HTMLInputElement) || t.type === 'number') return
    const mode = t.getAttribute('inputmode')
    if (!['numeric', 'decimal', 'tel'].includes(mode) && !t.classList.contains('num')) return
    if (!DIGITS.test(t.value)) return
    const pos = t.selectionStart
    t.value = toLatin(t.value)
    try { t.setSelectionRange(pos, pos) } catch { /* not all types support it */ }
  }, true)

  document.addEventListener('focusin', (e) => {
    const t = e.target
    if (window.innerWidth >= 1024 || !(t instanceof HTMLElement) || !t.matches('input,select,textarea')) return
    setTimeout(() => { if (document.activeElement === t) t.scrollIntoView({ block: 'center', behavior: 'smooth' }) }, 300)
  })
}
