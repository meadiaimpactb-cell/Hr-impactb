// Formatted .xlsx export (ExcelJS) and import (SheetJS): right-to-left sheets, Latin digits, styled header,
// borders, frozen header, auto filter. Both libraries load on demand so they never weigh on the first page load.
let XLSX = null
let EXCELJS = null
const sheetjs = async () => (XLSX ||= await import('xlsx'))
const exceljs = async () => (EXCELJS ||= (await import('exceljs')).default)

const latin = (s) => String(s).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
const asCell = (v) => {
  if (v === null || v === undefined) return ''
  if (typeof v === 'number' || typeof v === 'boolean') return v
  const s = latin(v).trim()
  return /^-?\d{1,15}(\.\d+)?$/.test(s) && !/^0\d/.test(s) ? Number(s) : s // keep IDs with leading zeros / IBANs as text
}

const BRAND = { navy: 'FF262E6B', blue: 'FF1C84C6', black: 'FF000000', gold: 'FFF79646', soft: 'FFF2F2F2', line: 'FFBFBFBF', white: 'FFFFFFFF' }
const FONT = 'Calibri'
const thin = { style: 'thin', color: { argb: BRAND.line } }
const BORDER = { top: thin, left: thin, bottom: thin, right: thin }
const fill = (argb) => ({ type: 'pattern', pattern: 'solid', fgColor: { argb } })

const CATEGORY_BY_PATH = [[/payroll|custody|advance|insurance/, 'finance'], [/employees|users|recruitment|companies/, 'people']]

/** Keeps a copy in the user's download center (silent: an export never fails because of it). */
async function keepCopy(blob, name, opts) {
  try {
    const token = localStorage.getItem('hc_token')
    if (!token) return
    const fd = new FormData()
    fd.append('file', blob, `${name}.xlsx`)
    const kind = (opts.kind || document.querySelector('main h1, h1')?.textContent || 'تصدير Excel').trim().slice(0, 120)
    const d = new Date(); const p2 = (n) => String(n).padStart(2, '0')
    // technical names (e.g. "users") become "<page> <dd-mm-yyyy>" so the list reads clearly
    const shown = /[؀-ۿ]/.test(name) ? name : `${kind} ${p2(d.getDate())}-${p2(d.getMonth() + 1)}-${d.getFullYear()}`
    fd.append('name', shown.slice(0, 200))
    fd.append('kind', kind)
    fd.append('category', opts.category || CATEGORY_BY_PATH.find(([re]) => re.test(location.pathname))?.[1] || 'reports')
    await fetch('/api/v1/exports', { method: 'POST', body: fd, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
  } catch { /* ignore */ }
}

async function deliver(wb, name, opts) {
  const blob = new Blob([await wb.xlsx.writeBuffer()], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url = URL.createObjectURL(blob)
  const link = Object.assign(document.createElement('a'), { href: url, download: `${name}.xlsx` })
  document.body.appendChild(link); link.click(); link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 60000)
  if (opts.keep !== false) keepCopy(blob, name, opts)
}

const p2 = (n) => String(n).padStart(2, '0')
const dmy = (d = new Date()) => `${p2(d.getDate())}/${p2(d.getMonth() + 1)}/${d.getFullYear()}`
const GROUP = 'مجموعة وادي الأثر المالية'

/** Company shown at the top of every export: the company chosen in the top bar, else the group. */
async function currentCompany() {
  try {
    const { useUi } = await import('./stores/ui')
    const { useAuth } = await import('./stores/auth')
    const ui = useUi(); const auth = useAuth()
    return auth.meta?.companies?.find((c) => c.id === ui.company)?.name || GROUP
  } catch { return GROUP }
}
/** Report title from the page: its heading and the open tab. */
function pageTitle() {
  if (typeof document === 'undefined') return ''
  const h = document.querySelector('main h1, .page-h h1, h1')?.textContent?.trim() || ''
  const tab = document.querySelector('.tabs button.on')?.textContent?.replace(/\(\d+\)/g, '').trim() || ''
  return [h, tab && tab !== h ? tab : ''].filter(Boolean).join(' — ')
}

/** The three lines every report starts with: company, report title, date (rows 1–3, row 4 left blank). */
function writeHead(ws, cols, { company, title, period }) {
  const last = Math.max(cols, 4)
  const line = (r, value, font, fillArgb, height) => {
    ws.mergeCells(r, 1, r, last)
    Object.assign(ws.getCell(r, 1), { value, font: { name: FONT, ...font }, alignment: { horizontal: 'center', vertical: 'middle', wrapText: true, readingOrder: 'rtl' }, ...(fillArgb ? { fill: fill(fillArgb) } : {}) })
    ws.getRow(r).height = height
  }
  line(1, company, { size: 15, bold: true, color: { argb: BRAND.white } }, BRAND.navy, 28)
  line(2, title, { size: 13, bold: true, color: { argb: BRAND.navy } }, 'FFE9ECF7', 24)
  line(3, `التاريخ: ${dmy()}${period ? `     |     الفترة: ${period}` : ''}`, { size: 11, color: { argb: 'FF404040' } }, 'FFF2F2F2', 20)
}

const sheetName = (t) => latin(t).replace(/[\\/?*[\]:]/g, ' ').slice(0, 31) || 'Sheet1'

/** rows: [[header…], [cell…], …] — or sheets: { 'اسم الورقة': rows, … }.
 *  opts: { kind, category, keep, title, company, period }. Every sheet starts with company, report title and date. */
export async function exportXlsx(name, rowsOrSheets, opts = {}) {
  const ExcelJS = await exceljs()
  const wb = new ExcelJS.Workbook()
  wb.creator = 'HC'
  wb.views = [{ rightToLeft: true }]
  const company = opts.company || await currentCompany()
  const baseTitle = opts.title || pageTitle() || opts.kind || name
  const sheets = Array.isArray(rowsOrSheets) ? { [name]: rowsOrSheets } : rowsOrSheets
  const H = 5 // table header row (rows 1–3: company, title, date; row 4 blank)
  for (const [title, rows] of Object.entries(sheets)) {
    const data = rows.map((r) => r.map(asCell))
    const cols = (data[0] || []).length
    const ws = wb.addWorksheet(sheetName(title), { views: [{ rightToLeft: true, state: 'frozen', ySplit: H }], pageSetup: { orientation: cols > 6 ? 'landscape' : 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 0 } })
    writeHead(ws, cols, { company, title: Array.isArray(rowsOrSheets) ? baseTitle : `${baseTitle} — ${title}`, period: opts.period })
    data.forEach((r, k) => { ws.getRow(H + k).values = r })
    for (let c = 1; c <= cols; c++) {
      const len = Math.max(...data.slice(0, 300).map((r) => String(r[c - 1] ?? '').length))
      ws.getColumn(c).width = Math.min(55, Math.max(10, len + 3))
    }
    data.forEach((r, k) => {
      const i = H + k
      const row = ws.getRow(i)
      for (let c = 1; c <= cols; c++) {
        const cell = row.getCell(c)
        const head = k === 0
        cell.border = BORDER
        cell.font = { name: FONT, size: 11, ...(head ? { bold: true, color: { argb: BRAND.white } } : {}) }
        cell.alignment = { vertical: 'middle', horizontal: head || typeof cell.value === 'number' ? 'center' : 'right', wrapText: head || String(cell.value ?? '').length > 55, readingOrder: 'rtl' }
        if (typeof cell.value === 'number' && !head) cell.numFmt = Number.isInteger(cell.value) ? '0' : '#,##0.00'
        if (head) cell.fill = fill(BRAND.navy)
        else if (k % 2 === 0) cell.fill = fill('FFF7F8FC')
      }
      if (k === 0) row.height = 26
    })
    if (data.length > 1 && cols) ws.autoFilter = { from: { row: H, column: 1 }, to: { row: H + data.length - 1, column: cols } }
    ws.pageSetup.printTitlesRow = `${H}:${H}`
  }
  await deliver(wb, name, opts)
}

/** Reads the first sheet of an uploaded file into row objects keyed by the header row. The header row is found
 *  automatically (forms may have a title block above the table) and merged cells are filled into every cell they
 *  cover, so a task merged over several rows, or one achievement merged over several tasks, reads correctly. */
export async function readXlsx(file) {
  const X = await sheetjs()
  const wb = X.read(await file.arrayBuffer(), { type: 'array' })
  const ws = wb.Sheets[wb.SheetNames[0]]
  const grid = X.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false, blankrows: true })
  for (const m of ws['!merges'] || []) {
    const v = grid[m.s.r]?.[m.s.c] ?? ''
    for (let r = m.s.r; r <= m.e.r; r++) {
      grid[r] ||= []
      for (let c = m.s.c; c <= m.e.c; c++) grid[r][c] = v
    }
  }
  const known = ['المهام الوظيفية', 'رقم البند', 'البند', 'المنجز', 'رقم الموظف', 'اسم الموظف']
  let h = grid.findIndex((r) => (r || []).some((c) => known.includes(String(c).trim())))
  if (h < 0) h = 0
  const head = grid[h].map((c) => String(c).trim())
  return grid.slice(h + 1).filter((r) => (r || []).some((c) => String(c).trim() !== '')).map((r) => Object.fromEntries(head.map((k, i) => [k, r[i] ?? ''])))
}

// ---- Monthly report form: the group's approved layout ----
// Title, employee details, then the table: job merged down the side, each axis merged over its tasks, the
// employee's obligations (axis, task, KPI) pre-filled on grey and locked, white columns for the employee.
// As in the approved file: a long achievement continues over extra rows under the same task, and one
// achievement shared by several tasks of an axis is a single merged cell. A hidden column holds the item number.
export const REPORT_COLS = ['الوظيفة', 'المحور', 'المهام الوظيفية', 'المنجز', 'المؤشرات الوظيفية', 'إنجاز المؤشر', 'مستهدفات الشهر القادم', 'العقبات', 'الحلول', 'ملاحظات', 'رقم البند']
const WIDTHS = [18, 20, 40, 70, 34, 34, 30, 26, 26, 24, 9]
const FIELDS = [null, null, null, 'achieved', null, 'kpi_result', 'next_target', 'obstacles', 'solutions', 'notes'] // columns 4,6..10 are the employee's
const FILL_COLS = [4, 6, 7, 8, 9, 10]
const LINE_PT = 15
const MAX_ROW_PT = 400 // Excel caps a row at 409 points
const MAX_LINES = Math.floor((MAX_ROW_PT - 8) / LINE_PT)

const monthLabel = (ym) => {
  if (!ym) return ''
  const [y, m] = ym.split('-').map(Number)
  return new Intl.DateTimeFormat('ar-SA-u-ca-gregory-nu-latn', { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1))
}
const perLine = (w) => Math.max(8, Math.floor(w * 1.2))
const linesOf = (text, w) => String(text || '').split('\n').reduce((n, p) => n + Math.max(1, Math.ceil(p.length / perLine(w))), 0)
const same = (a, b) => String(a || '').replace(/\s+/g, ' ').trim() === String(b || '').replace(/\s+/g, ' ').trim()

/** Splits a long text into chunks that each fit in one row (by paragraph, then by words). */
function chunks(text, w) {
  const max = MAX_LINES
  const out = []
  let cur = []
  let n = 0
  const push = () => { if (cur.length) out.push(cur.join('\n')); cur = []; n = 0 }
  for (const para of String(text || '').split('\n')) {
    let p = para
    while (linesOf(p, w) > max) { // a single paragraph taller than a row: cut at a word boundary
      const cut = p.lastIndexOf(' ', max * perLine(w)) > 0 ? p.lastIndexOf(' ', max * perLine(w)) : max * perLine(w)
      push(); out.push(p.slice(0, cut)); p = p.slice(cut).trim()
    }
    const l = linesOf(p, w)
    if (n + l > max) push()
    cur.push(p); n += l
  }
  push()
  return out.length ? out : ['']
}

/** info: { name, company, job, manager, period }. items: report items. */
export async function exportReportForm(info, items, opts = {}) {
  const ExcelJS = await exceljs()
  const wb = new ExcelJS.Workbook()
  wb.creator = 'HC'
  wb.views = [{ rightToLeft: true }]
  await addReportSheet(wb, 'التقرير الشهري', info, items)
  await deliver(wb, `نموذج التقرير الشهري ${info.period || ''} - ${info.name || ''}`.trim(), { kind: 'نموذج التقرير الشهري', category: 'forms', ...opts })
}

/** Several employees' reports in one workbook: one sheet per employee, each in the approved layout. list: [{ info, items }] */
export async function exportReportForms(list, name, opts = {}) {
  const ExcelJS = await exceljs()
  const wb = new ExcelJS.Workbook()
  wb.creator = 'HC'
  wb.views = [{ rightToLeft: true }]
  const used = new Set()
  for (const { info, items } of list) {
    let t = sheetName(info.name || 'موظف').slice(0, 28)
    for (let n = 2; used.has(t); n++) t = `${sheetName(info.name || 'موظف').slice(0, 25)} (${n})`
    used.add(t)
    await addReportSheet(wb, t, info, items)
  }
  await deliver(wb, name, { kind: 'التقارير الشهرية', category: 'reports', ...opts })
}

async function addReportSheet(wb, title, info, items) {
  const ws = wb.addWorksheet(title, {
    views: [{ rightToLeft: true, state: 'frozen', ySplit: 6 }],
    pageSetup: { paperSize: 9, orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0, margins: { left: 0.3, right: 0.3, top: 0.4, bottom: 0.4, header: 0.2, footer: 0.2 } },
    headerFooter: { oddFooter: '&C&8صفحة &P من &N' },
  })
  WIDTHS.forEach((w, i) => { ws.getColumn(i + 1).width = w })
  ws.getColumn(11).hidden = true
  const last = 10
  const font = (o = {}) => ({ name: FONT, size: 11, ...o })
  const band = (r, value, style, height) => {
    ws.mergeCells(r, 1, r, last)
    Object.assign(ws.getCell(r, 1), { value, ...style })
    ws.getRow(r).height = height
  }

  // company, report title, date — then the employee's details and how to fill the form
  band(1, info.company || GROUP, { font: font({ size: 16, bold: true, color: { argb: BRAND.white } }), fill: fill(BRAND.navy), alignment: { horizontal: 'center', vertical: 'middle' } }, 32)
  band(2, `التقرير الشهري — ${monthLabel(info.period)}`, { font: font({ size: 14, bold: true, color: { argb: BRAND.navy } }), fill: fill('FFE9ECF7'), alignment: { horizontal: 'center', vertical: 'middle' } }, 26)
  band(3, `التاريخ: ${dmy()}     |     فترة التقرير: ${monthLabel(info.period)}${info.status ? `     |     الحالة: ${info.status}` : ''}`, { font: font({ size: 11, color: { argb: 'FF404040' } }), fill: fill('FFF2F2F2'), alignment: { horizontal: 'center', vertical: 'middle' } }, 20)
  const details = [['الموظف', info.name], ['الوظيفة', info.job], ['المدير المباشر', info.manager]].filter(([, v]) => v).map(([k, v]) => `${k}: ${v}`).join('     |     ')
  band(4, details, { font: font({ bold: true, color: { argb: BRAND.navy } }), alignment: { horizontal: 'center', vertical: 'middle', wrapText: true } }, 24)
  band(5, 'عبّئ الأعمدة البيضاء: المنجز، إنجاز المؤشر، مستهدفات الشهر القادم، العقبات، الحلول، الملاحظات. لا تعدّل الأعمدة الرمادية، ويمكنك كتابة منجز طويل على أكثر من صف تحت نفس المهمة. ثم ارفع الملف من «التقارير الدورية».', { font: font({ size: 10, italic: true, color: { argb: 'FF6B7094' } }), alignment: { horizontal: 'right', vertical: 'middle', wrapText: true, readingOrder: 'rtl' } }, 32)
  REPORT_COLS.forEach((h, i) => {
    Object.assign(ws.getCell(6, i + 1), { value: h, font: font({ size: 12, bold: true, color: { argb: BRAND.gold } }), fill: fill(BRAND.black), border: BORDER, alignment: { horizontal: 'center', vertical: 'middle', wrapText: true } })
  })
  ws.getRow(6).height = 28

  const styleCell = (r, c, value) => {
    const editable = FILL_COLS.includes(c)
    Object.assign(ws.getCell(r, c), {
      value: value === '' || value == null ? null : latin(value),
      font: font({ bold: c <= 2, color: { argb: c <= 2 ? BRAND.navy : 'FF1F1F1F' } }),
      border: BORDER,
      fill: fill(editable ? BRAND.white : BRAND.soft),
      alignment: { horizontal: c <= 2 ? 'center' : 'right', vertical: c === 4 ? 'top' : 'middle', wrapText: true, readingOrder: 'rtl' },
      protection: { locked: !editable },
    })
  }
  const merge = (r1, c1, r2, c2) => { if (r2 > r1 || c2 > c1) ws.mergeCells(r1, c1, r2, c2) }
  const fieldText = (it, c) => (c === 6 ? it.kpi_result || (it.pct ? `${it.pct}%` : '') : FIELDS[c - 1] ? it[FIELDS[c - 1]] || '' : '')

  // blocks: consecutive tasks of one axis sharing the same achievement form one merged block; otherwise one task per block
  const blocks = []
  for (let i = 0; i < items.length;) {
    let j = i
    while (j + 1 < items.length && (items[j + 1].axis || '') === (items[i].axis || '') && String(items[i].achieved || '').trim() && same(items[j + 1].achieved, items[i].achieved)) j++
    blocks.push(items.slice(i, j + 1))
    i = j + 1
  }

  let r = 7
  const axisAt = []
  for (const block of blocks) {
    if (block.length === 1) {
      const it = block[0]
      const parts = chunks(it.achieved, WIDTHS[3])
      const r1 = r
      parts.forEach((p, k) => {
        styleCell(r, 1, info.job || ''); styleCell(r, 2, it.axis || '')
        for (let c = 3; c <= 10; c++) styleCell(r, c, c === 4 ? p : k === 0 ? (c === 3 ? it.title : c === 5 ? it.indicator : fieldText(it, c)) : '')
        styleCell(r, 11, it.id || '')
        ws.getRow(r).height = Math.min(MAX_ROW_PT + 9, Math.max(30, linesOf(p, WIDTHS[3]) * LINE_PT + 8))
        axisAt[r] = it.axis || ''
        r++
      })
      const r2 = r - 1
      for (const c of [3, 5, 6, 7, 8, 9, 10]) merge(r1, c, r2, c)
      // other columns taller than the achievement rows: grow the last row
      const need = Math.max(...[[it.title, 2], [it.indicator, 4], [fieldText(it, 6), 5], [it.next_target, 6], [it.obstacles, 7], [it.solutions, 8], [it.notes, 9]].map(([t, c]) => linesOf(t, WIDTHS[c]) * LINE_PT + 8))
      let have = 0
      for (let x = r1; x <= r2; x++) have += ws.getRow(x).height
      if (need > have) ws.getRow(r2).height = Math.min(MAX_ROW_PT + 9, ws.getRow(r2).height + need - have)
    } else {
      // one shared achievement merged over the tasks; other fill columns merged too when they are identical
      const r1 = r
      block.forEach((it) => {
        styleCell(r, 1, info.job || ''); styleCell(r, 2, it.axis || '')
        for (let c = 3; c <= 10; c++) styleCell(r, c, c === 3 ? it.title : c === 5 ? it.indicator : fieldText(it, c))
        styleCell(r, 11, it.id || '')
        ws.getRow(r).height = Math.max(30, linesOf(it.title, WIDTHS[2]) * LINE_PT + 8, linesOf(it.indicator, WIDTHS[4]) * LINE_PT + 8)
        axisAt[r] = it.axis || ''
        r++
      })
      const r2 = r - 1
      merge(r1, 4, r2, 4)
      for (const c of [6, 7, 8, 9, 10]) if (block.every((it) => same(fieldText(it, c), fieldText(block[0], c)))) merge(r1, c, r2, c)
      const need = Math.max(...[4, 6, 7, 8, 9, 10].map((c) => linesOf(fieldText(block[0], c), WIDTHS[c - 1]) * LINE_PT + 8))
      let have = 0
      for (let x = r1; x <= r2; x++) have += ws.getRow(x).height
      if (need > have) {
        const add = (need - have) / block.length
        for (let x = r1; x <= r2; x++) ws.getRow(x).height = Math.min(MAX_ROW_PT + 9, ws.getRow(x).height + add)
      }
    }
  }
  const end = r - 1
  if (end >= 7) {
    merge(7, 1, end, 1)
    let s = 7
    for (let x = 8; x <= end + 1; x++) {
      if (x > end || axisAt[x] !== axisAt[s]) { if (axisAt[s]) merge(s, 2, x - 1, 2); s = x }
    }
  }
  const t = end + 2
  band(t, `عدد المهام: ${items.length}     |     يُعتمد التقرير إلكترونيًا من المدير المباشر ثم الموارد البشرية بعد رفعه على النظام`, { font: font({ size: 10, color: { argb: 'FF6B7094' } }), alignment: { horizontal: 'center' } }, 20)

  ws.pageSetup.printTitlesRow = '6:6'
  // grey columns are locked so the obligations stay intact; the sheet can be unprotected from Excel if needed (no password)
  await ws.protect('', { selectLockedCells: true, selectUnlockedCells: true, formatCells: true, formatColumns: true, formatRows: true, insertRows: true, deleteRows: false })
}

const pctOf = (v) => {
  const s = latin(v ?? '').replace('%', '').trim()
  if (s === '' || isNaN(Number(s))) return null
  const n = Number(s)
  return Math.max(0, Math.min(100, n > 0 && n <= 1 && !String(v).includes('%') ? Math.round(n * 100) : n))
}

/** Copies an uploaded form onto the report items. Rows are matched to items by item number, else by the task
 *  text; several rows of one task (a long achievement continued below) are joined in order. Returns how many
 *  items were filled. */
export function applyReportRows(items, rows) {
  const norm = (s) => String(s ?? '').replace(/\s+/g, ' ').replace(/[.。]\s*$/, '').trim()
  const byItem = new Map()
  for (const r of rows) {
    const id = norm(r['رقم البند'])
    const task = norm(r['المهام الوظيفية'] ?? r['البند'])
    const it = items.find((x) => id && String(x.id) === id) || items.find((x) => task && norm(x.title) === task)
    if (!it) continue
    if (!byItem.has(it)) byItem.set(it, [])
    byItem.get(it).push(r)
  }
  const join = (rs, ...cols) => {
    const vals = []
    for (const r of rs) for (const c of cols) { const v = String(r[c] ?? '').trim(); if (v && !vals.includes(v)) vals.push(v) }
    return vals.join('\n')
  }
  for (const [it, rs] of byItem) {
    const set = (key, ...cols) => { const v = join(rs, ...cols); if (v) it[key] = v }
    set('achieved', 'المنجز', 'الإنجازات')
    set('kpi_result', 'إنجاز المؤشر')
    set('next_target', 'مستهدفات الشهر القادم', 'مستهدف الشهر القادم')
    set('obstacles', 'العقبات'); set('solutions', 'الحلول'); set('notes', 'ملاحظات', 'الملاحظات')
    const p = rs.map((r) => pctOf(r['نسبة الإنجاز %']) ?? pctOf(r['إنجاز المؤشر'])).find((x) => x !== null)
    if (p != null) it.pct = p
  }
  return byItem.size
}
