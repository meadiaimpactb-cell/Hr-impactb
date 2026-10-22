<template>
  <div style="padding:20px">
    <div class="noprint row mb" style="max-width:780px;margin-inline:auto;justify-content:space-between">
      <button class="btn sec sm" @click="$router.back()"><Icon name="arrow-right" :size="15" flip-rtl /> رجوع</button>
      <div class="row"><button v-if="d?.can_sign" class="btn sec sm" @click="sign"><Icon name="signature" :size="15" /> توقيع إلكتروني</button><button v-if="d" class="btn sec sm" @click="word"><Icon name="download" :size="15" /> تنزيل Word</button><button v-if="d" class="btn pri sm" @click="print"><Icon name="external" :size="15" /> طباعة / حفظ PDF</button></div>
    </div>
    <div v-if="!d" class="center muted" style="padding:60px">جارٍ التحميل…</div>
    <div v-else class="doc-print" :dir="d.lang === 'en' ? 'ltr' : 'rtl'">
      <div class="dh" :style="d.company.color ? { borderBottomColor: d.company.color } : {}">
        <div class="logos"><img v-if="d.company.logo_url" :src="d.company.logo_url" :alt="d.company.name" /><div v-else class="mark" /></div>
        <div class="title"><b>{{ d.lang === 'en' ? 'Official letter' : 'خطاب رسمي' }}</b><div class="small muted">{{ d.no }}</div></div>
        <div class="co" :style="d.company.color ? { color: d.company.color } : {}">{{ d.company.name }}<small>{{ d.company.name_en }}</small><small v-if="d.company.legal_no">CR {{ d.company.legal_no }}</small><small>{{ d.lang === 'en' ? 'Impact Valley Capital Group' : 'مجموعة وادي الأثر المالية' }}</small></div>
        <div class="logos"><img class="grp" src="/logo-mark.png" alt="HC" /></div>
      </div>
      <div class="row" style="justify-content:space-between"><span class="small">{{ d.lang === 'en' ? 'Date' : 'التاريخ' }}: {{ d.issued_at }} <span v-if="d.lang !== 'en'">· {{ d.hijri }}</span></span><span class="small">{{ d.lang === 'en' ? 'Ref' : 'الرقم' }}: {{ d.no }}</span></div>
      <h2 style="margin:26px 0 14px">{{ d.title }}</h2>
      <p style="white-space:pre-wrap;line-height:2;font-size:15px">{{ d.body }}</p>
      <div style="margin-top:40px;display:flex;justify-content:space-between;align-items:flex-end">
        <div class="small">{{ d.lang === 'en' ? 'Human Resources' : 'الموارد البشرية' }}<div class="muted">{{ d.signer || d.issued_by || '' }}</div></div>
        <div style="text-align:center"><img v-if="d.signature" :src="d.signature" alt="signature" style="height:70px" /><div v-else style="height:70px" /><div class="tiny muted">{{ d.signed ? (d.lang === 'en' ? 'Electronically signed' : 'موقَّع إلكترونيًا') : (d.lang === 'en' ? 'Signature' : 'التوقيع') }}</div></div>
      </div>
      <div class="df"><span>{{ d.no }}</span><span class="tiny">SHA-256 {{ (d.hash || '').slice(0, 16) }}…</span><span>{{ d.company.name }}</span></div>
    </div>
  </div>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { onMounted, ref } from 'vue'
import api from '../api'
import { useUi } from '../stores/ui'

const props = defineProps({ id: [String, Number] })
const ui = useUi()
const d = ref(null)
const print = () => window.print()
// Word copy (§114): a single-file Word document (MHTML) — the company logo and the group logo travel inside the
// file, so they show without internet; the rest is the letter as Word reads it natively
const toB64 = async (url) => {
  try {
    const blob = await (await fetch(url, { credentials: 'same-origin' })).blob()
    if (!blob.type.startsWith('image/')) return null
    const data = await new Promise((ok) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1]); r.readAsDataURL(blob) })
    return { type: blob.type, data }
  } catch { return null }
}
async function word() {
  const x = d.value; const rtl = x.lang !== 'en'
  const groupSrc = document.querySelector('.doc-print img.grp')?.src || '/static/logo-mark.png'
  const [coImg, grpImg, sigImg] = await Promise.all([x.company.logo_url ? toB64(x.company.logo_url) : null, toB64(groupSrc), x.signature && !String(x.signature).startsWith('data:') ? toB64(x.signature) : null])
  const parts = []
  const ref = (img, name) => { if (!img) return ''; parts.push({ name, ...img }); return `file:///C:/hc/${name}` }
  const co = ref(coImg, 'company-logo'); const grp = ref(grpImg, 'group-logo')
  const sig = x.signature ? (String(x.signature).startsWith('data:') ? (() => { const [h, b] = String(x.signature).split(','); return ref({ type: h.slice(5).split(';')[0], data: b }, 'signature') })() : ref(sigImg, 'signature')) : ''
  const html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" lang="${rtl ? 'ar' : 'en'}" dir="${rtl ? 'rtl' : 'ltr'}"><head><meta charset="utf-8"><title>${x.title}</title><style>body{font-family:Arial;direction:${rtl ? 'rtl' : 'ltr'};font-size:13pt}.h{border-bottom:3px solid ${x.company.color || '#1A2363'};padding-bottom:8pt;margin-bottom:14pt}.co{color:${x.company.color || '#1A2363'};font-weight:bold;font-size:14pt}.m{color:#67718F;font-size:10pt}p{line-height:1.9}</style></head><body>
<table class="h" width="100%"><tr><td width="18%">${co ? `<img src="${co}" height="56">` : ''}</td><td align="center"><b>${rtl ? 'خطاب رسمي' : 'Official letter'}</b><br><span class="m">${x.no}</span></td><td align="${rtl ? 'left' : 'right'}"><span class="co">${x.company.name}</span><br><span class="m">${x.company.name_en || ''}</span>${x.company.legal_no ? `<br><span class="m">CR ${x.company.legal_no}</span>` : ''}<br><span class="m">${rtl ? 'مجموعة وادي الأثر المالية' : 'Impact Valley Capital Group'}</span></td><td width="14%" align="${rtl ? 'left' : 'right'}">${grp ? `<img src="${grp}" height="44">` : ''}</td></tr></table>
<p class="m">${rtl ? 'التاريخ' : 'Date'}: ${x.issued_at}${rtl ? ' · ' + x.hijri : ''} &nbsp;&nbsp; ${rtl ? 'الرقم' : 'Ref'}: ${x.no}</p><h2>${x.title}</h2><p>${String(x.body).replace(/\n/g, '<br>')}</p>
<br><br><table width="100%"><tr><td>${rtl ? 'الموارد البشرية' : 'Human Resources'}<br><span class="m">${x.signer || x.issued_by || ''}</span></td><td align="center">${sig ? `<img src="${sig}" height="70">` : '<br><br>'}<br><span class="m">${x.signed ? (rtl ? 'موقَّع إلكترونيًا' : 'Electronically signed') : (rtl ? 'التوقيع' : 'Signature')}</span></td></tr></table>
<p class="m">${x.no} · SHA-256 ${(x.hash || '').slice(0, 16)}…</p></body></html>`
  const B = '----=_HC_LETTER_PART'
  const b64 = (str) => { const bytes = new TextEncoder().encode(str); let bin = ''; for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000)); return btoa(bin) }
  const wrap = (t) => t.replace(/.{1,76}/g, '$&\r\n')
  let mht = `MIME-Version: 1.0\r\nContent-Type: multipart/related; boundary="${B}"; type="text/html"\r\n\r\n--${B}\r\nContent-Type: text/html; charset="utf-8"\r\nContent-Transfer-Encoding: base64\r\nContent-Location: file:///C:/hc/letter.htm\r\n\r\n${wrap(b64(html))}\r\n`
  for (const p of parts) mht += `--${B}\r\nContent-Type: ${p.type}\r\nContent-Transfer-Encoding: base64\r\nContent-Location: file:///C:/hc/${p.name}\r\n\r\n${wrap(p.data)}\r\n`
  mht += `--${B}--\r\n`
  const blob = new Blob([mht], { type: 'application/msword' })
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `${x.no}.doc`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 5000)
}
const load = async () => { d.value = await api.get(`/letters/${props.id}`) }
async function sign() { if (!await confirmBox('توقيع الخطاب إلكترونيًا؟')) return; try { d.value = await api.post(`/letters/${props.id}/sign`); ui.toast('وُقّع الخطاب', 'ok') } catch (e) { ui.toast(e.message, 'bad') } }
onMounted(load)
</script>
