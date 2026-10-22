<template>
  <div class="page-h">
    <div><h1>{{ t('التقييم والأوزان') }}</h1><p>أربعة محاور بأوزان قابلة للتعديل: الإنجاز · الحضور · التطوير · الانضباط — تُحسب آليًا من التقرير الشهري وسجل الحضور والتطوير والمخالفات، وتُنشر مع اعتماد التقرير، ويحق للموظف الاعتراض خلال المهلة</p></div>
    <div class="acts"><MonthPicker v-if="tab !== 'annual' && tab !== 'weights'" v-model="period" @change="load" /><input v-else-if="tab === 'annual'" v-model.number="year" type="number" class="in" style="width:110px" @change="load" /></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <div class="notice info small mb"><Icon name="info" :size="14" /> {{ TAB_HELP[tab] }}</div>
  <template v-if="tab === 'my'">
    <div v-if="!my"><div class="skel" v-for="i in 3" :key="i" /></div>
    <template v-else>
      <div class="grid g4 mb">
        <Kpi label="آخر تقييم" :value="my.monthly[0]?.score ?? '—'" :hint="my.monthly[0] ? `${fmtYM(my.monthly[0].period)} · ${my.monthly[0].grade_label}` : 'لم يُنشر بعد'" :color="my.monthly[0] && my.monthly[0].score >= my.template.pass_score ? 'g' : 'r'" />
        <Kpi label="المعدل السنوي" :value="my.annual?.score ?? '—'" :hint="my.annual ? (my.annual.insufficient ? `${my.annual.months} من ${my.annual.min_months} أشهر مطلوبة` : `${my.annual.months} أشهر · ${my.annual.grade.grade}${my.annual.partial ? ' · من الأشهر المتاحة' : ''}`) : ''" color="b" />
        <Kpi label="حد القبول" :value="my.template.pass_score + '%'" :hint="my.template.name" color="y" />
        <Kpi label="الأوزان" :value="`${my.template.weights.achieve}/${my.template.weights.attend}/${my.template.weights.dev}/${my.template.weights.disc}`" hint="إنجاز / حضور / تطوير / انضباط" color="p" />
      </div>
      <div class="card">
        <h3>تقييماتي الشهرية</h3>
        <div v-if="my.badges?.length" class="row mb"><span v-for="b in my.badges" :key="b.code" class="pill ok"><Icon name="check-circle" :size="13" /> {{ b.label }} ×{{ b.count }} · {{ b.points }} نقطة</span><span class="pill navy">المجموع {{ my.badges.reduce((s, b) => s + b.points, 0) }} نقطة</span></div>
        <div v-if="my.kudos?.length" class="notice info small"><b>إشادات الزملاء:</b> <span v-for="(k, i) in my.kudos" :key="i">«{{ k.text }}» — {{ k.from }} ({{ k.period }})<template v-if="i < my.kudos.length - 1"> · </template></span></div>
        <div v-if="!my.monthly.length" class="empty">لا تقييمات منشورة</div>
        <div v-dt v-else class="tw"><table><thead><tr><th>الشهر</th><th>الإنجاز</th><th>الحضور</th><th>التطوير</th><th>الانضباط</th><th>الدرجة</th><th>التقدير</th><th>اعتراض</th><th /></tr></thead>
          <tbody><tr v-for="e in my.monthly" :key="e.id">
            <td><b>{{ fmtYM(e.period) }}</b></td><td>{{ e.axes.achieve }}</td><td>{{ e.axes.attend ?? 'معفى' }}</td><td>{{ e.axes.dev }}</td><td>{{ e.axes.disc }}</td>
            <td><b class="num" :class="(e.details?.pass ?? e.score >= my.template.pass_score) ? 'ok' : 'bad'">{{ e.score }}</b></td><td><span class="pill" :class="(e.details?.pass ?? e.score >= my.template.pass_score) ? 'ok' : 'bad'">{{ e.grade }} · {{ e.grade_label }}</span></td>
            <td class="small">{{ OBJ[e.objection_status] }}<div v-if="e.can_object && e.objection?.deadline" class="tiny muted">حتى {{ fmtD(e.objection.deadline) }}</div><div v-if="e.objection_status === 'open' && e.objection?.decision_due" class="tiny" :class="e.objection.decision_overdue ? 'bad' : 'muted'">يُبت فيه حتى {{ fmtD(e.objection.decision_due) }}</div></td><td><button class="btn ghost xs" @click="openDetail(auth.emp.id, e.period)">كيف احتُسبت؟</button></td>
          </tr></tbody></table></div>
      </div>
      <div v-if="my.annual_published?.length" class="card">
        <h3>تقييماتي السنوية المنشورة <span class="sub">يحق الاعتراض مرة واحدة خلال 7 أيام عمل من النشر، ويُبت فيه خلال 10 أيام عمل (§76)</span></h3>
        <div v-dt class="tw"><table><thead><tr><th>السنة</th><th>النوع</th><th>الدرجة</th><th>التقدير</th><th>نُشر</th><th>الاعتراض</th><th /></tr></thead>
          <tbody><tr v-for="a in my.annual_published" :key="a.id"><td class="num"><b>{{ a.year }}</b></td><td class="small">{{ a.kind === 'contract' ? 'السنة العقدية' : 'السنة الميلادية' }}</td><td class="num"><b>{{ a.score }}</b></td><td>{{ a.grade }} · {{ a.grade_label }}</td><td class="small">{{ fmtD(a.published_at) }}</td>
            <td class="small">{{ OBJ[a.objection_status] }}<div v-if="a.objection_decision" class="tiny muted">{{ a.objection_decision }}</div><div v-if="a.can_object" class="tiny muted">حتى {{ fmtD(a.objection.deadline) }}</div><div v-if="a.objection_status === 'open' && a.objection?.decision_due" class="tiny muted">يُبت فيه حتى {{ fmtD(a.objection.decision_due) }}</div></td>
            <td><button v-if="a.can_object" class="btn dan xs" @click="objectAnnual(a)">اعتراض</button></td></tr></tbody></table></div>
      </div>
    </template>
  </template>

  <template v-else-if="tab === 'monthly'">
    <div class="grid g4 mb">
      <Kpi label="الموظفون" :value="rows.length" color="b" /><Kpi label="منشور" :value="rows.filter((r) => r.published).length" color="g" />
      <Kpi label="دون حد القبول" :value="rows.filter((r) => !r.grade.pass).length" color="r" /><Kpi label="متوسط الدرجة" :value="avg" color="y" />
    </div>
    <div class="card">
      <h3>التقييم الشهري <span class="sub">معاينة حيّة تُحسب الآن، والمنشور ما اعتُمد مع التقرير</span><span class="acts"><button class="btn ghost xs" @click="exportCsv"><Icon name="download" :size="14" /> CSV</button></span></h3>
      <div v-dt class="tw"><table><thead><tr><th>الموظف</th><th>الشركة</th><th>التقرير</th><th>إنجاز</th><th>حضور</th><th>تطوير</th><th>انضباط</th><th>الدرجة (حيّة)</th><th>المنشور</th><th /></tr></thead>
        <tbody><tr v-for="r in rows" :key="r.employee.id">
          <td><b>{{ r.employee.name }}</b></td><td class="small">{{ r.company }}</td><td class="small">{{ REP[r.report_status] || r.report_status }}</td>
          <td>{{ r.axes.achieve }}</td><td>{{ r.exempt_attendance ? 'معفى' : r.axes.attend }}</td><td>{{ r.axes.dev }}</td><td>{{ r.axes.disc }}</td>
          <td><b class="num" :class="r.grade.pass ? 'ok' : 'bad'">{{ r.score }}</b> <span class="tiny">{{ r.grade.grade }}</span></td>
          <td><span v-if="r.published" class="pill" :class="r.published.objection === 'open' ? 'warn' : 'ok'">{{ r.published.score }} {{ r.published.grade }}{{ r.published.objection === 'open' ? ' · اعتراض' : '' }}</span><span v-else class="muted">—</span></td>
          <td class="row" style="gap:4px;flex-wrap:nowrap"><button class="btn ghost xs" @click="openDetail(r.employee.id, r.period)">تفاصيل</button><button v-if="!r.published && canPublish" class="btn sec xs" @click="publish(r)">نشر</button></td>
        </tr></tbody></table></div>
    </div>
  </template>

  <template v-else-if="tab === 'annual'">
    <div class="card">
      <h3>التقييم السنوي {{ year }} <span class="sub">اللائحة §73: 70% متوسط الأشهر المنشورة (يُستبعد الشهر الذي عمل فيه أقل من 10 أيام) + 30% تقييم الأهداف السنوية · المكافأة §85: أشهر × أجر ديسمبر الشامل × نسبة الخدمة × معامل التمويل</span>
        <span class="acts"><select v-model="annualKind" class="in" style="width:auto" @change="load"><option value="fiscal">السنة الميلادية (يناير–ديسمبر)</option><option value="contract">السنة العقدية (من المباشرة)</option></select><button v-if="canPublish" class="btn sec xs" :class="{ loading: busy }" @click="publishAnnual">نشر التقييم السنوي</button></span></h3>
      <div v-dt class="tw"><table><thead><tr><th>الموظف</th><th>الشركة</th><th>الأشهر</th><th>إنجاز</th><th>حضور</th><th>تطوير</th><th>انضباط</th><th>الأهداف السنوية</th><th>المعدل</th><th>التقدير</th><th v-if="annualKind === 'fiscal'">المكافأة</th><th v-if="annualKind === 'fiscal'">العلاوة</th><th /></tr></thead>
        <tbody><tr v-for="r in annual" :key="r.employee.id">
          <td><b>{{ r.employee.name }}</b></td><td class="small">{{ r.company }}</td>
          <td v-if="r.annual?.insufficient" :colspan="annualKind === 'fiscal' ? 10 : 8" class="muted">{{ r.annual.months }} أشهر فقط — يلزم {{ r.annual.min_months }} لاحتساب المعدل السنوي</td>
          <template v-else-if="r.annual"><td>{{ r.annual.months }}<span v-if="r.annual.excluded?.length" class="tiny muted" :title="r.annual.excluded.join(', ')"> (استُبعد {{ r.annual.excluded.length }})</span></td><td>{{ r.annual.axes.achieve }}</td><td>{{ r.annual.axes.attend }}</td><td>{{ r.annual.axes.dev }}</td><td>{{ r.annual.axes.disc }}</td>
            <td><span v-if="r.annual.goals" class="num">{{ r.annual.goals.score }} <span class="tiny" :class="r.annual.goals.approved ? 'muted' : 'warn'">({{ r.annual.goals.approved ? 'معتمد' : 'مقترح — لا يُحتسب قبل اعتماد الرئيس التنفيذي' }})</span></span><span v-else class="muted tiny">لم تُقيَّم</span></td>
            <td><b class="num">{{ r.annual.score }}</b><span v-if="r.annual.partial" class="pill warn tiny">جزئي</span><div class="tiny muted">{{ r.annual.formula }}</div>
              <div v-if="r.annual.published" class="tiny muted">منشور {{ fmtD(r.annual.published.published_at) }}<template v-if="r.annual.published.score !== r.annual.score"> ({{ r.annual.published.score }})</template></div>
              <div v-if="r.objection" class="tiny" :class="r.objection.status === 'open' ? 'bad' : 'muted'">اعتراض: {{ OBJ[r.objection.status] }}<template v-if="r.objection.status === 'open'"> · يُبت فيه حتى {{ fmtD(r.objection.decision_due) }}</template></div></td><td><span class="pill" :class="r.annual.grade.pass ? 'ok' : 'bad'">{{ r.annual.grade.grade }} · {{ r.annual.grade.label }}</span></td>
            <td v-if="annualKind === 'fiscal'"><template v-if="r.bonus"><b class="num">{{ r.bonus.eligible ? money(r.bonus.amount, 0) : '—' }}</b><div class="tiny muted">{{ r.bonus.months }} شهر × {{ money(r.bonus.wage, 0) }} × {{ Math.round(r.bonus.service_ratio * 100) }}%{{ r.bonus.funding_factor !== 1 ? ` × ${r.bonus.funding_factor}` : '' }}</div></template><span v-else class="muted">—</span></td>
            <td v-if="annualKind === 'fiscal'"><span v-if="r.bonus" class="small">{{ r.bonus.raise_range[0] }}–{{ r.bonus.raise_range[1] }}%{{ r.bonus.promotion_eligible ? ' · مؤهل للترشيح' : '' }}</span><div v-if="r.bonus?.promotion_blockers?.length" class="tiny bad">لا ترشيح: {{ r.bonus.promotion_blockers.join('، ') }}</div></td>
            <td class="row" style="gap:4px;flex-wrap:nowrap"><button v-if="auth.isApprover" class="btn ghost xs" @click="goalsForm = { employee: r.employee, score: r.annual.goals?.score ?? 90, note: '' }">تقييم الأهداف</button><button v-if="r.objection?.can_decide" class="btn dan xs" @click="annualObj = { ...r.objection, employee: r.employee, note: '', score: null }">البت في الاعتراض</button></td></template>
          <td v-else colspan="7" class="muted">لا تقييمات منشورة</td>
        </tr></tbody></table></div>
    </div>
  </template>

  <template v-else-if="tab === 'sim'">
    <div class="card">
      <h3>محاكاة «ماذا لو» <span class="sub">حرّك الأوزان وشاهد أثرها على الفريق قبل اعتمادها — المجموع يجب أن يساوي 100</span></h3>
      <div class="grid g4 mb">
        <div v-for="k in AXES" :key="k" class="fg"><label>{{ AXIS[k] }}: <b>{{ sim[k] }}</b></label><input v-model.number="sim[k]" type="range" min="0" max="70" step="5" /></div>
      </div>
      <div class="row mb"><span class="pill" :class="simTotal === 100 ? 'ok' : 'bad'">المجموع {{ simTotal }}</span><button class="btn sec sm" :disabled="simTotal !== 100" @click="runSim">احسب</button><button v-if="auth.has('super')" class="btn pri sm" :disabled="simTotal !== 100" @click="saveSimAsTemplate">اعتماد كأوزان جديدة</button></div>
      <div v-dt v-if="simRows.length" class="tw"><table><thead><tr><th>الموظف</th><th>الدرجة الحالية</th><th>بالأوزان المقترحة</th><th>الفرق</th></tr></thead>
        <tbody><tr v-for="r in simRows" :key="r.employee.id"><td><b>{{ r.employee.name }}</b></td><td>{{ r.base }}</td><td><b>{{ r.score }}</b> {{ r.grade.grade }}</td><td :class="r.score - r.base >= 0 ? 'ok' : 'bad'">{{ (r.score - r.base).toFixed(2) }}</td></tr></tbody></table></div>
    </div>
  </template>

  <template v-else>
    <div v-for="tp in templates" :key="tp.id" class="card">
      <h3>{{ tp.name }} <span class="sub">v{{ tp.version }} · {{ SCOPE[tp.scope] }} · حد القبول {{ tp.pass_score }}%</span><span v-if="auth.has('super')" class="acts"><button class="btn ghost xs" @click="tplForm = JSON.parse(JSON.stringify({ ...tp, gradesTxt: tp.grades.map((g) => g.join(' | ')).join('\n'), reason: '' }))">تعديل</button></span></h3>
      <div class="grid g4">
        <div v-for="k in AXES" :key="k" class="card" style="margin:0">
          <b>{{ AXIS[k] }} — {{ tp.weights[k] }}</b>
          <div v-for="(v, sk) in tp.sub[k]" :key="sk" class="small row" style="justify-content:space-between"><span>{{ SUB[sk] || sk }}</span><b>{{ v }}</b></div>
        </div>
      </div>
      <div class="row mt"><span v-for="g in tp.grades" :key="g[1]" class="pill gray">≥{{ g[0] }} {{ g[1] }} {{ g[2] }}</span><span class="pill bad">&lt;{{ tp.pass_score }} غير مقبول — خطة تطوير</span></div>
    </div>
  </template>

  <Modal v-if="goalsForm" :title="`تقييم الأهداف السنوية ${year} — ${goalsForm.employee.name}`" @close="goalsForm = null">
    <p class="small muted">يقيّم المشرف المباشر مدى تحقق الأهداف السنوية والإنجاز التراكمي وجودة النتائج وأثرها (30% من النتيجة السنوية)، ويعتمده الرئيس التنفيذي للشركة.</p>
    <div class="fr"><div class="fg"><label>الدرجة من 100 *</label><input v-model.number="goalsForm.score" type="number" min="0" max="100" class="in" /></div></div>
    <div class="fg"><label>ملاحظة</label><textarea v-model="goalsForm.note" class="in" rows="3" /></div>
    <template #foot><button class="btn sec" @click="goalsForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveGoals">{{ auth.has('super', 'ceo') ? 'اعتماد' : 'حفظ المقترح' }}</button></template>
  </Modal>

  <Modal v-if="annualObj" :title="`اعتراض على التقييم السنوي — ${annualObj.employee.name}`" @close="annualObj = null">
    <div class="small mb"><b>نص الاعتراض:</b> {{ annualObj.text }}</div>
    <div class="tiny muted mb">يُبت فيه حتى {{ fmtD(annualObj.decision_due) }} (10 أيام عمل) بقرار مكتوب ومسبَّب.</div>
    <div class="fr"><div class="fg"><label>الدرجة بعد القبول (اختياري)</label><input v-model.number="annualObj.score" type="number" min="0" max="100" class="in" /></div><div class="fg"><label>القرار المكتوب *</label><input v-model="annualObj.note" class="in" /></div></div>
    <template #foot><button class="btn sec" @click="annualObj = null">إلغاء</button><button class="btn dan" :class="{ loading: busy }" @click="decideAnnual('rejected')">رفض الاعتراض</button><button class="btn okb" :class="{ loading: busy }" @click="decideAnnual('accepted')">قبول الاعتراض</button></template>
  </Modal>

  <Modal v-if="detail" :title="`تقييم ${fmtYM(detail.period)} — ${detail.employee.name}`" size="wide" @close="detail = null">
    <div v-if="detail.computed.flag" class="notice mb" :class="FLAG[detail.computed.flag.color][0]"><b>حالة الشهر: {{ FLAG[detail.computed.flag.color][1] }}</b><span v-if="detail.computed.flag.reasons?.length"> — {{ detail.computed.flag.reasons.join(' · ') }}</span></div>
    <div class="grid g5 mb">
      <Kpi v-for="k in AXES" :key="k" :label="AXIS[k]" :value="detail.computed.axes[k] ?? 'معفى'" :hint="`من ${detail.computed.weights[k]}`" color="b" />
      <Kpi label="الدرجة" :value="detail.computed.score" :hint="detail.computed.grade.label + (detail.computed.bonus ? ` · +${detail.computed.bonus} نقاط` : '') + (detail.computed.penalty ? ` · −${detail.computed.penalty} ${detail.computed.parts?.report?.submitted ? 'تأخر التقرير الشهري' : 'لم يُرفع التقرير الشهري'} (§35)` : '')" :color="detail.computed.grade.pass ? 'g' : 'r'" />
    </div>
    <div class="grid g2">
      <div v-for="k in AXES" :key="k" class="card" style="margin:0"><b>{{ AXIS[k] }}</b>
        <div v-if="detail.computed.parts[k]" class="small"><div v-for="(v, sk) in detail.computed.parts[k]" :key="sk" class="row" style="justify-content:space-between"><span>{{ SUB[sk] || sk }}</span><b>{{ typeof v === 'object' ? JSON.stringify(v) : (REP[v] || v) }}</b></div></div>
        <div v-else class="muted small">معفى من الحضور (غير مطالب بالبصمة) — أُعيد توزيع الوزن على المحاور الأخرى</div>
      </div>
    </div>
    <div v-if="detail.can_rate || detail.rating?.quality != null" class="card mt" style="margin-bottom:0">
      <h3>تقييم المدير المباشر <span class="sub">جودة العمل تُحتسب ضمن محور الإنجاز، والتعاون ضمن محور الانضباط</span></h3>
      <div v-if="detail.rating?.by" class="small muted mb">آخر تقييم: {{ detail.rating.by }} · {{ fmtDT(detail.rating.at) }}</div>
      <div class="fr">
        <div class="fg"><label>جودة العمل (من {{ detail.rating.max_quality }})</label><input v-model.number="rateForm.quality" type="number" min="0" :max="detail.rating.max_quality" step="0.5" class="in" :disabled="!detail.can_rate" /></div>
        <div class="fg"><label>التعاون والسلوك (من {{ detail.rating.max_coop }})</label><input v-model.number="rateForm.coop" type="number" min="0" :max="detail.rating.max_coop" step="0.5" class="in" :disabled="!detail.can_rate" /></div>
      </div>
      <div class="fg"><label>ملاحظات المدير</label><textarea v-model="rateForm.note" class="in" rows="2" :disabled="!detail.can_rate" /></div>
      <div v-if="detail.can_rate" class="row"><span class="tiny muted">يمكن التعديل حتى نشر التقييم.</span><span class="spacer" /><button class="btn pri sm" :class="{ loading: busy }" @click="saveRating"><Icon name="check" :size="14" /> حفظ تقييم المدير</button></div>
    </div>
    <div v-if="detail.published" class="notice info mt">منشور بتاريخ {{ fmtDT(detail.published.published_at) }} · الدرجة {{ detail.published.score }} ({{ detail.published.grade }}) · الاعتراض: {{ OBJ[detail.published.objection_status] }}<div v-if="detail.published.objection_text" class="small">نص الاعتراض: {{ detail.published.objection_text }}</div><div v-if="detail.published.objection_decision" class="small">القرار: {{ detail.published.objection_decision }}</div></div>
    <div v-if="detail.objection && (detail.can_object || detail.published?.objection_status === 'open')" class="tiny muted mt">مهلة الاعتراض: 7 أيام عمل من النشر (حتى {{ fmtD(detail.objection.deadline) }})<template v-if="detail.objection.decision_due"> · يُبت فيه خلال 10 أيام عمل (حتى {{ fmtD(detail.objection.decision_due) }})</template> · يبت فيه من لم يعتمد التقييم ولم يراجعه (§76)</div>
    <div v-if="detail.computed.parts?.disc?.coop_pending" class="notice warn small mt">التعاون لم يقدّره المشرف المباشر بعد — القيمة المعروضة مؤقتة.</div>
    <div v-if="detail.can_object" class="fg mt"><label>نص الاعتراض</label><textarea v-model="objText" class="in" rows="3" /></div>
    <div v-if="detail.can_decide_objection" class="fr mt"><div class="fg"><label>الدرجة بعد القبول (اختياري)</label><input v-model.number="objScore" type="number" min="0" max="100" class="in" /></div><div class="fg"><label>القرار المكتوب *</label><input v-model="objNote" class="in" /></div></div>
    <template #foot>
      <button class="btn sec" @click="detail = null">إغلاق</button>
      <button v-if="detail.can_object" class="btn dan" :class="{ loading: busy }" @click="object">تقديم اعتراض</button>
      <template v-if="detail.can_decide_objection"><button class="btn dan" :class="{ loading: busy }" @click="decideObj('rejected')">رفض الاعتراض</button><button class="btn okb" :class="{ loading: busy }" @click="decideObj('accepted')">قبول الاعتراض</button></template>
    </template>
  </Modal>

  <Modal v-if="tplForm" :title="`الأوزان والتقديرات: ${tplForm.name}`" size="wide" @close="tplForm = null">
    <div class="fr3">
      <div class="fg"><label>الاسم</label><input v-model="tplForm.name" class="in" /></div>
      <div class="fg"><label>حد القبول %</label><input v-model.number="tplForm.pass_score" type="number" class="in" /></div>
      <div class="fg"><label>النطاق</label><select v-model="tplForm.scope" class="in"><option v-for="(l, k) in SCOPE" :key="k" :value="k">{{ l }}</option></select></div>
    </div>
    <div class="grid g4">
      <div v-for="k in AXES" :key="k" class="card" style="margin:0"><div class="fg"><label><b>{{ AXIS[k] }}</b> — الوزن</label><input v-model.number="tplForm.weights[k]" type="number" class="in" /></div>
        <div v-for="(v, sk) in tplForm.sub[k]" :key="sk" class="fg"><label class="tiny">{{ SUB[sk] || sk }}</label><input v-model.number="tplForm.sub[k][sk]" type="number" step="0.5" class="in" /></div></div>
    </div>
    <div class="fg mt"><label>التقديرات (سطر لكل تقدير: الحد الأدنى | الرمز | الوصف)</label><textarea v-model="tplForm.gradesTxt" class="in" rows="6" /></div>
    <div class="fg"><label>سبب التعديل *</label><input v-model="tplForm.reason" class="in" /></div>
    <p class="tiny muted">مجموع الأوزان: {{ AXES.reduce((s, k) => s + (Number(tplForm.weights[k]) || 0), 0) }} / 100 · التعديل يُصدر نسخة جديدة ولا يعيد احتساب الأشهر المعتمدة.</p>
    <template #foot><button class="btn sec" @click="tplForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="saveTpl">حفظ نسخة جديدة</button></template>
  </Modal>
</template>

<script setup>
import { exportXlsx } from '../excel'
import { confirmBox, promptBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { csv, download, fmtD, fmtDT, fmtYM, money } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const AXES = ['achieve', 'attend', 'dev', 'disc']
const AXIS = { achieve: 'الإنجاز', attend: 'الحضور', dev: 'التطوير', disc: 'الانضباط' }
const SUB = { targets: 'المستهدفات', quality: 'جودة المخرجات', presence: 'الحضور', noLate: 'عدم التأخر', dailyNote: 'المنجز اليومي', complete: 'إكمال البرنامج', interact: 'التفاعل', apply: 'التطبيق', hrScore: 'تقدير الموارد البشرية', weekly: 'التقارير الأسبوعية', coop: 'التعاون', conduct: 'السلوك', pct: 'نسبة الإنجاز الموزونة', report_status: 'حالة التقرير', absent: 'أيام الغياب', late: 'مرات التأخر', no_late: 'عدم التأخر', daily_note: 'المنجز اليومي', hr_score: 'تقدير الموارد البشرية', records: 'أنشطة معتمدة', weeks: 'الأسابيع',
  coop_pending: 'التعاون بانتظار المشرف', conduct_violations: 'مخالفات سلوكية', first_submitted_at: 'أول رفع', after_month_end: 'رُفع بعد نهاية الشهر', penalty: 'الخصم', red_flag: 'علامة حمراء', submitted: 'مرفوع',
  points: 'نقاط الشهر', carry: 'مرحّل من الشهر السابق', total: 'المجموع المحتسب (حد 150)', level: 'المستوى', score: 'درجة المحور' }
const FLAG = { green: ['ok', 'أخضر — أداء جيد فأعلى'], orange: ['warn', 'برتقالي — متابعة وتوجيه'], red: ['bad', 'أحمر — غير مقبول، تبدأ المعالجة (§75)'] }
const REP = { none: 'لا تقرير', draft: 'مسودة', submitted: 'مرفوع', mgr_reviewed: 'راجعه المدير', hr_reviewed: 'راجعته الموارد', approved: 'معتمد', returned: 'مُرجَع' }
const OBJ = { none: '—', open: 'قيد النظر', accepted: 'قُبل', rejected: 'رُفض' }
const SCOPE = { group: 'المجموعة', company: 'شركة', job: 'وظيفة' }
const prevMonth = () => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }
const period = ref(prevMonth()); const year = ref(new Date().getFullYear())
const tabs = computed(() => [auth.emp && ['my', 'تقييماتي'], auth.isApprover && auth.canView('PRF') && ['monthly', 'التقييم الشهري'], auth.isApprover && auth.canView('PRF') && ['annual', 'التقييم السنوي'], auth.isApprover && auth.canView('PRF') && ['sim', 'محاكاة «ماذا لو»'], ['weights', 'الأوزان والتقديرات']].filter(Boolean))
const tab = ref(auth.emp ? 'my' : 'monthly')
const my = ref(null); const rows = ref([]); const annual = ref([]); const templates = ref([]); const detail = ref(null); const tplForm = ref(null)
const annualKind = ref('fiscal'); const goalsForm = ref(null); const annualObj = ref(null)
async function publishAnnual() {
  if (!await confirmBox(`نشر التقييم السنوي ${year.value} للموظفين في القائمة؟ يُشعر كل موظف وتبدأ مهلة الاعتراض (7 أيام عمل).`)) return
  busy.value = true
  try { annual.value = await api.get('/evaluation/annual', { params: { year: year.value, kind: annualKind.value, company_id: ui.company || undefined, publish: 1 } }); ui.toast('نُشر التقييم السنوي', 'ok') } finally { busy.value = false }
}
async function objectAnnual(a) {
  const text = await promptBox(`الاعتراض على التقييم السنوي ${a.year}`, { placeholder: 'اكتب أسباب الاعتراض' })
  if (!text) return
  await api.post(`/evaluations/${a.id}/object`, { text }); ui.toast('قُدّم الاعتراض', 'ok'); load()
}
async function decideAnnual(decision) {
  if (!annualObj.value.note?.trim()) return ui.toast('القرار المكتوب إلزامي', 'warn')
  busy.value = true
  try { await api.post(`/evaluations/${annualObj.value.id}/objection`, { decision, note: annualObj.value.note, score: annualObj.value.score || undefined }); ui.toast('سُجّل القرار', 'ok'); annualObj.value = null; load() } finally { busy.value = false }
}
async function saveGoals() {
  busy.value = true
  try { await api.post(`/evaluation/${goalsForm.value.employee.id}/goals`, { year: year.value, kind: annualKind.value, score: goalsForm.value.score, note: goalsForm.value.note }); ui.toast('حُفظ تقييم الأهداف', 'ok'); goalsForm.value = null; load() } catch (e) { ui.toast(e.message || 'تعذر الحفظ', 'bad') } finally { busy.value = false }
}
const sim = ref({ achieve: 50, attend: 20, dev: 20, disc: 10 }); const simRows = ref([]); const objText = ref(''); const objNote = ref(''); const objScore = ref(null); const busy = ref(false)
const simTotal = computed(() => AXES.reduce((s, k) => s + sim.value[k], 0))
const avg = computed(() => rows.value.length ? (rows.value.reduce((s, r) => s + r.score, 0) / rows.value.length).toFixed(1) : '—')
const canPublish = computed(() => auth.has('super', 'hr_mgr', 'hr_staff', 'ceo'))

async function load() {
  if (tab.value === 'my') my.value = await api.get('/evaluation/mine')
  else if (tab.value === 'monthly') rows.value = await api.get('/evaluation/monthly', { params: { period: period.value, company_id: ui.company || undefined } })
  else if (tab.value === 'annual') annual.value = await api.get('/evaluation/annual', { params: { year: year.value, kind: annualKind.value, company_id: ui.company || undefined } })
  else if (tab.value === 'weights' || tab.value === 'sim') { templates.value = await api.get('/evaluation/templates'); if (tab.value === 'sim' && templates.value[0]) sim.value = { ...templates.value[0].weights } }
}
async function openDetail(empId, p) { detail.value = await api.get(`/evaluation/${empId}/${p}`); const rt = detail.value.rating || {}; rateForm.value = { quality: rt.quality ?? null, coop: rt.coop ?? null, note: rt.note || '' }; objText.value = ''; objNote.value = ''; objScore.value = null }
const rateForm = ref({ quality: null, coop: null, note: '' })
const TAB_HELP = {
  my: 'تقييماتي: درجاتك الشهرية بعد اعتمادها ونشرها، ومعدلك السنوي، مع إمكانية الاعتراض خلال المدة المحددة. لا يظهر هنا إلا ما نُشر.',
  monthly: 'التقييم الشهري: تقييم فريقك لشهر محدد. الدرجة تُحسب آليًا من التقرير الشهري والحضور والتطوير والانضباط، ويُضيف المدير تقييم الجودة والتعاون من زر «عرض»، ثم يُنشر التقييم للموظف.',
  annual: 'التقييم السنوي: 70% متوسط التقييمات الشهرية المنشورة + 30% الأهداف السنوية المعتمدة من الرئيس التنفيذي للشركة. إن نقصت الأشهر عن 6 والموظف مستوفٍ لمدة خدمة المكافأة يُحسب من الأشهر المتاحة. يُنشر ليحق للموظف الاعتراض عليه.',
  sim: 'محاكاة «ماذا لو»: تجربة أوزان مختلفة ومعرفة أثرها على درجات الفريق قبل اعتمادها، دون تغيير أي تقييم فعلي.',
  weights: 'الأوزان والتقديرات: نسب المحاور الأربعة وحد القبول وسلم التقديرات المعتمد. التعديل للرئيس التنفيذي للمجموعة فقط ويُحفظ بنسخة جديدة.',
}
async function saveRating() {
  const r = rateForm.value, mx = detail.value.rating
  if (r.quality == null || r.coop == null || r.quality === '' || r.coop === '') return ui.toast('أدخل درجتي الجودة والتعاون', 'warn')
  if (r.quality < 0 || r.quality > mx.max_quality || r.coop < 0 || r.coop > mx.max_coop) return ui.toast(`الجودة من 0 إلى ${mx.max_quality} والتعاون من 0 إلى ${mx.max_coop}`, 'warn')
  busy.value = true
  try {
    const res = await api.post(`/evaluation/${detail.value.employee.id}/${detail.value.period}/manager`, r)
    detail.value.computed = res.computed
    ui.toast('حُفظ تقييم المدير وأُعيد احتساب الدرجة', 'ok'); load()
  } finally { busy.value = false }
}
async function publish(r) { await api.post(`/evaluation/${r.employee.id}/${r.period}/publish`); ui.toast('نُشر التقييم وأُشعر الموظف', 'ok'); load() }
async function object() { if (!objText.value.trim()) return ui.toast('اكتب نص الاعتراض', 'warn'); busy.value = true; try { await api.post(`/evaluations/${detail.value.published.id}/object`, { text: objText.value }); ui.toast('قُدّم الاعتراض', 'ok'); detail.value = null; load() } finally { busy.value = false } }
async function decideObj(decision) { if (!objNote.value.trim()) return ui.toast('القرار المكتوب إلزامي', 'warn'); busy.value = true; try { await api.post(`/evaluations/${detail.value.published.id}/objection`, { decision, note: objNote.value, score: objScore.value || undefined }); ui.toast('سُجّل القرار', 'ok'); detail.value = null; load() } finally { busy.value = false } }
async function runSim() {
  const base = await api.get('/evaluation/monthly', { params: { period: period.value } })
  const s = await api.get('/evaluation/monthly', { params: { period: period.value, weights: sim.value } })
  simRows.value = s.map((r) => ({ ...r, base: base.find((b) => b.employee.id === r.employee.id)?.score ?? 0 }))
}
async function saveSimAsTemplate() { const tp = templates.value[0]; const reason = await promptBox('سبب اعتماد الأوزان الجديدة:'); if (!reason) return; await api.put(`/evaluation/templates/${tp.id}`, { ...tp, weights: { ...sim.value }, reason }); ui.toast('اعتُمدت الأوزان بنسخة جديدة', 'ok'); load() }
async function saveTpl() {
  const x = tplForm.value
  if (!x.reason?.trim()) return ui.toast('سبب التعديل إلزامي', 'warn')
  const grades = x.gradesTxt.split('\n').map((l) => l.split('|').map((s) => s.trim())).filter((g) => g.length === 3).map(([m, g, l]) => [Number(m), g, l])
  busy.value = true
  try { await api.put(`/evaluation/templates/${x.id}`, { name: x.name, scope: x.scope, scope_id: x.scope_id, pass_score: x.pass_score, weights: x.weights, sub: x.sub, grades, active: x.active, reason: x.reason }); ui.toast('حُفظت نسخة جديدة', 'ok'); tplForm.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
function exportCsv() { exportXlsx(`evaluation-${period.value}`, ([['الموظف', 'الشركة', 'إنجاز', 'حضور', 'تطوير', 'انضباط', 'الدرجة', 'التقدير', 'المنشور'], ...rows.value.map((r) => [r.employee.name, r.company, r.axes.achieve, r.axes.attend ?? 'معفى', r.axes.dev, r.axes.disc, r.score, r.grade.grade, r.published?.score ?? ''])]), { title: 'التقييم الشهري', period: period.value.split('-').reverse().join('/') }) }
onMounted(load)
</script>
