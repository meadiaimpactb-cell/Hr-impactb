// Every drop-down list in the portal gets a search box (owner's note: "عند أي قائمة منسدلة لازم يكون خيار البحث
// للاختيار"). Applied once for the whole app: opening any native <select> shows a searchable list instead of the
// browser's picker. Choosing an item sets the select and fires its normal change/input events, so v-model, @change
// and keyboard use keep working with no change in the screens. A select marked data-native keeps the browser's list.

const norm = (s) => String(s || '')
  .toLowerCase()
  .replace(/[ً-ْـ]/g, '') // harakat, tatweel
  .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
  .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x660))
  .trim()

let panel = null
let current = null
let items = []
let active = -1

function enhanceable(el) {
  return el instanceof HTMLSelectElement && !el.multiple && !el.disabled && el.size <= 1 && !el.hasAttribute('data-native') && el.options.length > 0
}

function close(refocus) {
  if (!panel) return
  panel.remove()
  panel = null
  window.removeEventListener('resize', onViewport, true)
  window.removeEventListener('scroll', onScroll, true)
  document.removeEventListener('mousedown', onOutside, true)
  document.removeEventListener('touchstart', onOutside, true)
  if (refocus && current) current.focus({ preventScroll: true })
  current = null
}

function choose(i) {
  const it = items[i]
  if (!it || it.disabled || !current) return
  const sel = current
  if (sel.selectedIndex !== it.index) {
    sel.selectedIndex = it.index
    sel.dispatchEvent(new Event('input', { bubbles: true }))
    sel.dispatchEvent(new Event('change', { bubbles: true }))
  }
  close(true)
}

function render(q) {
  const list = panel.querySelector('.ssx-list')
  const nq = norm(q)
  list.innerHTML = ''
  let shown = 0
  let group = null
  items.forEach((it, i) => {
    it.el = null
    if (nq && !it.key.includes(nq)) return
    if (it.group && it.group !== group) {
      group = it.group
      const h = document.createElement('div')
      h.className = 'ssx-group'
      h.textContent = it.group
      list.appendChild(h)
    }
    const b = document.createElement('div')
    b.className = 'ssx-opt' + (it.selected ? ' sel' : '') + (it.disabled ? ' dis' : '')
    b.setAttribute('role', 'option')
    b.setAttribute('aria-selected', it.selected ? 'true' : 'false')
    b.textContent = it.label || '—'
    b.addEventListener('mousedown', (e) => e.preventDefault())
    b.addEventListener('click', () => choose(i))
    it.el = b
    list.appendChild(b)
    shown++
  })
  if (!shown) {
    const e = document.createElement('div')
    e.className = 'ssx-empty'
    e.textContent = 'لا نتائج'
    list.appendChild(e)
  }
  const vis = items.map((it, i) => (it.el && !it.disabled ? i : -1)).filter((i) => i >= 0)
  active = vis.includes(active) ? active : (vis.find((i) => items[i].selected) ?? vis[0] ?? -1)
  highlight()
}

function highlight() {
  items.forEach((it, i) => it.el?.classList.toggle('act', i === active))
  items[active]?.el?.scrollIntoView({ block: 'nearest' })
}

function move(step) {
  const vis = items.map((it, i) => (it.el && !it.disabled ? i : -1)).filter((i) => i >= 0)
  if (!vis.length) return
  const at = vis.indexOf(active)
  active = vis[Math.max(0, Math.min(vis.length - 1, (at < 0 ? -1 : at) + step))]
  highlight()
}

function place() {
  if (!panel || !current) return
  const r = current.getBoundingClientRect()
  const vw = window.innerWidth
  const vh = window.innerHeight
  if (vw < 640) { // phones: a sheet at the bottom of the screen
    Object.assign(panel.style, { left: '8px', right: '8px', width: 'auto', top: 'auto', bottom: '8px', maxHeight: '70dvh' })
    return
  }
  const w = Math.min(Math.max(r.width, 240), 420)
  const below = vh - r.bottom
  const h = Math.min(360, Math.max(below, r.top) - 12)
  const top = below >= Math.min(260, h) || below >= r.top ? r.bottom + 4 : Math.max(8, r.top - h - 4)
  let left = document.dir === 'rtl' || document.documentElement.dir === 'rtl' ? r.right - w : r.left
  left = Math.max(8, Math.min(left, vw - w - 8))
  Object.assign(panel.style, { left: `${left}px`, top: `${top}px`, width: `${w}px`, maxHeight: `${h}px`, right: 'auto', bottom: 'auto' })
}

function onViewport() { place() }
let openedAt = 0
// a page scroll closes the list on desktop; on phones the list is a bottom sheet, and the keyboard opening scrolls the page
function onScroll(e) { if (panel && !panel.contains(e.target) && window.innerWidth >= 640 && Date.now() - openedAt > 350) close(false) }
function onOutside(e) { if (panel && !panel.contains(e.target) && e.target !== current) close(false) }

function open(sel) {
  close(false)
  openedAt = Date.now()
  current = sel
  let group = null
  items = []
  ;[...sel.options].forEach((o, index) => {
    group = o.parentElement?.tagName === 'OPTGROUP' ? o.parentElement.label : null
    if (o.hidden) return
    items.push({ index, label: o.text, key: norm(o.text + ' ' + (o.value || '')), disabled: o.disabled || o.parentElement?.disabled, selected: index === sel.selectedIndex, group })
  })
  panel = document.createElement('div')
  panel.className = 'ssx'
  panel.setAttribute('role', 'listbox')
  panel.innerHTML = '<div class="ssx-s"><input type="search" class="in" autocomplete="off" enterkeyhint="search" placeholder="ابحث في القائمة…" aria-label="بحث" /></div><div class="ssx-list"></div>'
  document.body.appendChild(panel)
  const input = panel.querySelector('input')
  input.addEventListener('input', () => render(input.value))
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1) }
    else if (e.key === 'Enter') { e.preventDefault(); choose(active) }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true) }
    else if (e.key === 'Tab') close(false)
  })
  render('')
  place()
  window.addEventListener('resize', onViewport, true)
  window.addEventListener('scroll', onScroll, true)
  document.addEventListener('mousedown', onOutside, true)
  document.addEventListener('touchstart', onOutside, true)
  // phones: don't pop the keyboard over a short list
  if (window.innerWidth >= 640 || items.length > 8) input.focus({ preventScroll: true })
}

export function installSelectSearch() {
  let touch = null
  document.addEventListener('mousedown', (e) => {
    const el = e.target
    if (!enhanceable(el) || e.button !== 0) return
    e.preventDefault()
    el.focus({ preventScroll: true })
    panel && current === el ? close(false) : open(el)
  }, true)
  // touch: a tap (not a scroll) on a select opens the searchable list instead of the phone's picker
  document.addEventListener('touchstart', (e) => {
    touch = enhanceable(e.target) ? { el: e.target, x: e.touches[0].clientX, y: e.touches[0].clientY } : null
  }, { capture: true, passive: true })
  document.addEventListener('touchend', (e) => {
    if (!touch || e.target !== touch.el) return
    const t = e.changedTouches[0]
    const moved = Math.abs(t.clientX - touch.x) > 10 || Math.abs(t.clientY - touch.y) > 10
    const el = touch.el
    touch = null
    if (moved) return
    e.preventDefault()
    open(el)
  }, { capture: true, passive: false })
  document.addEventListener('keydown', (e) => {
    const el = e.target
    if (!enhanceable(el) || panel) return
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'F4' || (e.altKey && e.key === 'ArrowDown')) { e.preventDefault(); open(el) }
  }, true)
}
