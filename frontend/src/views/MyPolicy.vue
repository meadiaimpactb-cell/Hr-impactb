<template>
  <div class="page-h">
    <div><h1>لائحتي وإقراري</h1><p>سياسات ولوائح رأس المال البشري التي يعمل بها الجميع، وإقرارك بالاطلاع عليها — متاحة لك للمراجعة في أي وقت.</p></div>
    <div class="acts"><button v-if="st?.document" class="btn pri sm" :class="{ loading: opening }" @click="openDoc()"><Icon name="external" :size="16" /> قراءة اللائحة كاملة (الإصدار {{ st.version }})</button></div>
  </div>
  <div v-if="!st"><div v-for="i in 3" :key="i" class="skel" /></div>
  <template v-else>
    <div class="grid g3 mb">
      <Kpi label="الإصدار الحالي من اللائحة" :value="st.version" color="b" />
      <Kpi label="حالة إقرارك" :value="st.mine ? 'أقررت' : 'لم تقرّ بعد'" :hint="st.mine ? fmtDT(st.mine.signed_at) : 'مطلوب إقرارك بهذا الإصدار'" :color="st.mine ? 'g' : 'r'" />
      <Kpi label="ملف اللائحة" :value="st.document ? 'متاح' : 'لم يُرفع بعد'" :hint="st.document ? 'اضغط «قراءة اللائحة كاملة»' : 'ترفعه الموارد البشرية'" :color="st.document ? 'n' : 'y'" />
    </div>

    <div class="card">
      <h3>نص الإقرار — الإصدار {{ st.version }} <span class="sub">الملحق الرابع</span></h3>
      <div class="notice info" style="line-height:1.9;font-size:15px">{{ st.text }}</div>
      <template v-if="st.mine">
        <div class="tw mt"><table class="kv"><tbody>
          <tr><th>الاسم</th><td>{{ st.employee?.name }}</td></tr>
          <tr><th>الرقم الوظيفي</th><td class="num">{{ st.employee?.emp_no }}</td></tr>
          <tr><th>الشركة</th><td>{{ st.employee?.company || '—' }}</td></tr>
          <tr><th>الاسم المكتوب توقيعًا</th><td>{{ st.mine.typed_name }}</td></tr>
          <tr><th>تاريخ الإقرار</th><td class="num">{{ fmtDT(st.mine.signed_at) }}</td></tr>
          <tr v-if="st.mine.signature"><th>التوقيع</th><td><img :src="st.mine.signature" alt="التوقيع" style="max-height:80px;background:#fff;border-radius:8px;padding:4px" /></td></tr>
        </tbody></table></div>
        <p class="tiny muted mt">حُفظ الإقرار في ملفك الوظيفي مع التاريخ وعنوان الشبكة والجهاز. لا يُعد تنازلًا عن أي حق نظامي أو تعاقدي.</p>
      </template>
      <div v-else class="notice warn mt">لم تقرّ بعد بهذا الإصدار — سيُطلب منك الإقرار عند فتح المنصة. يمكنك قراءة اللائحة كاملة أولًا من الزر أعلى الصفحة.</div>
    </div>

    <div class="card">
      <h3>إقراراتي السابقة <span class="cnt">{{ st.history?.length || 0 }}</span></h3>
      <div v-if="!st.history?.length" class="empty small">لا إقرارات مسجلة</div>
      <div v-else class="tw"><table>
        <thead><tr><th>الإصدار</th><th>تاريخ الإقرار</th><th>الاسم المكتوب</th><th /></tr></thead>
        <tbody><tr v-for="h in st.history" :key="h.version + h.signed_at">
          <td><b>{{ h.version }}</b><span v-if="h.version === st.version" class="pill ok" style="margin-inline-start:6px">الحالي</span></td>
          <td class="num">{{ fmtDT(h.signed_at) }}</td><td>{{ h.typed_name }}</td>
          <td><button v-if="h.has_document" class="btn ghost xs" @click="openDoc(h.version)">قراءة هذا الإصدار</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>
</template>

<script setup>
// Owner's note (22/10): an employee can always read the regulations and see what he acknowledged (or that he has not yet).
import { onMounted, ref } from 'vue'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import { useUi } from '../stores/ui'
import { fmtDT } from '../utils'

const ui = useUi()
const st = ref(null); const opening = ref(false)
async function openDoc(version) {
  opening.value = true
  try { const r = await api.get('/policy-ack/document-link', { params: { version } }); window.open(r.url, '_blank', 'noopener') } catch (e) { ui.toast(e.message, 'bad') } finally { opening.value = false }
}
onMounted(async () => { st.value = await api.get('/policy-ack') })
</script>
