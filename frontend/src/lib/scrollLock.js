// Body scroll lock that survives iOS Safari (overflow:hidden alone does not): the body is fixed at the current
// scroll offset while a dialog or the drawer is open, and the offset is restored on release. Counted, so nested
// dialogs release only when the last one closes.
let count = 0
let y = 0
export function lockScroll(on) {
  if (on) {
    if (count++ === 0) {
      y = window.scrollY
      document.body.style.top = `-${y}px`
      document.body.classList.add('scroll-locked')
    }
  } else if (count > 0 && --count === 0) {
    document.body.classList.remove('scroll-locked')
    document.body.style.top = ''
    window.scrollTo(0, y)
  }
}

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
export const focusables = (root) => [...root.querySelectorAll(FOCUSABLE)].filter((e) => e.offsetParent !== null || e === document.activeElement)

/** Keeps Tab inside `root` while it is open; returns a cleanup function. Focus moves to the first field (or the root). */
export function trapFocus(root) {
  const prev = document.activeElement
  const first = focusables(root).find((e) => !e.classList.contains('x')) || root
  if (!root.hasAttribute('tabindex')) root.setAttribute('tabindex', '-1')
  setTimeout(() => first.focus({ preventScroll: true }), 30)
  const onKey = (e) => {
    if (e.key !== 'Tab') return
    const list = focusables(root)
    if (!list.length) { e.preventDefault(); root.focus(); return }
    const a = list[0], b = list[list.length - 1]
    if (e.shiftKey && (document.activeElement === a || document.activeElement === root)) { e.preventDefault(); b.focus() }
    else if (!e.shiftKey && document.activeElement === b) { e.preventDefault(); a.focus() }
  }
  root.addEventListener('keydown', onKey)
  return () => { root.removeEventListener('keydown', onKey); if (prev && prev.focus && document.contains(prev)) prev.focus({ preventScroll: true }) }
}
