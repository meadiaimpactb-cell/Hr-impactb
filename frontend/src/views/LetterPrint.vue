<template>
  <div style="padding:20px">
    <div class="noprint row mb" style="max-width:780px;margin-inline:auto;justify-content:space-between">
      <button class="btn sec sm" @click="$router.back()"><Icon name="arrow-right" :size="15" flip-rtl /> رجوع</button>
      <div class="row"><button v-if="d?.can_sign" class="btn sec sm" @click="sign"><Icon name="signature" :size="15" /> توقيع إلكتروني</button><button v-if="d" class="btn pri sm" @click="print"><Icon name="external" :size="15" /> طباعة / حفظ PDF</button></div>
    </div>
    <div v-if="!d" class="center muted" style="padding:60px">جارٍ التحميل…</div>
    <div v-else class="doc-print" :dir="d.lang === 'en' ? 'ltr' : 'rtl'">
      <div class="dh">
        <div class="mark" />
        <div class="title"><b>{{ d.lang === 'en' ? 'Official letter' : 'خطاب رسمي' }}</b><div class="small muted">{{ d.no }}</div></div>
        <div class="co">{{ d.company.name }}<small>{{ d.company.name_en }}</small><small v-if="d.company.legal_no">CR {{ d.company.legal_no }}</small></div>
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
const load = async () => { d.value = await api.get(`/letters/${props.id}`) }
async function sign() { if (!await confirmBox('توقيع الخطاب إلكترونيًا؟')) return; try { d.value = await api.post(`/letters/${props.id}/sign`); ui.toast('وُقّع الخطاب', 'ok') } catch (e) { ui.toast(e.message, 'bad') } }
onMounted(load)
</script>
