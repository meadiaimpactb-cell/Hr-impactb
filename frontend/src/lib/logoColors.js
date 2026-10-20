// Suggests a company's identity colours from its logo: the logo is drawn on a small canvas, near-white / near-black /
// transparent pixels are ignored, and the remaining colours are bucketed. The two most frequent, sufficiently different
// buckets become the primary (darkest of the two) and secondary colour.
const hex = (r, g, b) => '#' + [r, g, b].map((x) => Math.round(x).toString(16).padStart(2, '0')).join('').toUpperCase()
const lum = ([r, g, b]) => 0.2126 * r + 0.7152 * g + 0.0722 * b
const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2])

export async function logoColors(src) {
  const img = await new Promise((ok, bad) => { const i = new Image(); i.crossOrigin = 'anonymous'; i.onload = () => ok(i); i.onerror = bad; i.src = src })
  const W = 96; const H = Math.max(1, Math.round((img.height / img.width) * W))
  const cv = document.createElement('canvas'); cv.width = W; cv.height = H
  const ctx = cv.getContext('2d', { willReadFrequently: true }); ctx.drawImage(img, 0, 0, W, H)
  const px = ctx.getImageData(0, 0, W, H).data
  const buckets = new Map()
  for (let i = 0; i < px.length; i += 4) {
    const [r, g, b, a] = [px[i], px[i + 1], px[i + 2], px[i + 3]]
    if (a < 160) continue
    const max = Math.max(r, g, b); const min = Math.min(r, g, b)
    if (min > 235 || max < 22) continue // background white / pure black outlines
    const key = `${r >> 4},${g >> 4},${b >> 4}`
    const v = buckets.get(key) || { n: 0, r: 0, g: 0, b: 0 }
    v.n++; v.r += r; v.g += g; v.b += b; buckets.set(key, v)
  }
  const list = [...buckets.values()].sort((x, y) => y.n - x.n).map((v) => [v.r / v.n, v.g / v.n, v.b / v.n])
  if (!list.length) return null
  const first = list[0]
  const second = list.find((c) => dist(c, first) > 70) || list[1] || first
  const [primary, secondary] = lum(first) <= lum(second) ? [first, second] : [second, first]

  return { color: hex(...primary), color2: hex(...secondary) }
}
