// Minimal QR code encoder (byte mode, error correction level M, versions 1-10) → SVG path.
// Dependency-free so the landing page stays a single small bundle.

const EC_CODEWORDS_M = [0, 10, 16, 26, 36, 48, 64, 72, 88, 110, 130]
const EC_BLOCKS_M = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5]
const TOTAL_CODEWORDS = [0, 26, 44, 70, 100, 134, 172, 196, 242, 292, 346]
const ALIGN = [[], [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]]

// GF(256) tables
const EXP = new Uint8Array(512), LOG = new Uint8Array(256)
for (let i = 0, x = 1; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 0x100) x ^= 0x11d }
for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255]
const mul = (a, b) => (a && b ? EXP[LOG[a] + LOG[b]] : 0)

function rsGenerator(n) {
  let g = [1]
  for (let i = 0; i < n; i++) {
    const ng = new Array(g.length + 1).fill(0)
    for (let j = 0; j < g.length; j++) { ng[j] ^= g[j]; ng[j + 1] ^= mul(g[j], EXP[i]) }
    g = ng
  }
  return g
}
function rsEncode(data, n) {
  const g = rsGenerator(n)
  const res = new Array(n).fill(0)
  for (const d of data) {
    const f = d ^ res[0]
    res.shift(); res.push(0)
    if (f) for (let j = 0; j < n; j++) res[j] ^= mul(g[j + 1], f)
  }
  return res
}

export function qrPath(text) {
  const bytes = new TextEncoder().encode(text)
  let ver = 1
  while (ver <= 10 && TOTAL_CODEWORDS[ver] - EC_CODEWORDS_M[ver] < bytes.length + 3) ver++
  if (ver > 10) return { size: 0, path: '' }
  const size = 17 + ver * 4
  const dataCw = TOTAL_CODEWORDS[ver] - EC_CODEWORDS_M[ver]

  // --- bitstream
  const bits = []
  const push = (v, n) => { for (let i = n - 1; i >= 0; i--) bits.push((v >> i) & 1) }
  push(0b0100, 4); push(bytes.length, ver < 10 ? 8 : 16)
  for (const b of bytes) push(b, 8)
  push(0, Math.min(4, dataCw * 8 - bits.length))
  while (bits.length % 8) bits.push(0)
  const data = []
  for (let i = 0; i < bits.length; i += 8) data.push(parseInt(bits.slice(i, i + 8).join(''), 2))
  for (let p = 0xec; data.length < dataCw; p ^= 0xec ^ 0x11) data.push(p)

  // --- blocks + interleave
  const nb = EC_BLOCKS_M[ver], ecPer = EC_CODEWORDS_M[ver] / nb
  const shortLen = Math.floor(dataCw / nb), longCount = dataCw % nb
  const blocks = [], ecs = []
  for (let b = 0, off = 0; b < nb; b++) {
    const len = shortLen + (b >= nb - longCount ? 1 : 0)
    const blk = data.slice(off, off + len); off += len
    blocks.push(blk); ecs.push(rsEncode(blk, ecPer))
  }
  const out = []
  for (let i = 0; i <= shortLen; i++) for (const b of blocks) if (i < b.length) out.push(b[i])
  for (let i = 0; i < ecPer; i++) for (const e of ecs) out.push(e[i])

  // --- matrix
  const m = Array.from({ length: size }, () => new Array(size).fill(null))
  const set = (r, c, v) => { m[r][c] = v }
  const finder = (r, c) => { for (let i = -1; i <= 7; i++) for (let j = -1; j <= 7; j++) { const rr = r + i, cc = c + j; if (rr < 0 || cc < 0 || rr >= size || cc >= size) continue; const on = i >= 0 && i <= 6 && j >= 0 && j <= 6 && (i === 0 || i === 6 || j === 0 || j === 6 || (i >= 2 && i <= 4 && j >= 2 && j <= 4)); set(rr, cc, on ? 1 : 0) } }
  finder(0, 0); finder(0, size - 7); finder(size - 7, 0)
  for (const r of ALIGN[ver]) for (const c of ALIGN[ver]) {
    if (m[r][c] !== null) continue
    for (let i = -2; i <= 2; i++) for (let j = -2; j <= 2; j++) set(r + i, c + j, Math.max(Math.abs(i), Math.abs(j)) !== 1 ? 1 : 0)
  }
  for (let i = 8; i < size - 8; i++) { set(6, i, i % 2 === 0 ? 1 : 0); set(i, 6, i % 2 === 0 ? 1 : 0) }
  set(size - 8, 8, 1)
  // reserve format areas
  for (let i = 0; i < 8; i++) { if (m[8][i] === null) set(8, i, 0); if (m[i][8] === null) set(i, 8, 0); if (m[8][size - 1 - i] === null) set(8, size - 1 - i, 0); if (m[size - 1 - i][8] === null) set(size - 1 - i, 8, 0) }
  if (m[8][8] === null) set(8, 8, 0)
  if (ver >= 7) for (let i = 0; i < 6; i++) for (let j = 0; j < 3; j++) { set(i, size - 11 + j, 0); set(size - 11 + j, i, 0) }

  // --- place data
  const reserved = m.map((row) => row.map((v) => v !== null))
  let bi = 0
  const allBits = out.flatMap((b) => [7, 6, 5, 4, 3, 2, 1, 0].map((i) => (b >> i) & 1))
  for (let c = size - 1; c > 0; c -= 2) {
    if (c === 6) c--
    for (let k = 0; k < size; k++) {
      const r = ((c + 1) / 2) % 2 === 0 ? k : size - 1 - k
      for (const cc of [c, c - 1]) if (!reserved[r][cc]) { m[r][cc] = allBits[bi++] ?? 0 }
    }
  }
  // mask 0 (checkerboard) — always valid, no penalty evaluation needed for a static page
  for (let r = 0; r < size; r++) for (let c = 0; c < size; c++) if (!reserved[r][c] && (r + c) % 2 === 0) m[r][c] ^= 1

  // --- format info (level M = 00, mask 0): precomputed 15-bit string
  const fmt = 0b101010000010010
  for (let i = 0; i < 15; i++) {
    const bit = (fmt >> (14 - i)) & 1
    const a = i < 6 ? [8, i] : i < 8 ? [8, i + 1] : i === 8 ? [7, 8] : [14 - i, 8]
    m[a[0]][a[1]] = bit
    const b = i < 8 ? [size - 1 - i, 8] : [8, size - 15 + i]
    m[b[0]][b[1]] = bit
  }
  if (ver >= 7) {
    let v = ver << 12, g = 0x1f25
    for (let i = 17; i >= 12; i--) if ((v >> i) & 1) v ^= g << (i - 12)
    const info = (ver << 12) | v
    for (let i = 0; i < 18; i++) { const bit = (info >> i) & 1; m[Math.floor(i / 3)][size - 11 + (i % 3)] = bit; m[size - 11 + (i % 3)][Math.floor(i / 3)] = bit }
  }

  let path = ''
  for (let r = 0; r < size; r++) for (let c = 0; c < size; c++) if (m[r][c]) path += `M${c} ${r}h1v1h-1z`
  return { size, path }
}
