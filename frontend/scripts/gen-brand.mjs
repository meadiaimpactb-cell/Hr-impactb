// Regenerates the brand PNGs (frontend/public/*.png) from their base64 sources in frontend/brand/.
// The PNGs are stored as text so the repository carries the exact brand pixels without binary blobs.
// Runs automatically via the npm `prebuild` hook; safe to run anytime: node scripts/gen-brand.mjs
import { readFileSync, writeFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const brand = join(root, 'brand')
const pub = join(root, 'public')

import { existsSync } from 'node:fs'
if (!existsSync(brand)) process.exit(0) // nothing to regenerate: the PNGs in public/ are committed as-is
for (const f of readdirSync(brand)) {
  if (!f.endsWith('.b64')) continue
  const out = f.replace(/\.b64$/, '')
  writeFileSync(join(pub, out), Buffer.from(readFileSync(join(brand, f), 'utf8').trim(), 'base64'))
  console.log('brand asset →', 'public/' + out)
}
