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
        <div class="title"><b>قسيمة راتب</b><div class="small muted">{{ fmtYM(d.payslip.period) }}</div></div>
        <div class="co">{{ d.employee.company?.name }}<small>{{ d.employee.company?.name_en }}</small></div>
      </div>

      <p><b>{{ d.employee.name }}</b> · {{ d.employee.emp_no }} · {{ d.employee.job_title?.name }}<br />
        <span class="small muted">أيام الخدمة في الفترة: {{ d.payslip.line.service_days }} يوم</span></p>

      <table>
        <thead><tr><th>المستحقات</th><th>المبلغ</th></tr></thead>
        <tbody>
          <tr v-for="e in d.payslip.line.earnings" :key="e.key"><td>{{ e.name }}</td><td class="num">{{ money(e.amount) }}</td></tr>
          <tr><td><b>إجمالي المستحقات</b></td><td class="num"><b>{{ money(d.payslip.line.gross) }}</b></td></tr>
        </tbody>
      </table>
      <table style="margin-top:10px">
        <thead><tr><th>الاستقطاعات</th><th>المبلغ</th></tr></thead>
        <tbody>
          <tr v-for="ded in d.payslip.line.deductions" :key="ded.key">
            <td>{{ ded.name }}</td>
            <td class="num">{{ money(ded.key === 'late' && d.payslip.adj_late !== null ? d.payslip.adj_late : ded.amount) }}</td>
          </tr>
          <tr v-if="!d.payslip.line.deductions.length"><td colspan="2" class="muted">لا استقطاعات</td></tr>
          <tr><td><b>إجمالي الاستقطاعات</b></td><td class="num"><b>{{ money(d.ded_total) }}</b></td></tr>
        </tbody>
      </table>

      <div class="net-box"><div class="small muted">صافي المستحق</div><div class="amt num">{{ money(d.net) }} <span class="small">ر.س</span></div></div>

      <div class="df">
        <span>رقم المستند: {{ d.payslip.no }}</span>
        <span>تاريخ الإصدار: {{ today() }}</span>
        <span>مستند صادر آليًا من منصة HC — معتمد من المدير المباشر والموارد البشرية</span>
      </div>
    </div>
  </div>
</template>

<script setup>
const print = () => window.print()
import { onMounted, ref } from 'vue'
import api from '../api'
import { fmtYM, money, today } from '../utils'

const props = defineProps({ id: [String, Number] })
const d = ref(null)
onMounted(async () => { d.value = await api.get(`/payroll/payslips/${props.id}`) })
</script>
