<template>
  <div class="page-h">
    <div><h1>{{ t('التكامل مع أودو') }}</h1><p>ربط المنصة بأودو لتنسيق الشركات: الشركات والموظفون والقسائم والسلف وتسويات العهد تُرسَل عبر صندوق صادر بإعادة محاولة تلقائية، ويمكن استيراد الموظفين والشركات من أودو، وتعيين الحقول بيانات قابلة للتحرير</p></div>
    <div class="acts"><button class="btn sec sm" :class="{ loading: busyRun }" @click="runNow"><Icon name="refresh" :size="16" /> إرسال الصادر الآن</button></div>
  </div>
  <div v-if="!st"><div class="skel" v-for="i in 4" :key="i" /></div>
  <template v-else>
    <div class="grid g4 mb">
      <Kpi label="الحالة" :value="st.enabled ? 'مفعّل' : 'معطّل'" :color="st.enabled ? 'g' : 'r'" />
      <Kpi label="بانتظار الإرسال" :value="st.counts.queued" color="y" />
      <Kpi label="أُرسل" :value="st.counts.sent" :hint="st.last_sent ? 'آخر إرسال ' + fmtDT(st.last_sent) : ''" color="g" />
      <Kpi label="فشل نهائيًا" :value="st.counts.failed" color="r" />
    </div>
    <div class="tabs"><button v-for="x in TABS" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; x[0] === 'outbox' && loadOutbox()">{{ x[1] }}</button></div>

    <div v-if="tab === 'conn'" class="card">
      <h3>الاتصال</h3>
      <div class="fr">
        <div class="fg"><label>رابط خادم أودو</label><input v-model="c.url" class="in" style="direction:ltr" placeholder="https://odoo.example.com" /></div>
        <div class="fg"><label>قاعدة البيانات</label><input v-model="c.db" class="in" style="direction:ltr" /></div>
        <div class="fg"><label>المستخدم (البريد)</label><input v-model="c.user" class="in" style="direction:ltr" /></div>
        <div class="fg"><label>مفتاح API {{ st.settings.has_key ? '(محفوظ — اتركه فارغًا للإبقاء)' : '' }}</label><input v-model="c.api_key" type="password" class="in" style="direction:ltr" autocomplete="new-password" /></div>
      </div>
      <div class="row mb">
        <label class="chk"><input v-model="c.enabled" type="checkbox" /> تفعيل التكامل</label>
        <label class="chk"><input v-model="c.auto" type="checkbox" /> إرسال تلقائي كل 5 دقائق (المجدول)</label>
      </div>
      <div class="fg"><label>الكيانات المتزامنة</label>
        <div class="row"><label v-for="(l, k) in st.entities" :key="k" class="chk"><input v-model="c.entities" type="checkbox" :value="k" /> {{ l }} <span v-if="st.linked[k]" class="tiny muted">({{ st.linked[k] }} مرتبط)</span></label></div>
      </div>
      <div class="row">
        <button class="btn pri" :class="{ loading: busy }" @click="saveConn">حفظ</button>
        <button class="btn sec" :class="{ loading: busyTest }" @click="test"><Icon name="activity" :size="15" /> اختبار الاتصال</button>
        <span v-if="testRes" class="pill" :class="testRes.ok ? 'ok' : 'bad'">{{ testRes.ok ? `متصل — Odoo ${testRes.version} (uid ${testRes.uid})` : testRes.error }}</span>
      </div>
      <div class="notice info mt">
        <b>استقبال التحديثات من أودو:</b> أنشئ Automated Action في أودو يستدعي <code style="direction:ltr;display:inline-block">POST {{ apiBase }}/integrations/odoo/webhook</code> بجسم <code>{ token, model, id, values }</code>؛ الرمز يُحفظ في الإعدادات (odooWebhookToken).
        في الإنتاج شغّل <code>php artisan schedule:work</code> أو cron لكل دقيقة، و<code>php artisan queue:work</code> للإرسال في الخلفية.
      </div>
    </div>

    <div v-else-if="tab === 'sync'" class="card">
      <h3>مزامنة يدوية</h3>
      <div v-dt class="tw"><table>
        <thead><tr><th>الكيان</th><th>مرتبط بأودو</th><th>نموذج أودو</th><th /></tr></thead>
        <tbody><tr v-for="(l, k) in st.entities" :key="k">
          <td><b>{{ l }}</b></td><td>{{ st.linked[k] || 0 }}</td><td class="num small" style="direction:ltr">{{ maps?.effective?.[k]?.model }}</td>
          <td class="row" style="gap:6px;flex-wrap:nowrap">
            <button class="btn sec xs" :disabled="!st.enabled" @click="pushAll(k)">إرسال الكل إلى أودو</button>
            <button v-if="['company', 'employee'].includes(k)" class="btn ghost xs" :disabled="!st.enabled" @click="pull(k)">استيراد من أودو</button>
          </td>
        </tr></tbody>
      </table></div>
      <p class="tiny muted mt">الإرسال يتخطى السجلات التي لم تتغير منذ آخر مزامنة (بصمة المحتوى)، ويربط السجلات الموجودة في أودو بدل تكرارها (حقل المطابقة في التعيين).</p>
    </div>

    <div v-else-if="tab === 'map'" class="card">
      <h3>تعيين الحقول <span class="sub">HC ← Odoo · يُعدَّل كـ JSON ويُدمج فوق الافتراضي</span></h3>
      <div class="fr">
        <div class="fg"><label>الكيان</label><select v-model="mapEntity" class="in"><option v-for="(l, k) in st.entities" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="fg"><label>حقول نموذج أودو (يتطلب اتصالًا)</label><button class="btn sec sm" :class="{ loading: busyFields }" @click="loadFields">جلب حقول {{ maps?.effective?.[mapEntity]?.model }}</button></div>
      </div>
      <div class="grid g2">
        <div class="fg"><label>التعيين الفعّال (للقراءة)</label><textarea class="in" rows="16" style="direction:ltr;font-family:monospace;font-size:12px" readonly :value="JSON.stringify(maps?.effective?.[mapEntity] || {}, null, 2)" /></div>
        <div class="fg"><label>تخصيصك لهذا الكيان (JSON)</label><textarea v-model="mapText" class="in" rows="16" style="direction:ltr;font-family:monospace;font-size:12px" /></div>
      </div>
      <div v-dt v-if="fields.length" class="tw mb" style="max-height:220px;overflow:auto"><table>
        <thead><tr><th>الحقل</th><th>التسمية</th><th>النوع</th><th>إلزامي</th></tr></thead>
        <tbody><tr v-for="x in fields" :key="x.name"><td class="num" style="direction:ltr">{{ x.name }}</td><td>{{ x.label }}</td><td>{{ x.type }}{{ x.relation ? ' → ' + x.relation : '' }}</td><td>{{ x.required ? '✓' : '' }}</td></tr></tbody>
      </table></div>
      <div class="fg"><label>سبب التعديل *</label><input v-model="mapReason" class="in" /></div>
      <button class="btn pri" :class="{ loading: busy }" @click="saveMap">حفظ التعيين</button>
    </div>

    <div v-else class="card">
      <h3>الصادر <span class="acts"><select v-model="obStatus" class="in" style="width:auto" @change="loadOutbox"><option value="">كل الحالات</option><option value="queued">بانتظار</option><option value="sent">أُرسل</option><option value="failed">فشل</option><option value="skipped">تخطّي</option></select></span></h3>
      <div v-if="!ob.length" class="empty">لا سجلات</div>
      <div v-dt v-else class="tw"><table>
        <thead><tr><th>#</th><th>الكيان</th><th>الإجراء</th><th>الحالة</th><th>محاولات</th><th>آخر خطأ / نتيجة</th><th>التاريخ</th><th /></tr></thead>
        <tbody><tr v-for="r in ob" :key="r.id">
          <td>{{ r.id }}</td><td>{{ st.entities[r.entity] || r.entity }} #{{ r.entity_id }}</td><td>{{ r.action }}</td>
          <td><span class="pill" :class="{ queued: 'warn', sent: 'ok', failed: 'bad', skipped: 'gray' }[r.status]">{{ r.status_label }}</span></td>
          <td>{{ r.attempts }}</td>
          <td class="small" style="max-width:320px">{{ r.last_error || (r.response ? JSON.stringify(r.response) : '—') }}</td>
          <td class="small">{{ fmtDT(r.sent_at || r.created_at) }}</td>
          <td><button v-if="r.status !== 'sent'" class="btn ghost xs" @click="retry(r)">إعادة</button></td>
        </tr></tbody>
      </table></div>
    </div>
  </template>
</template>

<script setup>
import { confirmBox } from '../dialog'
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import { useUi } from '../stores/ui'
import { fmtDT } from '../utils'

const { t } = useI18n()
const ui = useUi()
const TABS = [['conn', 'الاتصال'], ['sync', 'المزامنة'], ['map', 'تعيين الحقول'], ['outbox', 'الصادر']]
const tab = ref('conn')
const st = ref(null); const c = ref({}); const maps = ref(null); const mapEntity = ref('employee'); const mapText = ref('{}'); const mapReason = ref('')
const fields = ref([]); const ob = ref([]); const obStatus = ref('')
const busy = ref(false); const busyTest = ref(false); const busyRun = ref(false); const busyFields = ref(false); const testRes = ref(null)
const apiBase = `${location.origin.replace(':5173', ':8000')}/api/v1`

async function load() {
  st.value = await api.get('/integrations/odoo')
  c.value = { enabled: st.value.enabled, url: st.value.settings.url || '', db: st.value.settings.db || '', user: st.value.settings.user || '', api_key: '', entities: st.value.settings.entities || [], auto: st.value.settings.auto }
  maps.value = await api.get('/integrations/odoo/mappings')
  syncMapText()
}
const syncMapText = () => { mapText.value = JSON.stringify(maps.value?.custom?.[mapEntity.value] || {}, null, 2) }
watch(mapEntity, () => { syncMapText(); fields.value = [] })
async function saveConn() { busy.value = true; try { st.value = await api.put('/integrations/odoo', c.value); c.value.api_key = ''; ui.toast('حُفظت إعدادات التكامل', 'ok') } finally { busy.value = false } }
async function test() { busyTest.value = true; testRes.value = null; try { testRes.value = await api.post('/integrations/odoo/test') } catch (e) { testRes.value = { ok: false, error: e.error || e.message } } finally { busyTest.value = false } }
async function runNow() { busyRun.value = true; try { st.value = await api.post('/integrations/odoo/run'); ui.toast('تمت معالجة الصادر', 'ok'); if (tab.value === 'outbox') loadOutbox() } finally { busyRun.value = false } }
async function pushAll(entity) { if (!await confirmBox('إرسال كل سجلات هذا الكيان إلى أودو؟')) return; const r = await api.post('/integrations/odoo/push-all', { entity }); ui.toast(`أُضيف ${r.queued} سجلًا للصادر`, 'ok'); load() }
async function pull(entity) { if (!await confirmBox('استيراد من أودو؟ السجلات المطابقة تُحدَّث والجديدة تُنشأ.')) return; const r = await api.post('/integrations/odoo/pull', { entity }); ui.toast(r.status === 'sent' ? `استُورد ${r.response?.pulled} سجلًا (جديد ${r.response?.created}، محدَّث ${r.response?.updated})` : `لم يكتمل: ${r.last_error}`, r.status === 'sent' ? 'ok' : 'bad', 6000); load() }
async function loadFields() { busyFields.value = true; try { fields.value = await api.get('/integrations/odoo/fields', { params: { model: maps.value?.effective?.[mapEntity.value]?.model } }) } catch (e) { ui.toast(e.error || e.message, 'bad') } finally { busyFields.value = false } }
async function saveMap() {
  let parsed
  try { parsed = JSON.parse(mapText.value || '{}') } catch { return ui.toast('JSON غير صالح', 'bad') }
  if (!mapReason.value.trim()) return ui.toast('سبب التعديل إلزامي', 'warn')
  busy.value = true
  try { const all = { ...(maps.value.custom || {}), [mapEntity.value]: parsed }; await api.put('/integrations/odoo/mappings', { mappings: all, reason: mapReason.value }); ui.toast('حُفظ التعيين', 'ok'); maps.value = await api.get('/integrations/odoo/mappings'); syncMapText() } finally { busy.value = false }
}
async function loadOutbox() { ob.value = (await api.get('/integrations/odoo/outbox', { params: { status: obStatus.value || undefined } })).data }
async function retry(r) { await api.post(`/integrations/odoo/outbox/${r.id}/retry`); loadOutbox(); load() }
onMounted(load)
</script>
