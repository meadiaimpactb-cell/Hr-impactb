// v-dt — turns any existing list table into a data table without rewriting its template:
// search, click-to-sort headers, pagination with page size, column chooser, CSV export of the visible columns.
// Usage: <div v-dt class="tw"><table>…</table></div>   or   v-dt="{ size: 25, export: 'attendance', search: false }"
// Works with Vue-rendered rows: it only hides/orders existing <tr> elements and re-applies itself whenever Vue patches the body.
import { exportXlsx } from '../excel'
import { norm } from '../utils'

const STORE = (k) => `vdt:${location.pathname}:${k}`
const read = (k, d) => { try { return JSON.parse(localStorage.getItem(STORE(k))) ?? d } catch { return d } }
const write = (k, v) => { try { localStorage.setItem(STORE(k), JSON.stringify(v)) } catch { /* private mode */ } }
const svg = (d) => `<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`
const I = { search: svg('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'), cols: svg('<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/>'), dl: svg('<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>') }

function cellValue(td) {
  const t = (td?.innerText ?? td?.textContent ?? '').trim()
  const n = t.replace(/[,\s٬]/g, '').replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
  if (/^-?\d+(\.\d+)?%?$/.test(n)) return parseFloat(n)
  const dm = /^(\d{4})-(\d{2})-(\d{2})/.exec(t) || /^(\d{2})\/(\d{2})\/(\d{4})/.exec(t)
  if (dm) return dm[1].length === 4 ? `${dm[1]}${dm[2]}${dm[3]}` : `${dm[3]}${dm[2]}${dm[1]}`
  return t
}

class Enhancer {
  constructor(wrap, opts) {
    this.wrap = wrap
    this.table = wrap.querySelector('table')
    this.opts = { size: 25, search: true, export: '', ...(opts || {}) }
    this.idx = [...document.querySelectorAll('[data-vdt]')].length
    wrap.setAttribute('data-vdt', '')
    this.key = this.idx
    this.q = ''
    this.page = 1
    this.size = read(`${this.key}:size`, this.opts.size)
    this.sort = { col: -1, dir: 1 }
    this.hidden = new Set(read(`${this.key}:hidden`, []))
    this.build()
    this.observer = new MutationObserver(() => this.schedule())
    this.observe()
    this.apply()
  }

  observe() { this.observer.observe(this.table, { childList: true, subtree: true, characterData: true }) }

  schedule() {
    if (this.pending) return
    this.pending = requestAnimationFrame(() => { this.pending = null; this.apply() })
  }

  headers() { return [...(this.table.tHead?.rows[0]?.cells || [])] }

  rows() {
    const body = this.table.tBodies[0]
    if (!body) return []
    // data rows only: skip empty/skeleton rows that span the whole table
    return [...body.rows].filter((r) => !(r.cells.length === 1 && r.cells[0].colSpan > 1))
  }

  build() {
    const bar = document.createElement('div')
    bar.className = 'vdt-bar'
    bar.innerHTML = `${this.opts.search ? `<label class="search vdt-search">${I.search}<input class="in" type="search" placeholder="بحث في الجدول…" aria-label="بحث في الجدول"></label>` : '<span></span>'}
      <span class="vdt-count tiny muted"></span>
      <span class="vdt-tools">
        <span class="vdt-pop-wrap"><button type="button" class="btn ghost xs vdt-cols-btn">${I.cols} الأعمدة</button><div class="vdt-pop" hidden></div></span>
        <button type="button" class="btn ghost xs vdt-export">${I.dl} تصدير Excel</button>
      </span>`
    const foot = document.createElement('div')
    foot.className = 'vdt-foot'
    this.wrap.parentNode.insertBefore(bar, this.wrap)
    this.wrap.parentNode.insertBefore(foot, this.wrap.nextSibling)
    this.bar = bar; this.foot = foot

    bar.querySelector('input')?.addEventListener('input', (e) => { this.q = norm(e.target.value); this.page = 1; this.apply() })
    bar.querySelector('.vdt-export').addEventListener('click', () => this.exportCsv())
    const pop = bar.querySelector('.vdt-pop')
    bar.querySelector('.vdt-cols-btn').addEventListener('click', (e) => { e.stopPropagation(); this.fillCols(); pop.hidden = !pop.hidden })
    document.addEventListener('click', (this.closePop = (e) => { if (!pop.contains(e.target)) pop.hidden = true }))
    foot.addEventListener('click', (e) => {
      const b = e.target.closest('[data-p]'); if (!b || b.disabled) return
      this.page = +b.dataset.p; this.apply(); this.wrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    })
    foot.addEventListener('change', (e) => { if (e.target.matches('select')) { this.size = +e.target.value; write(`${this.key}:size`, this.size); this.page = 1; this.apply() } })
    this.table.tHead?.addEventListener('click', (e) => {
      const th = e.target.closest('th'); if (!th || e.target.closest('input,button,a,select')) return
      const col = this.headers().indexOf(th)
      if (col < 0 || !th.textContent.trim() || !this.sortable) return
      this.sort = this.sort.col === col ? { col, dir: -this.sort.dir } : { col, dir: 1 }
      this.apply()
    })
  }

  fillCols() {
    const pop = this.bar.querySelector('.vdt-pop')
    pop.innerHTML = ''
    this.headers().forEach((th, i) => {
      const label = th.textContent.trim(); if (!label) return
      const l = document.createElement('label'); l.className = 'chk'
      l.innerHTML = `<input type="checkbox" ${this.hidden.has(i) ? '' : 'checked'}> ${label}`
      l.querySelector('input').addEventListener('change', (e) => { e.target.checked ? this.hidden.delete(i) : this.hidden.add(i); write(`${this.key}:hidden`, [...this.hidden]); this.apply() })
      pop.appendChild(l)
    })
  }

  apply() {
    this.observer.disconnect()
    try {
      const rows = this.rows()
      const heads = this.headers()
      // multi-row records (detail rows) cannot be sorted safely
      this.sortable = rows.every((r) => r.cells.length >= heads.length - 1)
      heads.forEach((th, i) => {
        th.classList.toggle('vdt-sortable', this.sortable && !!th.textContent.trim())
        th.dataset.sort = this.sort.col === i ? (this.sort.dir > 0 ? 'asc' : 'desc') : ''
        th.style.display = this.hidden.has(i) ? 'none' : ''
      })
      for (const r of this.table.rows) [...r.cells].forEach((c, i) => { if (r.parentNode !== this.table.tHead) c.style.display = this.hidden.has(i) ? 'none' : '' })

      let list = rows
      if (this.q) list = list.filter((r) => norm(r.textContent).includes(this.q))
      if (this.sortable && this.sort.col >= 0) {
        const c = this.sort.col, d = this.sort.dir
        list = [...list].sort((a, b) => {
          const x = cellValue(a.cells[c]), y = cellValue(b.cells[c])
          return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'ar', { numeric: true })) * d
        })
        const body = this.table.tBodies[0]
        list.forEach((r) => body.appendChild(r))
      }
      const total = list.length
      const pages = Math.max(1, Math.ceil(total / this.size))
      if (this.page > pages) this.page = pages
      const from = (this.page - 1) * this.size
      const shown = new Set(list.slice(from, from + this.size))
      rows.forEach((r) => { r.style.display = shown.has(r) ? '' : 'none' })

      this.bar.querySelector('.vdt-count').textContent = rows.length ? (this.q ? `${total} من ${rows.length}` : `${rows.length} سجل`) : ''
      this.bar.style.display = rows.length || this.q ? '' : 'none'
      this.renderFoot(total, pages, from)
    } finally {
      this.observe()
    }
  }

  renderFoot(total, pages, from) {
    if (total <= Math.min(...[10, this.size])) { this.foot.innerHTML = ''; return }
    const p = this.page
    const nums = pages <= 7 ? [...Array(pages)].map((_, i) => i + 1) : [...new Set([1, p - 1, p, p + 1, pages])].filter((x) => x >= 1 && x <= pages).sort((a, b) => a - b)
    let html = ''
    nums.forEach((n, i) => { if (i && n - nums[i - 1] > 1) html += '<span class="vdt-gap">…</span>'; html += `<button type="button" class="btn xs ${n === p ? 'pri' : 'ghost'}" data-p="${n}">${n}</button>` })
    this.foot.innerHTML = `<span class="tiny muted">${from + 1}–${Math.min(from + this.size, total)} من ${total}</span>
      <span class="vdt-pages">
        <select class="in xs" aria-label="عدد الصفوف">${[10, 25, 50, 100].map((n) => `<option value="${n}" ${n === this.size ? 'selected' : ''}>${n} / صفحة</option>`).join('')}</select>
        <button type="button" class="btn ghost xs" data-p="1" ${p === 1 ? 'disabled' : ''} aria-label="الأولى">«</button>
        <button type="button" class="btn ghost xs" data-p="${p - 1}" ${p === 1 ? 'disabled' : ''} aria-label="السابقة">‹</button>
        ${html}
        <button type="button" class="btn ghost xs" data-p="${p + 1}" ${p === pages ? 'disabled' : ''} aria-label="التالية">›</button>
        <button type="button" class="btn ghost xs" data-p="${pages}" ${p === pages ? 'disabled' : ''} aria-label="الأخيرة">»</button>
      </span>`
  }

  exportCsv() {
    const heads = this.headers()
    const cols = heads.map((th, i) => i).filter((i) => !this.hidden.has(i) && heads[i].textContent.trim())
    let list = this.rows()
    if (this.q) list = list.filter((r) => norm(r.textContent).includes(this.q))
    const data = list.map((r) => cols.map((i) => (r.cells[i]?.innerText || '').replace(/\s+/g, ' ').trim()))
    const name = this.opts.export || (document.querySelector('.page-h h1')?.textContent.trim() || 'table')
    exportXlsx(name, [cols.map((i) => heads[i].textContent.trim()), ...data])
  }

  destroy() {
    this.observer.disconnect()
    document.removeEventListener('click', this.closePop)
    this.bar?.remove(); this.foot?.remove()
  }
}

export default {
  mounted(el, binding) { if (el.querySelector('table')) el.__vdt = new Enhancer(el, binding.value) },
  updated(el) {
    // the table may appear after data loads (v-if inside the wrapper)
    if (!el.__vdt && el.querySelector('table')) el.__vdt = new Enhancer(el)
    else if (el.__vdt && el.__vdt.table !== el.querySelector('table')) { el.__vdt.destroy(); el.__vdt = el.querySelector('table') ? new Enhancer(el) : null }
  },
  unmounted(el) { el.__vdt?.destroy() },
}
