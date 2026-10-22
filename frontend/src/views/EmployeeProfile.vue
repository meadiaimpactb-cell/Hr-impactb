<template>
  <div v-if="!d"><div class="skel" v-for="i in 8" :key="i" /></div>
  <template v-else>
    <div class="page-h">
      <div class="row" style="gap:12px">
        <Avatar :name="e.name" :url="e.user?.photo_url" :size="56" :user-id="e.user?.id" :editable="!!e.user && (auth.has('super', 'hr_mgr') || e.user.id === auth.user.id)" @changed="(u) => { e.user.photo_url = u; if (e.user.id === auth.user.id) auth.user.photo_url = u }" />
        <div><h1>{{ e.name }}</h1><p>{{ e.emp_no }} · {{ e.company?.name }} · {{ e.job_title?.name }} · <StatusPill :status="e.status" /></p></div>
      </div>
      <div class="acts">
        <button v-if="d.can.edit" class="btn sec sm" @click="editing = true"><Icon name="edit" :size="16" /> {{ t('تعديل') }}</button>
        <button v-if="d.can.hr && e.user" class="btn ghost sm" :class="{ loading: resetting }" @click="resetPassword"><Icon name="key" :size="16" /> إرسال دعوة الدخول</button>
        <button v-if="(d.can.hr || auth.isApprover) && e.id !== auth.emp?.id" class="btn pri sm" @click="onBehalf = true"><Icon name="requests" :size="16" /> طلب نيابةً عنه</button>
        <button v-if="auth.meta?.policy?.kudos_enabled && e.id !== auth.emp?.id" class="btn ghost sm" @click="kudos"><Icon name="check-circle" :size="16" /> إشادة</button>
        <RouterLink class="btn ghost sm" to="/employees"><Icon name="arrow-right" :size="16" flip-rtl /> {{ t('الموظفون') }}</RouterLink>
      </div>
    </div>
    <div class="grid g4 mb">
      <Kpi label="مدة الخدمة" :value="d.service" color="n" />
      <Kpi label="حضور الشهر" :value="`${d.attendance.present}/${d.attendance.required}`" :hint="`تأخير ${d.attendance.late} · غياب ${d.attendance.absent}`" color="g" />
      <Kpi label="رصيد السنوية" :value="annual?.available ?? '—'" hint="يوم" color="b" />
      <Kpi label="التصنيف" :value="d.tier?.name || '—'" :hint="`${d.reports_count} مرؤوس`" color="p" />
    </div>
    <div class="tabs">
      <button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]">{{ t(x[1]) }}</button>
    </div>

    <template v-if="tab === 'info'">
      <div class="card">
        <div v-if="d.can.edit" class="tiny muted mb"><Icon name="edit" :size="13" /> انقر على أي قيمة عليها قلم لتعديلها مباشرة، ثم «حفظ» أو Enter. للحقول الأخرى استخدم زر «تعديل» أعلى الصفحة.</div>
        <InlineTable :rows="infoRows" :can="canInline" :submit="saveField" @start="onStart" />
      </div>
      <AccountCard v-if="auth.isAdmin" :employee="e" @changed="load" />
    </template>

    <div v-else-if="tab === 'contract'" class="card">
      <div v-if="!d.salary" class="notice warn">ليست لديك صلاحية الاطلاع على بيانات الأجر.</div>
      <template v-else>
        <div class="grid g3 mb">
          <Kpi label="الأجر الشهري الإجمالي" color="n"><span class="num">{{ money(d.salary.gross) }}</span></Kpi>
          <Kpi label="الأجر المعتبر لنهاية الخدمة" hint="أساسي + سكن + نقل" color="b"><span class="num">{{ money(d.salary.considered) }}</span></Kpi>
          <Kpi label="الحساب البنكي" :value="e.bank_iban ? '****' + e.bank_iban.slice(-4) : '—'" color="p" />
        </div>
        <h3>بيانات العقد <span v-if="d.can.edit" class="sub">انقر على أي قيمة عليها قلم لتعديلها وحفظها مباشرة</span></h3>
        <InlineTable :rows="contractRows" :can="canInline" :submit="saveField" />
        <h3 class="mt">مكوّنات الأجر <span v-if="d.can.hr" class="sub">انقر على المبلغ لتعديله. يُرفع التعديل طلبًا يعتمده الرئيس التنفيذي للمجموعة ثم يُطبَّق من التاريخ الذي تحدده</span>
          <span v-if="d.can.hr" class="acts"><button class="btn sec xs" @click="payEdit = { id: 'new', key: 'alw' + Date.now().toString(36).slice(-5), name: '', amount: null, effective: today(), reason: '' }"><Icon name="plus" :size="14" /> إضافة بدل</button></span></h3>
        <div class="tw"><table class="info-t">
          <thead><tr><th>المكوّن</th><th>القيمة</th><th>المصدر</th><th>ساري من</th></tr></thead>
          <tbody>
            <tr v-for="c in d.salary.components" :key="c.id"><td>{{ c.name }}</td>
              <td :class="{ editable: d.can.hr && payEdit?.id !== c.id }" @click="d.can.hr && payEdit?.id !== c.id && (payEdit = { id: c.id, key: c.key, name: c.name, amount: +c.amount, effective: today(), reason: '' })">
                <span v-if="payEdit?.id !== c.id" class="num">{{ money(c.amount) }} <Icon v-if="d.can.hr" name="edit" :size="13" class="pen" /></span>
                <div v-else class="row" style="gap:6px;flex-wrap:wrap" @click.stop>
                  <input v-model.number="payEdit.amount" type="number" min="0" step="0.01" class="in num" style="width:130px" placeholder="المبلغ الجديد" @keydown.enter="savePay" @keydown.esc="payEdit = null" />
                  <DateInput v-model="payEdit.effective" style="max-width:170px" title="ساري من" />
                  <input v-model.trim="payEdit.reason" class="in" style="min-width:200px;flex:1" placeholder="سبب التعديل (إلزامي)" @keydown.enter="savePay" />
                  <button class="btn pri xs" :class="{ loading: saving }" :disabled="saving" @click="savePay"><Icon name="send" :size="14" flip-rtl /> رفع للاعتماد</button>
                  <button class="btn ghost xs" title="إلغاء" @click="payEdit = null"><Icon name="x" :size="14" /></button>
                </div>
              </td>
              <td><span class="pill" :class="{ tier: 'purple', custom: 'warn' }[c.source] || 'info'">{{ { tier: 'موروث من التصنيف', custom: 'مخصص', contract: 'تعاقدي' }[c.source] || c.source }}</span></td><td class="num">{{ fmtD(c.valid_from) }}</td></tr>
            <tr v-if="payEdit?.id === 'new'"><td><input v-model.trim="payEdit.name" class="in" list="alw-names2" placeholder="اسم البدل" /><datalist id="alw-names2"><option>بدل طبيعة عمل</option><option>بدل غلاء معيشة</option><option>بدل اتصال</option><option>حوافز فردية</option></datalist></td>
              <td colspan="3"><div class="row" style="gap:6px;flex-wrap:wrap">
                <input v-model.number="payEdit.amount" type="number" min="0" step="0.01" class="in num" style="width:130px" placeholder="المبلغ" />
                <DateInput v-model="payEdit.effective" style="max-width:170px" />
                <input v-model.trim="payEdit.reason" class="in" style="min-width:200px;flex:1" placeholder="سبب الإضافة (إلزامي)" />
                <button class="btn pri xs" :class="{ loading: saving }" :disabled="saving" @click="savePay"><Icon name="send" :size="14" flip-rtl /> رفع للاعتماد</button>
                <button class="btn ghost xs" title="إلغاء" @click="payEdit = null"><Icon name="x" :size="14" /></button>
              </div></td></tr>
          </tbody>
        </table></div>
        <p class="tiny muted mt">بيانات العقد تُحفظ فورًا. أما الأجر فيُرفع طلبًا يعتمده الرئيس التنفيذي للمجموعة، ثم يُطبَّق ويُسجَّل في المسار الوظيفي. تابع الطلب من «صندوق الموافقات».</p>
      </template>
    </div>

    <div v-else-if="tab === 'att'" class="card">
      <div class="row mb"><MonthPicker v-model="period" @change="loadAtt" /></div>
      <AttendanceTable :records="att?.records || []" :grace="auth.meta?.policy?.grace_minutes ?? 0" />
    </div>

    <div v-else-if="tab === 'lv'" class="card">
      <h3>أرصدة الإجازات {{ new Date().getFullYear() }} <span class="sub">المتاح = المكتسب + الافتتاحي ± التعديلات − المستهلك − المحجوز</span></h3>
      <div class="grid g4">
        <div v-for="b in d.balances" :key="b.type" class="lvcard">
          <Kpi :icon="LT_ICON[b.type] || 'calendar'" :label="b.name" color="b" :hint="`مستهلك ${b.used} · محجوز ${b.reserved}${b.accrual === 'monthly' ? ' · مكتسب ' + b.accrued : ''}`">{{ b.available }} <span class="muted small">يوم</span></Kpi>
          <button v-if="d.can.hr" class="btn ghost xs lvedit" title="تعديل الرصيد والمستهلك" @click="lvForm = { type: b.type, name: b.name, available: b.available, used: b.used, reason: '' }"><Icon name="edit" :size="14" /> تعديل</button>
        </div>
      </div>
      <div v-if="ledger.length" class="mt">
        <h3>سجل الحركات اليدوية</h3>
        <div v-dt class="tw"><table><thead><tr><th>التاريخ</th><th>النوع</th><th>الحركة</th><th>الأيام</th><th>البيان</th></tr></thead>
          <tbody><tr v-for="l in ledger" :key="l.id"><td>{{ l.occurred_on?.slice(0, 10) }}</td><td>{{ d.balances.find((b) => b.type === l.leave_type)?.name || l.leave_type }}</td><td>{{ { opening: 'رصيد افتتاحي', adjust: 'تعديل', used: 'استهلاك' }[l.entry] || l.entry }}</td><td class="num">{{ l.days }}</td><td class="small">{{ l.note }}</td></tr></tbody></table></div>
      </div>
    </div>

    <template v-else-if="tab === '360'">
      <div v-if="!x"><div class="skel" v-for="i in 4" :key="i" /></div>
      <div v-else class="grid g2">
        <div v-if="x.documents" class="card" style="grid-column:1/-1"><h3>الوثائق والمرفقات <span class="cnt">{{ x.documents.length }}</span><span class="acts"><button v-if="d.can.hr" class="btn pri xs" @click="docForm = { type: '', number: '', issue_date: null, expiry_date: null, issuer: '', file: null }"><Icon name="plus" :size="14" /> إضافة وثيقة</button></span></h3>
          <div v-if="!x.documents.length" class="empty">لا وثائق — أضف الهوية والعقد والشهادات مع مرفقاتها</div>
          <div v-else class="tw"><table><thead><tr><th>النوع</th><th>الرقم</th><th>الإصدار</th><th>الانتهاء</th><th>الحالة</th><th>المرفق</th><th /></tr></thead>
            <tbody><tr v-for="dd in x.documents" :key="dd.id">
              <td><b>{{ dd.type }}</b></td><td class="num">{{ dd.number || '—' }}</td><td>{{ dd.issue_date || '—' }}</td><td>{{ dd.expiry_date || '—' }}</td>
              <td><span class="pill" :class="dd.state.color">{{ dd.state.label }}</span></td>
              <td><button v-if="dd.file" class="btn ghost xs" @click="fileUrl(dd.file)"><Icon name="download" :size="13" /> عرض</button><span v-else class="muted">—</span></td>
              <td><div v-if="d.can.hr" class="row" style="gap:2px;flex-wrap:nowrap"><button class="btn ghost xs" title="تعديل" @click="docForm = { id: dd.id, type: dd.type_code, number: dd.number, issue_date: dd.issue_date, expiry_date: dd.expiry_date, issuer: dd.issuer, file: null }"><Icon name="edit" :size="14" /></button><button class="btn ghost xs bad" title="حذف" @click="removeDoc(dd)"><Icon name="trash" :size="14" /></button></div></td>
            </tr></tbody></table></div></div>
        <div v-if="x.evaluations" class="card"><h3>التقييمات <span class="acts"><RouterLink class="btn ghost xs" to="/evaluation">التقييم</RouterLink></span></h3>
          <div v-if="!x.evaluations.length" class="empty">لا تقييمات منشورة</div>
          <div v-for="ev in x.evaluations" :key="ev.id" class="row between small" style="padding:4px 0;border-bottom:1px solid var(--line2)"><span>{{ fmtYM(ev.period) }}</span><span><b class="num">{{ ev.score }}</b> {{ ev.grade }} · {{ ev.grade_label }}</span></div>
          <div v-if="x.reports?.length" class="tiny muted mt">التقارير: {{ x.reports.map((r) => `${fmtYM(r.period)} ${r.status_label}`).join(' · ') }}</div></div>
        <div v-if="x.violations" class="card"><h3>المخالفات <span class="cnt">{{ x.violations.length }}</span></h3>
          <div v-if="!x.violations.length" class="empty">لا مخالفات</div>
          <div v-for="v in x.violations" :key="v.id" class="row between small" style="padding:4px 0;border-bottom:1px solid var(--line2)"><span>{{ v.type }} · {{ v.occurred_on }}</span><span>{{ v.degree || '—' }}{{ v.amount ? ` (${money(v.amount)})` : '' }} · {{ v.status }}</span></div></div>
        <div v-if="x.custody" class="card"><h3>العهد <span class="cnt">{{ x.custody.length }}</span></h3>
          <div v-if="!x.custody.length" class="empty">لا عهد</div>
          <div v-for="c in x.custody" :key="c.id" class="row between small" style="padding:4px 0;border-bottom:1px solid var(--line2);cursor:pointer" title="فتح العهدة: إقرار الاستلام، طلب الإعادة، الاستلام للفحص" @click="custodyId = c.id"><span>{{ c.ident }} <span class="muted">{{ c.type }}</span></span><span class="num">{{ money(c.value) }} · {{ c.status }}</span></div></div>
        <div v-if="x.development" class="card"><h3>التطوير <span class="cnt">{{ x.development.length }}</span></h3>
          <div v-if="!x.development.length" class="empty">لا سجلات</div>
          <div v-for="r in x.development" :key="r.id" class="row between small" style="padding:4px 0;border-bottom:1px solid var(--line2)"><span>{{ r.program }} <span class="muted">{{ r.provider }}</span></span><span>{{ r.hours }} س · {{ r.status }}</span></div></div>
        <div v-if="x.letters" class="card"><h3>الخطابات</h3>
          <div v-if="!x.letters.length" class="empty">لا خطابات</div>
          <div v-for="l in x.letters" :key="l.id" class="row between small" style="padding:4px 0;border-bottom:1px solid var(--line2)"><span>{{ l.kind }} · {{ l.to || '' }}</span><RouterLink class="btn ghost xs" :to="`/print/letter/${l.id}`">{{ l.no }}</RouterLink></div></div>
        <div v-if="x.insurance" class="card"><h3>التأمين الطبي</h3>
          <div v-if="!x.insurance.current" class="small muted">غير مضاف لوثيقة — فئة التصنيف {{ x.insurance.tier_class }} · التابعون {{ x.insurance.tier_dependents }}</div>
          <div v-else class="small">الفئة <b>{{ x.insurance.current.class }}</b> · التابعون <b>{{ x.insurance.current.dependents }}</b> · البطاقة {{ x.insurance.current.card || '—' }} · منذ {{ x.insurance.current.since }}</div></div>
        <div v-if="x.termination" class="card"><h3>إنهاء الخدمة</h3><div class="small">{{ x.termination.reason }} · آخر يوم عمل {{ x.termination.last_working_day }} · {{ x.termination.status }} · المرحلة {{ x.termination.stage }}/4 <RouterLink class="btn ghost xs" to="/terminations">فتح</RouterLink></div></div>
      </div>
    </template>

    <div v-else-if="tab === 'career'" class="card">
      <h3>المسار الوظيفي <span class="sub">الترقيات والتغييرات الوظيفية بتاريخها</span><span class="acts"><button v-if="d.can.hr" class="btn pri xs" @click="careerForm = { type: 'ترقية', from: e.job_title?.name || '', to: '', occurred_on: today(), note: '', job_title_id: null }"><Icon name="plus" :size="14" /> تسجيل حركة وظيفية</button></span></h3>
      <div v-if="!e.career?.length" class="empty">لا حركات مسجلة</div>
      <ul v-else class="timeline"><li v-for="c in e.career" :key="c.id"><b>{{ c.type }}</b> <span class="tiny muted">{{ c.occurred_on }}{{ c.user ? ' · ' + c.user.name : '' }}</span><button v-if="d.can.hr && MANUAL.includes(c.type)" class="btn ghost xs bad" style="float:left" title="حذف" @click="removeCareer(c)"><Icon name="trash" :size="13" /></button><div class="small">{{ c.detail }}</div></li></ul>
      <p class="tiny muted">تُكتب الحركات المعتمدة (تعيين، تعديل أجر، نقل…) تلقائيًا، ويمكن للموارد البشرية تسجيل الترقيات والتغييرات يدويًا.</p>
    </div>

    <div v-else-if="tab === 'perf'" class="card">
      <div class="grid g3 mb">
        <Kpi label="التصنيف" :value="d.tier?.name || '—'" :hint="e.job_title?.name || ''" color="p" />
        <Kpi v-if="d.salary" label="الراتب الشهري الإجمالي" color="n"><span class="num">{{ money(d.salary.gross) }}</span></Kpi>
        <Kpi label="آخر تقييم منشور" :value="x?.evaluations?.[0]?.score ?? '—'" :hint="x?.evaluations?.[0] ? fmtYM(x.evaluations[0].period) + ' · ' + x.evaluations[0].grade_label : 'لم يُنشر بعد'" color="g" />
      </div>
      <h3>التقارير الشهرية <span class="acts"><button class="btn sec xs" title="نموذج Excel فارغ فيه مهام الموظف ومؤشراته ليعبئه" @click="reportForm"><Icon name="download" :size="14" /> نموذج التقرير الفارغ</button><RouterLink class="btn ghost xs" to="/reports">قسم التقارير ←</RouterLink><RouterLink class="btn ghost xs" to="/evaluation">التقييم ←</RouterLink></span></h3>
      <div v-if="!x"><div class="skel" v-for="i in 3" :key="i" /></div>
      <div v-else-if="!x.reports?.length" class="empty">لا تقارير شهرية بعد</div>
      <div v-else class="tw"><table><thead><tr><th>الشهر</th><th>الحالة</th><th>البنود</th><th>تقييم الجودة</th><th>التعاون</th><th>ملاحظة المدير</th><th>الدرجة</th></tr></thead>
        <tbody><tr v-for="r in x.reports" :key="r.id"><td><b>{{ fmtYM(r.period) }}</b></td><td><span class="pill" :class="r.status === 'approved' ? 'ok' : r.status === 'returned' ? 'bad' : 'info'">{{ r.status_label }}</span><span v-if="r.late" class="pill warn">متأخر</span></td>
          <td>{{ r.items }}</td><td class="num">{{ r.quality ?? '—' }}</td><td class="num">{{ r.coop ?? '—' }}</td><td class="small">{{ r.mgr_note || '—' }}</td>
          <td class="num">{{ x.evaluations?.find((ev) => ev.period === r.period)?.score ?? '—' }}</td></tr></tbody></table></div>
    </div>
  </template>
  <EmployeeForm v-if="editing" :id="+id" @close="editing = false" @saved="load" />
  <Modal v-if="lvForm" :title="`تعديل رصيد ${lvForm.name}`" @close="lvForm = null">
    <div class="fr">
      <div class="fg"><label>الرصيد المتاح (يوم)</label><input v-model.number="lvForm.available" type="number" step="0.25" class="in" /></div>
      <div class="fg"><label>المستهلك هذا العام (يوم)</label><input v-model.number="lvForm.used" type="number" step="0.25" min="0" class="in" /></div>
    </div>
    <div class="fg"><label>سبب التعديل *</label><input v-model="lvForm.reason" class="in" placeholder="مثال: رصيد مرحّل من النظام السابق" /></div>
    <p class="tiny muted">يُسجَّل الفرق كحركة في سجل الإجازات وفي سجل التدقيق والمسار الوظيفي، ولا يُمسح أي تاريخ سابق.</p>
    <template #foot><button class="btn sec" @click="lvForm = null">إلغاء</button><button class="btn pri" :class="{ loading: saving }" :disabled="saving" @click="saveLeave">حفظ</button></template>
  </Modal>
  <Modal v-if="docForm" :title="docForm.id ? 'تعديل وثيقة' : 'إضافة وثيقة'" @close="docForm = null">
    <div class="fr">
      <div class="fg"><label>نوع الوثيقة *</label><select v-model="docForm.type" class="in"><option value="" disabled>اختر…</option><option v-for="t in docTypes" :key="t.code" :value="t.code">{{ t.name }}</option></select></div>
      <div class="fg"><label>رقم الوثيقة</label><input v-model="docForm.number" class="in" dir="ltr" /></div>
      <div class="fg"><label>تاريخ الإصدار</label><DateInput v-model="docForm.issue_date" /></div>
      <div class="fg"><label>تاريخ الانتهاء</label><DateInput v-model="docForm.expiry_date" /></div>
      <div class="fg"><label>جهة الإصدار</label><input v-model="docForm.issuer" class="in" /></div>
      <div class="fg"><label>المرفق (PDF أو صورة){{ docForm.id ? ' — اتركه فارغًا للإبقاء على الحالي' : '' }}</label><input type="file" class="in" accept=".pdf,image/*" @change="docForm.file = $event.target.files[0]" /></div>
    </div>
    <template #foot><button class="btn sec" @click="docForm = null">إلغاء</button><button class="btn pri" :class="{ loading: saving }" :disabled="saving" @click="saveDoc">حفظ</button></template>
  </Modal>
  <Modal v-if="careerForm" title="تسجيل حركة وظيفية" @close="careerForm = null">
    <div class="fr">
      <div class="fg"><label>نوع الحركة *</label><select v-model="careerForm.type" class="in"><option v-for="t in MANUAL" :key="t">{{ t }}</option></select></div>
      <div class="fg"><label>التاريخ *</label><DateInput v-model="careerForm.occurred_on" /></div>
      <div class="fg"><label>من</label><input v-model="careerForm.from" class="in" placeholder="مثال: محاسب أول" /></div>
      <div class="fg"><label>إلى</label><input v-model="careerForm.to" class="in" placeholder="مثال: رئيس حسابات" /></div>
    </div>
    <div v-if="['ترقية', 'تغيير مسمى'].includes(careerForm.type)" class="fg"><label>تحديث المسمى الوظيفي في الملف (اختياري)</label><select v-model="careerForm.job_title_id" class="in"><option :value="null">— بدون تغيير —</option><option v-for="j in auth.meta.job_titles" :key="j.id" :value="j.id">{{ j.name }}</option></select></div>
    <div class="fg"><label>ملاحظة</label><input v-model="careerForm.note" class="in" /></div>
    <template #foot><button class="btn sec" @click="careerForm = null">إلغاء</button><button class="btn pri" :class="{ loading: saving }" :disabled="saving" @click="saveCareer">حفظ</button></template>
  </Modal>
  <CredentialsModal v-if="creds" v-bind="creds" @close="creds = null" />
  <CustodyItemModal v-if="custodyId" :id="custodyId" @close="custodyId = null" @changed="load" />
  <OnBehalfRequest v-if="onBehalf" :employee="e" :components="d.salary?.components || []" :balances="d.balances" :tier="d.tier" @close="onBehalf = false" @done="load" />
</template>

<script setup>
import { alertBox, confirmBox, promptBox } from '../dialog'
import { exportReportForm } from '../excel'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import AccountCard from '../components/AccountCard.vue'
import AttendanceTable from '../components/AttendanceTable.vue'
import CredentialsModal from '../components/CredentialsModal.vue'
import EmployeeForm from '../components/EmployeeForm.vue'
import OnBehalfRequest from '../components/OnBehalfRequest.vue'
import CustodyItemModal from '../components/CustodyItemModal.vue'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import { fileUrl } from '../api'
import { useUi } from '../stores/ui'
import StatusPill from '../components/StatusPill.vue'
import { useAuth } from '../stores/auth'
import { contractLabel, curYM, DAYS_AR, fmtD, fmtDT, fmtYM, money, today } from '../utils'
import { countryOptions, nationalityLabel } from '../countries'
import InlineTable from '../components/InlineTable.vue'

const props = defineProps({ id: [String, Number] })
const { t } = useI18n()
const auth = useAuth()
const d = ref(null)
const tab = ref('info')
const editing = ref(false)
const creds = ref(null)
const ui = useUi()
const saving = ref(false)
const lvForm = ref(null)
const docForm = ref(null)
const careerForm = ref(null)
const ledger = ref([])
const MANUAL = ['ترقية', 'نقل', 'تغيير مسمى', 'تكليف', 'تعديل درجة', 'إنجاز', 'ملاحظة']
const docTypes = computed(() => (auth.meta?.lookups?.document_types || []).filter((t) => (t.meta?.scope || 'employee') === 'employee'))
const errMsg = (x) => Object.values(x.errors || {}).flat().join(' · ') || x.message
async function reportForm() {
  const f = await api.get(`/reports/template-for/${props.id}`)
  if (!f.items.length) return alertBox('لا يوجد نموذج تقرير لهذا الموظف بعد. أنشئه من «التقارير الدورية» ثم «قوالب التقارير» باختيار النطاق «موظف بعينه».', { icon: 'info' })
  await exportReportForm({ name: f.employee.name, company: f.company, job: f.job, manager: f.manager, period: curYM() }, f.items)
}
async function loadLedger() { try { ledger.value = await api.get(`/employees/${props.id}/leave-ledger`) } catch { ledger.value = [] } }
async function saveLeave() {
  const f = lvForm.value
  if (!f.reason?.trim()) return ui.toast('سبب التعديل إلزامي', 'warn')
  saving.value = true
  try { const r = await api.post(`/employees/${props.id}/leave-adjust`, { type: f.type, available: f.available, used: f.used, reason: f.reason }); d.value.balances = r.balances; lvForm.value = null; ui.toast('حُدّث الرصيد', 'ok'); loadLedger() }
  catch (x) { alertBox(errMsg(x), { icon: 'error' }) } finally { saving.value = false }
}
async function saveDoc() {
  const f = docForm.value
  if (!f.type) return ui.toast('اختر نوع الوثيقة', 'warn')
  const fd = new FormData()
  fd.append('scope', 'employee'); fd.append('owner_id', props.id); fd.append('type', f.type)
  for (const k of ['number', 'issue_date', 'expiry_date', 'issuer']) if (f[k]) fd.append(k, f[k])
  if (f.file) fd.append('attachment', f.file)
  saving.value = true
  try { await api.post(f.id ? `/documents/${f.id}` : '/documents', fd); docForm.value = null; ui.toast('حُفظت الوثيقة', 'ok'); load360() }
  catch (x) { alertBox(errMsg(x), { icon: 'error' }) } finally { saving.value = false }
}
async function removeDoc(dd) {
  if (!await confirmBox(`حذف وثيقة «${dd.type}» ${dd.number || ''}؟`, { title: 'حذف الوثيقة', confirmText: 'حذف' })) return
  try { await api.delete(`/documents/${dd.id}`); ui.toast('حُذفت الوثيقة', 'ok'); load360() } catch (x) { alertBox(errMsg(x), { icon: 'error' }) }
}
async function saveCareer() {
  const f = careerForm.value
  if (!f.occurred_on) return ui.toast('التاريخ إلزامي', 'warn')
  saving.value = true
  try { await api.post(`/employees/${props.id}/career`, f); careerForm.value = null; ui.toast('سُجّلت الحركة', 'ok'); load() }
  catch (x) { alertBox(errMsg(x), { icon: 'error' }) } finally { saving.value = false }
}
async function removeCareer(c) {
  if (!await confirmBox(`حذف حركة «${c.type}» بتاريخ ${c.occurred_on}؟`, { confirmText: 'حذف' })) return
  try { await api.delete(`/employees/${props.id}/career/${c.id}`); load() } catch (x) { alertBox(errMsg(x), { icon: 'error' }) }
}
const resetting = ref(false)
async function resetPassword() {
  if (!await confirmBox(`إرسال رابط تفعيل/إعادة تعيين لـ ${e.value.name}؟ الرابط صالح 72 ساعة ويختار به كلمة مروره بنفسه.`)) return
  resetting.value = true
  try { const r = await api.post(`/users/${e.value.user.id}/invite`); creds.value = { login: r.login, inviteUrl: r.invite_url, email: r.email || '', emailed: !!r.emailed, title: 'دعوة الدخول' } }
  catch (x) { await alertBox(x.message || 'تعذر الإرسال', { icon: 'error' }) } finally { resetting.value = false }
}
const onBehalf = ref(false)
const custodyId = ref(null)
const period = ref(curYM())
const att = ref(null)
const LT_ICON = { annual: 'leave', sick: 'sick', emergency: 'emergency', maternity: 'maternity', marriage: 'marriage', bereavement: 'bereavement', unpaid: 'money' }
const tabs = [['info', 'البيانات'], ['contract', 'العقد والأجر'], ['att', 'الحضور والانصراف'], ['lv', 'الإجازات'], ['perf', 'الأداء والتقارير'], ['360', 'الملف الشامل'], ['career', 'المسار الوظيفي']]
const x = ref(null)
async function load360() { x.value = await api.get(`/employees/${props.id}/360`) }
watch(tab, (v) => { if ((v === '360' || v === 'perf') && !x.value) load360(); if (v === 'lv') loadLedger() })
const e = computed(() => d.value.employee)
const annual = computed(() => d.value.balances.find((b) => b.type === 'annual'))
const opt = (list, label = 'name') => () => (list() || []).map((x) => ({ value: x.id, label: x[label] }))
const infoRows = computed(() => {
  const x = e.value; const m = auth.meta || {}
  return [
    { label: 'الاسم', key: 'name', text: x.name, value: x.name },
    { label: 'الاسم بالإنجليزية', key: 'name_en', text: x.name_en, value: x.name_en, ltr: true },
    { label: 'الشركة القانونية', key: auth.isAdmin ? 'company_id' : null, text: x.company?.name, value: x.company_id, type: 'select', options: opt(() => m.companies) },
    { label: 'الوظيفة', key: 'job_title_id', text: x.job_title?.name, value: x.job_title_id, type: 'select', options: opt(() => m.job_titles) },
    { label: 'الإدارة', key: 'dept', text: x.dept, value: x.dept, list: { id: 'dept-list', items: m.departments || [] } },
    { label: 'المدير المباشر', key: 'manager_id', text: x.manager?.name, value: x.manager_id, type: 'select', options: () => staff.value.filter((s) => s.value !== x.id) },
    { label: 'نوع التعاقد', key: 'contract_type', text: contractLabel(x.contract_type, m), value: x.contract_type, type: 'select', options: () => (m.lookups?.contract_types || []).map((c) => ({ value: c.code, label: c.name })) },
    { label: 'التحضير', text: x.punch_required ? (x.punch_anywhere ? 'مطلوب من أي مكان' : 'مطلوب' + (x.geo_tolerance ? ` (+${x.geo_tolerance} م هامش)` : '')) : 'معفى' },
    { label: 'جدول الدوام', key: 'schedule_id', text: (x.schedule?.name || '—') + (x.work_hours && Object.keys(x.work_hours).length ? ' · ساعات مخصصة: ' + Object.entries(x.work_hours).map(([dd, h]) => `${DAYS_AR[+dd]} ${h[0]}–${h[1]}`).join('، ') : ''), value: x.schedule_id, type: 'select', options: opt(() => m.schedules) },
    { label: 'مواقع التحضير', text: x.locations?.map((l) => l.name).join('، ') },
    { label: 'الساعة المرنة', key: 'flex_enabled', text: x.flex_enabled ? 'مفعّلة' : 'غير مفعّلة', value: !!x.flex_enabled, type: 'bool', yes: 'مفعّلة', no: 'غير مفعّلة' },
    { label: 'الجنسية', key: 'nationality', text: nationalityLabel(x.nationality, auth.user?.locale), value: x.nationality, type: 'select', options: () => countryOptions(auth.user?.locale) },
    { label: 'الجوال', key: 'phone', text: x.phone, value: x.phone, type: 'tel', ltr: true },
    { label: 'البريد', key: x.email_enabled ? 'email' : null, text: x.email_enabled ? x.email : 'معطّل، الدخول برقم الموظف', value: x.email, type: 'email', ltr: true },
    { label: 'تاريخ الالتحاق', key: 'hire_date', text: fmtD(x.hire_date), value: x.hire_date, type: 'date' },
    { label: 'التصنيف', text: d.value.tier?.name },
    ...(d.value.salary ? [{ label: 'الراتب الشهري الإجمالي', text: money(d.value.salary.gross) + ' ر.س' }] : []),
    { label: 'قواعد نهاية الخدمة السعودية', key: 'eosb_saudi', text: x.eosb_saudi ? 'تنطبق' : 'لا تنطبق', value: !!x.eosb_saudi, type: 'bool', yes: 'تنطبق', no: 'لا تنطبق' },
    { label: 'التأمين الطبي', text: x.insurance ? `فئة ${x.insurance.class} · ${x.insurance.dependents || 0} تابعين` : '—' },
    { label: 'حساب الدخول', text: x.user ? `${x.user.login} · آخر دخول ${x.user.last_login_at ? fmtDT(x.user.last_login_at) : '—'}` : 'لا يوجد' },
  ]
})
// in-place editing: only the changed field is saved (with the record's required fields)
const MGR_FIELDS = ['dept', 'phone', 'email', 'name_en', 'schedule_id', 'flex_enabled']
const staff = ref([])
const canInline = (r) => !!r.key && d.value.can?.edit && (d.value.can.hr || MGR_FIELDS.includes(r.key))
async function onStart(r) {
  if (r.key === 'manager_id' && !staff.value.length) staff.value = ((await api.get('/employees', { params: { per_page: 1000 } })).data || []).map((x) => ({ value: x.id, label: x.name }))
}
async function saveField(key, value) {
  const x = e.value
  await api.put(`/employees/${x.id}`, { emp_no: x.emp_no, name: x.name, company_id: x.company_id, hire_date: x.hire_date, contract_type: x.contract_type, [key]: value })
  await load()
  ui.toast('حُفظ التعديل', 'ok')
}
const contractRows = computed(() => {
  const x = e.value; const m = auth.meta || {}
  return [
    { label: 'نوع التعاقد', key: 'contract_type', text: contractLabel(x.contract_type, m), value: x.contract_type, type: 'select', options: () => (m.lookups?.contract_types || []).map((c) => ({ value: c.code, label: c.name })) },
    { label: 'تاريخ الالتحاق', key: 'hire_date', text: fmtD(x.hire_date), value: x.hire_date, type: 'date' },
    ...(x.part_ratio && x.part_ratio < 1 ? [{ label: 'نسبة الدوام الجزئي', key: 'part_ratio', text: x.part_ratio, value: x.part_ratio, type: 'number' }] : []),
    { label: 'الآيبان', key: 'bank_iban', text: x.bank_iban || '—', value: x.bank_iban, ltr: true, placeholder: 'SA0000000000000000000000' },
    { label: 'تفعيل السلف', key: 'advances_enabled', text: x.advances_enabled ? 'مسموح بطلب سلفة' : 'غير مسموح', value: !!x.advances_enabled, type: 'bool', yes: 'مسموح بطلب سلفة', no: 'غير مسموح' },
    { label: 'قواعد نهاية الخدمة السعودية', key: 'eosb_saudi', text: x.eosb_saudi ? 'تنطبق' : 'لا تنطبق', value: !!x.eosb_saudi, type: 'bool', yes: 'تنطبق', no: 'لا تنطبق' },
    ...(d.value.salary?.gosi ? [{
      label: 'التأمينات الاجتماعية', key: 'gosi_scheme', value: x.gosi_scheme || '', type: 'select', options: () => GOSI_OPTS,
      text: ((g) => `${g.scheme_label}${g.automatic ? ' (تلقائي)' : ''}` + (g.scheme === 'exempt' ? '' : ` · على الموظف ${g.employee_rate}% = ${money(g.employee)} · على المنشأة ${g.employer_rate}% = ${money(g.employer)} · من أجر خاضع ${money(g.base)}`))(d.value.salary.gosi),
    }] : []),
    { label: 'التصنيف', text: d.value.tier?.name },
  ]
})
const GOSI_OPTS = [{ value: '', label: 'تلقائي (حسب الجنسية وتاريخ المباشرة)' }, { value: 'old', label: 'سعودي — النظام القديم' }, { value: 'new', label: 'سعودي — النظام الجديد' }, { value: 'expat', label: 'غير سعودي' }, { value: 'exempt', label: 'غير مشمول' }]
// pay change: raised as an approval request (basic -> salary_change, other components -> allowance)
const payEdit = ref(null)
async function savePay() {
  const f = payEdit.value
  if (f.id === 'new' && !f.name) return ui.toast('اكتب اسم البدل', 'warn')
  if (f.amount === null || f.amount === '' || isNaN(f.amount) || f.amount < 0) return ui.toast('أدخل مبلغًا صحيحًا', 'warn')
  if (!f.reason || f.reason.length < 3) return ui.toast('سبب التعديل إلزامي', 'warn')
  saving.value = true
  try {
    const basic = f.key === 'basic'
    await api.post('/requests', { type: basic ? 'salary_change' : 'allowance', employee_id: e.value.id, after: f.amount, reason: f.reason, effective: f.effective || today(), ...(basic ? {} : { component: f.key, name: f.name }) })
    payEdit.value = null
    await load()
    ui.toast('رُفع طلب تعديل الأجر للاعتماد، ويُطبَّق بعد موافقة الرئيس التنفيذي للمجموعة', 'ok', 6000)
  } catch { /* message already shown */ } finally { saving.value = false }
}
async function load() { d.value = await api.get(`/employees/${props.id}`) }
async function kudos() { const text = await promptBox('اكتب إشادتك (تصل للزميل وتُحتسب في نقاطه):'); if (!text) return; try { await api.post('/kudos', { employee_id: e.value.id, text }); await alertBox('أُرسلت الإشادة', { icon: 'success', title: 'تم' }) } catch (err) { await alertBox(err.message || 'تعذر الإرسال') } }
async function loadAtt() { if (!auth.canView('ATT') && +props.id !== auth.emp?.id) return; att.value = await api.get(`/attendance/employee/${props.id}`, { params: { period: period.value } }).catch(() => null) }
onMounted(() => { load(); loadAtt() })
</script>

<style scoped>
.lvcard { position: relative; }
.lvedit { position: absolute; bottom: 10px; inset-inline-end: 12px; }
.info-t td.editable { cursor: pointer; }
.info-t th, .info-t td { vertical-align: middle; }
.info-t td.editable:hover { background: var(--soft, #F1F3F9); }
.info-t .pen { opacity: .35; margin-inline-start: 6px; vertical-align: middle; }
.info-t td.editable:hover .pen { opacity: .9; color: var(--blue, #1C84C6); }
</style>
