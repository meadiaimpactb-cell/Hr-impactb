<template>
  <div class="page-h">
    <div><h1>إصدارات تطبيق الجوال</h1><p>انشر ملف APK الجديد هنا فيصل التحديث إلى الموظفين من زر «تحديث التطبيق» داخل التطبيق ومن صفحة التنزيل العامة · نسخة iOS تُبنى من ملف codemagic.yaml على خدمة Codemagic (تحتاج حساب مطوّر Apple)</p></div>
  </div>
  <div class="grid g2">
    <div class="card">
      <h3>الإصدار المنشور حاليًا</h3>
      <div v-if="!cur" class="skel" />
      <table v-else class="kv">
        <tr><th>الإصدار</th><td class="num">{{ cur.version }} (build {{ cur.build }})</td></tr>
        <tr><th>تاريخ النشر</th><td>{{ cur.published_at ? fmtDT(cur.published_at) : '—' }}</td></tr>
        <tr><th>الملف</th><td><a :href="cur.url" dir="ltr">{{ cur.url }}</a> · {{ cur.available ? (cur.size / 1048576).toFixed(1) + ' MB' : 'غير موجود' }}</td></tr>
        <tr><th>ملاحظات الإصدار</th><td style="white-space:pre-line">{{ cur.notes || '—' }}</td></tr>
      </table>
    </div>
    <div v-if="auth.has('super')" class="card">
      <h3>نشر إصدار جديد</h3>
      <div class="fr">
        <div class="fg"><label>رقم الإصدار *</label><input v-model.trim="f.version" class="in" dir="ltr" placeholder="1.1.0" /></div>
        <div class="fg"><label>رقم البناء * <span class="tiny muted">(يزيد مع كل نشر)</span></label><input v-model.number="f.build" type="number" min="1" class="in" dir="ltr" /></div>
      </div>
      <div class="fg"><label>ملاحظات الإصدار</label><textarea v-model="f.notes" class="in" rows="3" placeholder="ما الجديد في هذا الإصدار؟" /></div>
      <div class="fg"><label>ملف APK <span class="tiny muted">(اتركه فارغًا لتحديث الرقم والملاحظات فقط)</span></label><input type="file" accept=".apk" class="in" @change="f.apk = $event.target.files[0]" /></div>
      <button class="btn pri" :class="{ loading: busy }" :disabled="busy" @click="publish"><Icon name="upload" :size="15" /> نشر</button>
      <p class="tiny muted mt">بناء الـ APK محليًا: <code dir="ltr">flutter build apk --release</code> في مجلد mobile، ثم ارفع <code dir="ltr">app-release.apk</code> هنا.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../api'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { fmtDT } from '../utils'

const auth = useAuth(); const ui = useUi()
const cur = ref(null); const busy = ref(false)
const f = ref({ version: '', build: 1, notes: '', apk: null })
async function load() { cur.value = await api.get('/app/version'); f.value = { version: cur.value.version, build: cur.value.build + 1, notes: '', apk: null } }
async function publish() {
  if (!/^\d+\.\d+\.\d+$/.test(f.value.version)) return ui.toast('رقم الإصدار بصيغة 1.2.3', 'warn')
  busy.value = true
  const fd = new FormData(); fd.append('version', f.value.version); fd.append('build', f.value.build); fd.append('notes', f.value.notes || ''); if (f.value.apk) fd.append('apk', f.value.apk)
  try { await api.post('/app/version', fd, { headers: { 'Content-Type': 'multipart/form-data' } }); ui.toast('نُشر الإصدار', 'ok'); load() } catch (e) { ui.toast(e.message || 'تعذر النشر', 'bad') } finally { busy.value = false }
}
onMounted(load)
</script>
