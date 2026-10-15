<template>
  <div style="padding:20px">
    <div class="noprint row mb" style="max-width:780px;margin-inline:auto;justify-content:space-between">
      <button class="btn sec sm" @click="$router.back()"><Icon name="arrow-right" :size="15" flip-rtl /> رجوع</button>
      <button v-if="d" class="btn pri sm" @click="print"><Icon name="external" :size="15" /> طباعة / حفظ PDF</button>
    </div>
    <div v-if="!d" class="center muted" style="padding:60px">جارٍ التحميل…</div>
    <div v-else class="doc-print">
      <div class="dh">
        <div class="mark" />
        <div class="title"><b>تصفية نهاية الخدمة</b><div class="small muted">STL-{{ d.id }} · {{ d.status_label }}</div></div>
        <div class="co">{{ d.company }}</div>
      </div>
      <p><b>{{ d.employee?.name }}</b> · {{ d.employee?.emp_no }}<br /><span class="small muted">{{ d.reason_name }} · آخر يوم عمل {{ d.last_working_day }} · مدة الإشعار {{ d.notice_days }} يوم</span></p>
      <table>
        <thead><tr><th>مكافأة نهاية الخدمة</th><th>القيمة</th></tr></thead>
        <tbody>
          <tr><td>مدة الخدمة</td><td>{{ d.eosb.pretty }}</td></tr>
          <tr><td>الأجر المعتبر</td><td class="num">{{ money(d.eosb.wage) }}</td></tr>
          <tr><td>نصف أجر لأول {{ d.eosb.half_years }} سنوات</td><td class="num">{{ money(d.eosb.t1) }}</td></tr>
          <tr><td>أجر كامل لما بعدها</td><td class="num">{{ money(d.eosb.t2) }}</td></tr>
          <tr><td>الإجمالي × المعامل {{ d.eosb.factor }} ({{ d.eosb.why }})</td><td class="num"><b>{{ money(d.eosb.amount) }}</b></td></tr>
          <tr v-if="d.eosb_adjusted !== null"><td>تعديل الموارد البشرية — {{ d.eosb_adjust_reason }}</td><td class="num"><b>{{ money(d.eosb_adjusted) }}</b></td></tr>
        </tbody>
      </table>
      <table style="margin-top:10px">
        <thead><tr><th>بنود التصفية</th><th>المبلغ</th></tr></thead>
        <tbody>
          <tr v-for="(l, i) in d.settlement.lines" :key="i"><td>{{ l.name }}</td><td class="num">{{ money(l.amount) }}</td></tr>
        </tbody>
      </table>
      <div class="net-box"><div class="small muted">صافي التصفية</div><div class="amt num">{{ money(d.settlement.total) }} <span class="small">ر.س</span></div></div>
      <div class="row mt" style="justify-content:space-between;flex-wrap:wrap">
        <div v-for="a in d.approvals" :key="a.role" class="small" style="text-align:center;min-width:120px"><b>{{ a.label }}</b><br />{{ a.by }}<br /><span class="tiny muted">{{ fmtDT(a.at) }}</span></div>
      </div>
      <div class="df"><span>STL-{{ d.id }}</span><span>{{ d.company }}</span></div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../api'
import { fmtDT, money } from '../utils'

const props = defineProps({ id: [String, Number] })
const d = ref(null)
const print = () => window.print()
onMounted(async () => { d.value = await api.get(`/terminations/${props.id}`) })
</script>
