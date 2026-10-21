<template>
  <div class="page-h">
    <div><h1>{{ t('التقارير الدورية') }}</h1><p>تقرير شهري لكل موظف من قالب مرن (بنود بأوزان + بنود شخصية) يمر بالمدير ثم الموارد البشرية ثم الرئيس ويولّد التقييم آليًا · تقرير أسبوعي قصير يؤثر في محور الانضباط</p></div>
    <div class="acts"><MonthPicker v-model="period" @change="load" /></div>
  </div>
  <div class="tabs"><button v-for="x in tabs" :key="x[0]" :class="{ on: tab === x[0] }" @click="tab = x[0]; load()">{{ x[1] }}</button></div>

  <!-- My monthly report -->
  <template v-if="tab === 'mine'">
    <div v-if="!rep"><div class="skel" v-for="i in 4" :key="i" /></div>
    <template v-else>
      <div class="row mb">
        <span class="pill" :class="STATUS_COLOR[rep.status]">{{ rep.status_label }}</span>
        <span v-if="rep.late" class="pill bad">متأخر عن موعد الرفع (يوم {{ rep.template?.due_day }})</span>
        <span class="small muted">القالب: {{ rep.template?.name }}</span>
        <span v-if="rep.return_note" class="notice bad" style="margin:0">سبب الإرجاع: {{ rep.return_note }}</span>
        <span v-if="rep.locked" class="pill bad">الشهر مقفل — اطلب فتحه من «طلب جديد ← فتح شهر مقفل»</span><span v-else-if="rep.reopen_until" class="pill warn">مفتوح استثنائيًا حتى {{ fmtD(rep.reopen_until) }}</span><span v-else-if="rep.lock_at" class="tiny muted">يُقفل الشهر بنهاية {{ fmtD(rep.lock_at) }} (أول 5 أيام عمل من الشهر التالي)</span>
        <span v-if="rep.first_submitted_at" class="tiny muted">أول رفع: {{ fmtDT(rep.first_submitted_at) }}</span>
      </div>
      <div v-if="rep.preview" class="grid g5 mb">
        <Kpi label="الإنجاز" :value="rep.preview.axes.achieve" :hint="`من ${rep.preview.weights.achieve}`" color="b" />
        <Kpi label="الحضور" :value="rep.preview.exempt_attendance ? 'معفى' : rep.preview.axes.attend" :hint="`من ${rep.preview.weights.attend}`" color="g" />
        <Kpi label="التطوير" :value="rep.preview.axes.dev" :hint="`من ${rep.preview.weights.dev}`" color="p" />
        <Kpi label="الانضباط" :value="rep.preview.axes.disc" :hint="`من ${rep.preview.weights.disc}`" color="y" />
        <Kpi label="الدرجة المتوقعة" :value="rep.preview.score" :hint="rep.preview.grade.label" :color="rep.preview.grade.pass ? 'g' : 'r'" />
      </div>
      <div v-if="rep.can_edit" class="notice info row" style="gap:10px;align-items:center;flex-wrap:wrap">
        <span><b>التعبئة عبر Excel:</b> حمّل نموذجك الشهري، وفيه مهامك ومؤشراتك جاهزة. عبّئ المنجز وإنجاز المؤشر والمستهدفات والعقبات والحلول والملاحظات، ثم ارفعه هنا مباشرة دون بريد.</span>
        <span class="spacer" />
        <button class="btn sec xs" @click="downloadTemplate"><Icon name="download" :size="14" /> تحميل نموذج Excel</button>
        <label class="btn pri xs" style="cursor:pointer"><Icon name="upload" :size="14" /> رفع النموذج المعبّأ<input type="file" accept=".xlsx,.xls,.csv" hidden @change="uploadTemplate" /></label>
      </div>
      <div class="card">
        <h3>بنود التقرير <span class="sub">مجموع الأوزان {{ totalWeight }}</span>
          <span v-if="rep.can_edit && rep.template?.allow_employee_items && empItems < (rep.limits?.emp_items ?? rep.template.max_employee_items)" class="acts"><button class="btn sec xs" @click="addItem"><Icon name="plus" :size="14" /> بند شخصي (حتى {{ rep.limits?.emp_items ?? rep.template.max_employee_items }}، وزن ≤ {{ rep.limits?.emp_weight ?? rep.template.employee_item_weight_max }}، بموافقة المشرف)</button></span>
        </h3>
        <ReportItems :items="rep.items" :mode="rep.can_edit ? 'edit' : 'view'" :max-emp-weight="rep.limits?.emp_weight || rep.template?.employee_item_weight_max || 15" @remove="(it) => rep.items.splice(rep.items.indexOf(it), 1)" />
      </div>
      <div class="card">
        <h3>التطوير خلال الشهر <span class="sub">يُدرج تلقائيًا من سجل التطوير، ولا يحتاج كتابة · {{ rep.dev_points?.total ?? 0 }} / {{ rep.dev_points?.target ?? 100 }} نقطة</span>
          <span class="acts"><RouterLink class="btn sec xs" to="/development"><Icon name="plus" :size="14" /> إضافة نشاط تطوير</RouterLink></span></h3>
        <div v-if="!rep.dev_records?.length" class="empty small">لا تطوير مسجّل لهذا الشهر. سجّل الدورات والقراءة والورش من «التطوير والتدريب» لتظهر هنا تلقائيًا.</div>
        <div v-dt v-else class="tw"><table><thead><tr><th>البرنامج</th><th>الجهة</th><th>النوع</th><th>الفترة</th><th>الساعات</th><th>الحالة</th><th>النقاط</th></tr></thead>
          <tbody><tr v-for="d in rep.dev_records" :key="d.id"><td><b>{{ d.program }}</b></td><td>{{ d.provider || '—' }}</td><td class="small">{{ d.activity_name || '—' }}</td><td class="small num">{{ fmtD(d.from) }}{{ d.to && d.to !== d.from ? ' — ' + fmtD(d.to) : '' }}</td><td class="num">{{ d.hours || '—' }}</td>
            <td><span class="pill" :class="d.counted ? 'ok' : 'warn'">{{ d.status_label }}</span></td><td class="num">{{ d.counted ? d.points : '—' }}</td></tr></tbody></table></div>
        <div class="fg mt"><label>ملاحظاتك على التطوير هذا الشهر (اختياري)</label><textarea v-model="rep.dev.notes" class="in" rows="2" :disabled="!rep.can_edit" /></div>
      </div>
      <div v-if="rep.timeline.length" class="card"><h3>المسار</h3><ul class="timeline"><li v-for="(x, i) in rep.timeline" :key="i"><b>{{ x.label }}</b> <span class="tiny muted">{{ fmtDT(x.at) }}</span></li></ul></div>
      <div v-if="rep.can_edit" class="row">
        <button class="btn sec" :class="{ loading: busy }" @click="save(false)"><Icon name="check" :size="15" /> حفظ مسودة</button>
        <button class="btn pri" :class="{ loading: busy }" @click="save(true)"><Icon name="send" :size="15" flip-rtl /> إرسال للمدير</button>
      </div>
    </template>
    <div class="card mt">
      <h3>تقاريري السابقة</h3>
      <div v-if="!history.length" class="empty">لا تقارير</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الشهر</th><th>الحالة</th><th>البنود</th><th>رُفع</th></tr></thead>
        <tbody><tr v-for="h in history" :key="h.id"><td><b>{{ fmtYM(h.period) }}</b></td><td><span class="pill" :class="STATUS_COLOR[h.status]">{{ h.status_label }}</span><span v-if="h.late" class="pill bad">متأخر</span></td><td>{{ h.items_done }}/{{ h.items_total }}</td><td class="small">{{ fmtDT(h.submitted_at) }}</td></tr></tbody></table></div>
    </div>
  </template>

  <!-- Team monthly reports -->
  <template v-else-if="tab === 'team'">
    <div class="grid g4 mb">
      <Kpi label="لم يُرفع" :value="team.filter((x) => !x.report || ['draft', 'returned'].includes(x.report.status)).length" color="r" />
      <Kpi label="بانتظار المراجعة" :value="team.filter((x) => x.report && ['submitted', 'mgr_reviewed', 'hr_reviewed'].includes(x.report.status)).length" color="y" />
      <Kpi label="معتمد" :value="team.filter((x) => x.report?.status === 'approved').length" color="g" />
      <Kpi label="الإجمالي" :value="team.length" color="b" />
    </div>
    <div class="row mb" style="gap:8px;flex-wrap:wrap">
      <button v-if="auth.isHr" class="btn sec sm" @click="remind"><Icon name="bell" :size="14" /> تذكير المتأخرين عن {{ fmtYM(period) }}</button>
      <span class="spacer" />
      <button class="btn sec sm" :disabled="!picked.length" :class="{ loading: dl === 'sel' }" @click="downloadReports(picked, 'sel')"><Icon name="download" :size="14" /> تحميل المحدد ({{ picked.length }})</button>
      <button class="btn pri sm" :disabled="!team.length" :class="{ loading: dl === 'all' }" @click="downloadReports(team.map((x) => x.employee.id), 'all')"><Icon name="download" :size="14" /> تحميل تقارير الكل ({{ team.length }})</button>
    </div>
    <div class="card">
      <div v-dt class="tw"><table><thead><tr><th style="width:34px"><input type="checkbox" :checked="team.length && picked.length === team.length" title="تحديد الكل" @change="picked = $event.target.checked ? team.map((x) => x.employee.id) : []" /></th><th>الموظف</th><th>الشركة</th><th>الحالة</th><th>المرحلة الحالية</th><th>البنود</th><th /></tr></thead>
        <tbody><tr v-for="x in team" :key="x.employee.id">
          <td><input v-model="picked" type="checkbox" :value="x.employee.id" /></td>
          <td><b>{{ x.employee.name }}</b><span class="tiny muted">{{ x.employee.emp_no }}</span></td><td>{{ x.company }}</td>
          <td><span v-if="x.report" class="pill" :class="STATUS_COLOR[x.report.status]">{{ x.report.status_label }}</span><span v-else class="pill gray">لم يبدأ</span></td>
          <td class="small">{{ x.report ? STAGE_ACTOR[x.report.status] : 'الموظف' }}</td>
          <td>{{ x.report ? `${x.report.items_done}/${x.report.items_total}` : '—' }}</td>
          <td><div class="row" style="gap:4px;flex-wrap:nowrap;justify-content:flex-end"><button v-if="x.report" class="btn ghost xs" @click="openReview(x.report.id)">فتح</button><button class="btn ghost xs" :title="x.report ? 'تحميل التقرير Excel' : 'تحميل النموذج الفارغ'" :class="{ loading: dl === x.employee.id }" @click="downloadReports([x.employee.id], x.employee.id)"><Icon name="download" :size="14" /></button></div></td>
        </tr></tbody></table></div>
    </div>
  </template>

  <!-- Weekly -->
  <template v-else-if="tab === 'weekly'">
    <div v-if="auth.emp" class="card">
      <h3>أسابيعي <span class="sub">الأسبوع من الأحد إلى الخميس (ومن السبت لمن يعمل 6 أيام) · ارفع خطة الأسبوع في أوله ليعتمدها مديرك، ثم المنجز قبل الموعد الذي حدده · المتأخر برتقالي وغير المرفوع أحمر ويُسجَّل في ملفك ويُخصم من تقييمك الشهري</span></h3>
      <div v-if="!weeks.length" class="empty">لا أسابيع مطلوبة منك هذا الشهر</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الأسبوع</th><th>موعد رفع المنجز</th><th>الخطة</th><th>التقرير</th><th>البنود</th><th>ما أُنجز</th><th /></tr></thead>
        <tbody><tr v-for="w in weeks" :key="w.id" :class="{ 'wk-red': weekState(w) === 'missing', 'wk-orange': w.status === 'late' }">
          <td class="num">{{ fmtD(w.week_start) }} → {{ fmtD(w.week_end) }}</td>
          <td class="small"><span class="num">{{ fmtDT(w.due_at) }}</span><span class="tiny muted" style="display:block">{{ w.due_rule }}</span></td>
          <td><span class="pill" :class="PLAN_C[w.plan_status || 'none']">{{ PLAN[w.plan_status || 'none'] }}</span><div v-if="w.plan_status === 'returned' && w.mgr_note" class="tiny bad">{{ w.mgr_note }}</div></td>
          <td><span class="pill" :class="WEEK_C[weekState(w)]">{{ WEEK[weekState(w)] }}</span></td>
          <td class="small">{{ (w.items || []).length ? `${(w.items || []).length} بند` : '—' }}</td>
          <td class="small">{{ w.done || (w.items || []).filter((i) => i.achieved).map((i) => i.achieved).join(' · ') || '—' }}</td>
          <td class="row" style="gap:4px;flex-wrap:nowrap">
            <button v-if="['none', 'returned', 'submitted'].includes(w.plan_status || 'none') && w.status === 'missing'" class="btn xs" :class="(w.plan_status || 'none') === 'submitted' ? 'ghost' : 'sec'" @click="openWeek(w, 'plan')">{{ (w.plan_status || 'none') === 'submitted' ? 'تعديل الخطة' : 'رفع خطة الأسبوع' }}</button>
            <button v-if="['submitted', 'approved'].includes(w.plan_status)" class="btn xs" :class="w.status === 'missing' ? 'pri' : 'ghost'" @click="openWeek(w, 'report')">{{ w.status === 'missing' ? 'رفع المنجز' : 'تعديل المنجز' }}</button>
          </td>
        </tr></tbody></table></div>
    </div>
    <div v-if="auth.isApprover" class="card">
      <h3>لوحة أسابيع الفريق <span class="sub">اضغط على أي أسبوع لعرضه، والخطط بانتظار اعتمادك معلَّمة «خطة»</span></h3>
      <div v-if="!Object.keys(teamWeeksByEmp).length" class="empty">لا أسابيع للفريق في هذا الشهر</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th v-for="ws in weekStarts" :key="ws" class="num">{{ fmtD(ws).slice(0, 5) }}</th></tr></thead>
        <tbody><tr v-for="(rows, name) in teamWeeksByEmp" :key="name"><td><b>{{ name }}</b></td>
          <td v-for="ws in weekStarts" :key="ws">
            <template v-if="rows[ws]"><span class="pill" :class="WEEK_C[weekState(rows[ws])]" style="cursor:pointer" @click="openTeamWeek(rows[ws])">{{ WEEK[weekState(rows[ws])] }}</span><span v-if="rows[ws].plan_status === 'submitted'" class="pill info" style="cursor:pointer" title="خطة بانتظار اعتمادك" @click="openTeamWeek(rows[ws])">خطة</span></template>
            <span v-else class="muted">—</span>
          </td>
        </tr></tbody></table></div>
    </div>
    <div v-if="auth.isApprover" class="card">
      <h3>إعدادات تقارير الفريق <span class="sub">يحددها المدير المباشر (أو الموارد البشرية) لكل موظف: التقرير الأسبوعي وموعد رفعه، والمنجز اليومي عند الانصراف</span>
        <span class="acts"><button class="btn ghost xs" @click="loadTeamSettings"><Icon name="refresh" :size="13" /> تحديث</button></span></h3>
      <div v-if="!teamSettings.length" class="empty small">لا موظفين تابعين لك</div>
      <div v-dt v-else class="tw"><table><thead><tr><th>الموظف</th><th>أيام العمل</th><th>التقرير الأسبوعي</th><th>موعد رفع المنجز</th><th>المنجز اليومي عند الانصراف</th></tr></thead>
        <tbody><tr v-for="m in teamSettings" :key="m.id">
          <td><b>{{ m.name }}</b><span class="tiny muted">{{ m.emp_no }}</span></td>
          <td class="small">{{ m.week }}</td>
          <td><select class="in" style="min-height:30px;width:auto" :value="m.weekly_override === null ? '' : m.weekly_override ? '1' : '0'" @change="setTeam(m, { required: $event.target.value === '' ? null : $event.target.value === '1' })">
            <option value="">حسب إعداد الشركة ({{ m.weekly && m.weekly_override === null ? 'مطلوب' : 'افتراضي' }})</option><option value="1">مطلوب</option><option value="0">غير مطلوب</option></select></td>
          <td><select class="in" style="min-height:30px;width:auto" :value="m.due || ''" :disabled="!m.weekly" @change="setTeam(m, { due: $event.target.value || null })">
            <option value="">الافتراضي ({{ m.due ? 'أول يوم من الأسبوع التالي' : m.due_label }})</option><option value="end">آخر يوم في الأسبوع</option><option value="next">أول يوم من الأسبوع التالي</option></select></td>
          <td><label class="chk"><input type="checkbox" :checked="m.daily_note" @change="setTeam(m, { daily_note: $event.target.checked })" /> مطلوب</label><span v-if="m.daily_note_company && !m.daily_note" class="tiny muted">(مطلوب للجميع من إعداد الشركة)</span></td>
        </tr></tbody></table></div>
    </div>
  </template>

  <!-- Templates -->
  <template v-else>
    <div class="card">
      <h3>قوالب التقارير <span class="sub">يُطبَّق نموذج الموظف أولًا، ثم الوظيفة، ثم الشركة، ثم المجموعة</span><span class="acts"><button class="btn sec xs" @click="tplForm = { name: '', scope: 'group', scope_id: null, items: [{ title: '', target: '', indicator: '', weight: 100 }], allow_employee_items: true, max_employee_items: 3, employee_item_weight_max: 15, due_day: 5, active: true }"><Icon name="plus" :size="14" /> قالب</button></span></h3>
      <div v-dt class="tw"><table><thead><tr><th>القالب</th><th>النطاق</th><th>البنود</th><th>بنود شخصية</th><th>موعد الرفع</th><th>النسخة</th><th /></tr></thead>
        <tbody><tr v-for="tp in templates" :key="tp.id">
          <td><b>{{ tp.name }}</b></td><td>{{ SCOPE[tp.scope] }} {{ scopeName(tp) }}</td><td class="small"><b>{{ tp.items.length }} بند</b>: {{ tp.items.slice(0, 3).map((i) => i.title).join(' · ') }}{{ tp.items.length > 3 ? ' …' : '' }}</td>
          <td>{{ tp.allow_employee_items ? `حتى ${tp.max_employee_items} بوزن ≤ ${tp.employee_item_weight_max}` : '—' }}</td><td>يوم {{ tp.due_day }}</td><td>v{{ tp.version }}</td>
          <td><button class="btn ghost xs" @click="tplForm = JSON.parse(JSON.stringify(tp))">تعديل</button></td>
        </tr></tbody></table></div>
    </div>
  </template>


  <Modal v-if="weekForm" :title="`${weekMode === 'plan' ? 'خطة' : 'منجز'} الأسبوع ${fmtD(weekForm.week_start)} → ${fmtD(weekForm.week_end)}`" size="wide" @close="weekForm = null">
    <template v-if="weekMode === 'plan'">
      <div class="notice info small">حدّد بنود الأسبوع: لكل بند مستهدفه لمدة شهر أو 3 أشهر (أو اجلبه من تقريرك الشهري)، ثم يُقسَّم تلقائيًا إلى مستهدف هذا الأسبوع ويمكنك تعديله. ترفع الخطة لمديرك المباشر ليعتمدها.</div>
      <div v-if="weekForm.plan_status === 'returned' && weekForm.mgr_note" class="notice bad small">ملاحظة المدير عند الإرجاع: {{ weekForm.mgr_note }}</div>
      <div class="row mb" style="gap:6px">
        <button class="btn sec xs" @click="fromMonthly('monthly')"><Icon name="download" :size="13" /> جلب البنود من التقرير الشهري</button>
        <button class="btn sec xs" @click="fromMonthly('prev')"><Icon name="repeat" :size="13" /> من خطة الأسبوع الماضي</button>
        <button class="btn ghost xs" @click="weekForm.items.push({ axis: '', title: '', kpi: '', horizon: 'month', period_target: '', target: '', achieved: '', source: 'own' })"><Icon name="plus" :size="13" /> بند</button>
      </div>
      <div class="tw"><table class="wk"><thead><tr><th>المحور</th><th>البند *</th><th>المؤشر</th><th>مدة المستهدف</th><th>المستهدف للمدة</th><th>مستهدف هذا الأسبوع</th><th /></tr></thead>
        <tbody><tr v-for="(it, i) in weekForm.items" :key="i">
          <td><input v-model="it.axis" class="in" /></td><td><input v-model="it.title" class="in" /></td><td><input v-model="it.kpi" class="in" /></td>
          <td><select v-model="it.horizon" class="in" style="min-width:96px" @change="autoShare(it, true)"><option value="month">شهر</option><option value="quarter">3 أشهر</option></select></td>
          <td><input v-model="it.period_target" class="in" placeholder="مثال: 20 عميل" @change="autoShare(it, true)" /></td>
          <td><input v-model="it.target" class="in" :placeholder="share(it) || 'مستهدف الأسبوع'" /></td>
          <td><button class="btn ghost xs" title="حذف" @click="weekForm.items.splice(i, 1)"><Icon name="x" :size="13" /></button></td>
        </tr></tbody></table></div>
    </template>
    <template v-else>
      <div class="tw"><table class="wk"><thead><tr><th>البند</th><th>مستهدف الأسبوع</th><th>المنجز *</th><th>مستهدف الأسبوع القادم</th></tr></thead>
        <tbody><tr v-for="(it, i) in weekForm.items" :key="i">
          <td><b>{{ it.title }}</b><span v-if="it.period_target" class="tiny muted" style="display:block">{{ it.horizon === 'quarter' ? 'مستهدف 3 أشهر' : 'مستهدف الشهر' }}: {{ it.period_target }}</span></td>
          <td class="small">{{ it.target || '—' }}</td>
          <td><textarea v-model="it.achieved" class="in" rows="2" /></td>
          <td><input v-model="it.next_target" class="in" placeholder="ماذا ستنجز في هذا البند الأسبوع القادم" /></td>
        </tr></tbody></table></div>
      <div class="fg mt"><label>ملخص ما أُنجز</label><textarea v-model="weekForm.done" class="in" rows="2" /></div>
      <div class="fr"><div class="fg"><label>العقبات</label><input v-model="weekForm.blockers" class="in" /></div><div class="fg"><label>ماذا ستفعل الأسبوع القادم</label><input v-model="weekForm.next" class="in" /></div></div>
      <div class="tiny muted">موعد الرفع: {{ fmtDT(weekForm.due_at) }} ({{ weekForm.due_rule }}) — بعده يُعد متأخرًا.</div>
    </template>
    <template #foot><button class="btn sec" @click="weekForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" @click="weekMode === 'plan' ? submitPlan() : submitWeek()">{{ weekMode === 'plan' ? 'رفع الخطة لاعتماد المدير' : 'رفع المنجز' }}</button></template>
  </Modal>

  <Modal v-if="teamWeek" :title="`${teamWeek.employee?.name} — أسبوع ${fmtD(teamWeek.week_start)}`" size="wide" @close="teamWeek = null">
    <div class="row mb" style="gap:6px"><span class="pill" :class="PLAN_C[teamWeek.plan_status || 'none']">الخطة: {{ PLAN[teamWeek.plan_status || 'none'] }}</span><span class="pill" :class="WEEK_C[weekState(teamWeek)]">التقرير: {{ WEEK[weekState(teamWeek)] }}</span><span class="tiny muted">موعد الرفع {{ fmtDT(teamWeek.due_at) }}</span></div>
    <div v-if="(teamWeek.items || []).length" class="tw"><table><thead><tr><th>المحور</th><th>البند</th><th>المؤشر</th><th>المستهدف للمدة</th><th>مستهدف الأسبوع</th><th>المنجز</th><th>الأسبوع القادم</th></tr></thead>
      <tbody><tr v-for="(it, i) in teamWeek.items" :key="i"><td>{{ it.axis }}</td><td>{{ it.title }}</td><td>{{ it.kpi }}</td><td class="small">{{ it.period_target ? `${it.period_target} (${it.horizon === 'quarter' ? '3 أشهر' : 'شهر'})` : '—' }}</td>
        <td><input v-if="teamWeek.plan_status === 'submitted'" v-model="it.target" class="in" style="min-height:30px" /><template v-else>{{ it.target || '—' }}</template></td><td>{{ it.achieved || '—' }}</td><td class="small">{{ it.next_target || '—' }}</td></tr></tbody></table></div>
    <div v-else class="empty small">لم يرفع الموظف خطة هذا الأسبوع بعد</div>
    <div v-if="teamWeek.done" class="small mt"><b>الملخص:</b> {{ teamWeek.done }}</div><div v-if="teamWeek.blockers" class="small"><b>العقبات:</b> {{ teamWeek.blockers }}</div><div v-if="teamWeek.next" class="small"><b>الأسبوع القادم:</b> {{ teamWeek.next }}</div>
    <div v-if="teamWeek.plan_status === 'submitted'" class="fg mt"><label>ملاحظة للموظف (إلزامية عند الإرجاع)</label><input v-model="planNote" class="in" /></div>
    <template #foot><button class="btn sec" @click="teamWeek = null">إغلاق</button>
      <template v-if="teamWeek.plan_status === 'submitted'"><button class="btn dan" :class="{ loading: busy }" @click="approvePlan('return')">إرجاع للتعديل</button><button class="btn pri" :class="{ loading: busy }" @click="approvePlan('approve')">اعتماد الخطة</button></template></template>
  </Modal>

  <Modal v-if="tplForm" :title="tplForm.id ? 'تعديل قالب' : 'قالب جديد'" size="wide" @close="tplForm = null">
    <div class="fr3">
      <div class="fg"><label>الاسم *</label><input v-model="tplForm.name" class="in" /></div>
      <div class="fg"><label>النطاق</label><select v-model="tplForm.scope" class="in"><option v-for="(l, k) in SCOPE" :key="k" :value="k">{{ l }}</option></select></div>
      <div v-if="tplForm.scope === 'company'" class="fg"><label>الشركة</label><select v-model="tplForm.scope_id" class="in"><option v-for="c in auth.meta.companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <div v-else-if="tplForm.scope === 'employee'" class="fg"><label>الموظف</label><select v-model="tplForm.scope_id" class="in"><option v-for="e in staff" :key="e.id" :value="e.id">{{ e.name }}</option></select></div>
      <div v-else-if="tplForm.scope === 'job'" class="fg"><label>الوظيفة</label><select v-model="tplForm.scope_id" class="in"><option v-for="j in auth.meta.job_titles" :key="j.id" :value="j.id">{{ j.name }}</option></select></div>
      <div class="fg"><label>موعد الرفع (يوم من الشهر التالي)</label><input v-model.number="tplForm.due_day" type="number" min="1" max="28" class="in" /></div>
      <div class="fg"><label>حد البنود الشخصية</label><input v-model.number="tplForm.max_employee_items" type="number" min="0" class="in" /></div>
      <div class="fg"><label>أقصى وزن للبند الشخصي</label><input v-model.number="tplForm.employee_item_weight_max" type="number" min="0" class="in" /></div>
    </div>
    <div v-dt class="tw mb"><table><thead><tr><th>المحور</th><th>المهمة الوظيفية</th><th>المستهدف</th><th>المؤشر</th><th>الوزن</th><th /></tr></thead>
      <tbody><tr v-for="(it, i) in tplForm.items" :key="i"><td><input v-model="it.axis" class="in" /></td><td><input v-model="it.title" class="in" /></td><td><input v-model="it.target" class="in" /></td><td><input v-model="it.indicator" class="in" /></td><td><input v-model.number="it.weight" type="number" class="in" style="width:80px" /></td><td><button class="btn ghost xs bad" @click="tplForm.items.splice(i, 1)"><Icon name="trash" :size="14" /></button></td></tr></tbody></table></div>
    <div class="row"><button class="btn sec sm" @click="tplForm.items.push({ axis: tplForm.items[tplForm.items.length - 1]?.axis || '', title: '', target: '', indicator: '', weight: 0 })"><Icon name="plus" :size="14" /> بند</button><button class="btn ghost sm" @click="evenWeights">توزيع الأوزان بالتساوي</button><span class="small" :class="tplTotal === 100 ? 'ok' : 'bad'">مجموع الأوزان: {{ tplTotal }} / 100</span><label class="chk"><input v-model="tplForm.allow_employee_items" type="checkbox" /> يسمح ببنود شخصية</label><label class="chk"><input v-model="tplForm.active" type="checkbox" /> مفعّل</label></div>
    <template #foot><button class="btn sec" @click="tplForm = null">إلغاء</button><button class="btn pri" :class="{ loading: busy }" :disabled="tplTotal !== 100" @click="saveTpl">حفظ نسخة</button></template>
  </Modal>
</template>

<script setup>
import { applyReportRows, exportReportForm, exportReportForms, readXlsx } from '../excel'
import { alertBox } from '../dialog'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import Kpi from '../components/Kpi.vue'
import Modal from '../components/Modal.vue'
import ReportItems from '../components/ReportItems.vue'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import { curYM, fmtD, fmtDT, fmtYM } from '../utils'

const { t } = useI18n()
const auth = useAuth(); const ui = useUi()
const router = useRouter()
const STATUS_COLOR = { draft: 'gray', submitted: 'warn', mgr_reviewed: 'info', hr_reviewed: 'info', approved: 'ok', returned: 'bad' }
const STAGE_ACTOR = { draft: 'الموظف', returned: 'الموظف', submitted: 'المدير المباشر', mgr_reviewed: 'الموارد البشرية', hr_reviewed: 'رئيس الشركة', approved: 'منشور' }
const ROLE = { mgr: 'المدير المباشر', hr: 'الموارد البشرية', ceo: 'رئيس الشركة' }
const WEEK = { submitted: 'في الموعد', late: 'متأخر', missing: 'لم يُرفع', open: 'لم يُرفع بعد' }
const WEEK_C = { submitted: 'ok', late: 'warn', missing: 'bad', open: 'gray' }
const PLAN = { none: 'لم تُرفع', submitted: 'بانتظار المدير', approved: 'معتمدة', returned: 'أُرجعت للتعديل' }
const PLAN_C = { none: 'gray', submitted: 'info', approved: 'ok', returned: 'bad' }
// a week still before its deadline is "not raised yet" (grey), only past the deadline is it red
const weekState = (w) => (w.status === 'missing' && w.due_at && new Date(w.due_at.replace(' ', 'T')) > new Date() ? 'open' : w.status)
const weekMode = ref('report'); const teamWeek = ref(null); const planNote = ref(''); const teamSettings = ref([])
function openWeek(w, mode) { weekMode.value = mode; weekForm.value = { ...w, items: (w.items || []).map((i) => ({ horizon: 'month', ...i })) } }
function openTeamWeek(w) { planNote.value = ''; teamWeek.value = { ...w, items: (w.items || []).map((i) => ({ ...i })) } }
// a week's share of the period target: "20 عميل" over the weeks of the month (or 13 weeks for 3 months) → "5 عميل"
function share(it) {
  const t = String(it.period_target || '').trim().replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace('٫', '.')
  const m = t.match(/^(\d+(?:\.\d+)?)\s*(%?)\s*(.*)$/)
  if (!m) return ''
  if (m[2]) return `${m[1]}%${m[3] ? ' ' + m[3] : ''}`
  const n = it.horizon === 'quarter' ? 13 : Math.max(1, weeks.value.length || 4)
  return `${Math.round((Number(m[1]) / n) * 10) / 10}${m[3] ? ' ' + m[3] : ''}`
}
function autoShare(it, force) { const x = share(it); if (x && (force || !it.target)) it.target = x }
async function fromMonthly(source) {
  const items = await api.get(`/reports/weekly/${weekForm.value.id}/from-monthly`, { params: { source } })
  if (!items.length) return ui.toast(source === 'prev' ? 'لا خطة للأسبوع الماضي' : 'لا بنود في التقرير الشهري بعد', 'warn')
  weekForm.value.items = items.map((i) => ({ horizon: 'month', ...i }))
}
async function submitPlan() {
  if (!weekForm.value.items.some((i) => i.title?.trim())) return ui.toast('أضف بندًا واحدًا على الأقل', 'warn')
  busy.value = true
  try { await api.post(`/reports/weekly/${weekForm.value.id}/plan`, { items: weekForm.value.items.filter((i) => i.title?.trim()) }); ui.toast('رُفعت الخطة لاعتماد المدير', 'ok'); weekForm.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message || 'تعذر الرفع', 'bad') } finally { busy.value = false }
}
async function approvePlan(decision) {
  if (decision === 'return' && !planNote.value.trim()) return ui.toast('اكتب سبب الإرجاع في الملاحظة', 'warn')
  busy.value = true
  try {
    await api.post(`/reports/weekly/${teamWeek.value.id}/plan/approve`, { decision, note: planNote.value || null, items: decision === 'approve' ? teamWeek.value.items : undefined })
    ui.toast(decision === 'return' ? 'أُرجعت الخطة للموظف' : 'اعتُمدت الخطة', 'ok'); teamWeek.value = null; planNote.value = ''; load()
  } catch (e) { ui.toast(e.message || 'تعذر الإجراء', 'bad') } finally { busy.value = false }
}
async function loadTeamSettings() { teamSettings.value = await api.get('/reports/team-settings').catch(() => []) }
async function setTeam(m, patch) {
  try { const r = await api.put(`/reports/weekly/employee/${m.id}`, patch); Object.assign(m, { weekly: r.required, weekly_override: r.override, due: r.due, due_label: r.due_label, daily_note: r.daily_note }); ui.toast('حُفظ — وأُبلغ الموظف', 'ok') } catch (e) { ui.toast(e.message || 'تعذر الحفظ', 'bad'); loadTeamSettings() }
}
const SCOPE = { group: 'المجموعة', company: 'شركة', job: 'وظيفة', employee: 'موظف بعينه' }
const prevMonth = () => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }
const period = ref(auth.emp ? curYM() : prevMonth())
const tabs = computed(() => [auth.emp && ['mine', 'تقريري الشهري'], auth.isApprover && ['team', 'تقارير الفريق'], (auth.emp || auth.isApprover) && ['weekly', 'الأسبوعي'], auth.isAdmin && ['templates', 'قوالب التقارير']].filter(Boolean))
const route = useRoute()
const tab = ref(['mine', 'team', 'weekly', 'templates'].includes(route.query.tab) ? route.query.tab : auth.emp ? 'mine' : 'team')
const rep = ref(null); const history = ref([]); const team = ref([]); const weeks = ref([]); const teamWeeks = ref([]); const templates = ref([])
const rv = ref(null); const rvf = ref({}); const adj = ref({ hr_bonus: 0, reason: '' }); const weekForm = ref(null); const tplForm = ref(null); const busy = ref(false)
const totalWeight = computed(() => (rep.value?.items || []).reduce((s, i) => s + (Number(i.weight) || 0), 0))
const empItems = computed(() => (rep.value?.items || []).filter((i) => i.source === 'emp').length)
const tplTotal = computed(() => (tplForm.value?.items || []).reduce((s, i) => s + (Number(i.weight) || 0), 0))
const weekStarts = computed(() => [...new Set(teamWeeks.value.map((w) => w.week_start))].sort())
const teamWeeksByEmp = computed(() => teamWeeks.value.reduce((a, w) => { (a[w.employee?.name] ||= {})[w.week_start] = w; return a }, {}))
const scopeName = (tp) => tp.scope === 'employee' ? (staff.value.find((e) => e.id === tp.scope_id)?.name || '') : tp.scope === 'company' ? auth.meta.companies.find((c) => c.id === tp.scope_id)?.name : tp.scope === 'job' ? auth.meta.job_titles.find((j) => j.id === tp.scope_id)?.name : ''

async function load() {
  const p = { params: { period: period.value } }
  if (tab.value === 'mine') [rep.value, history.value] = await Promise.all([api.get('/reports/monthly/mine', p), api.get('/reports/monthly/history')])
  else if (tab.value === 'team') { picked.value = []; team.value = await api.get('/reports/monthly', { params: { period: period.value, company_id: ui.company || undefined } }) }
  else if (tab.value === 'weekly') { if (auth.emp) weeks.value = await api.get('/reports/weekly/mine', p); if (auth.isApprover) { teamWeeks.value = await api.get('/reports/weekly/team', p); loadTeamSettings() } }
  else { [templates.value] = await Promise.all([api.get('/reports/templates'), loadStaff()]) }
}
// Excel round-trip in the group's monthly report layout: the employee's own obligations pre-filled, the rest to fill in
const jobName = computed(() => auth.meta?.job_titles?.find((j) => j.id === auth.emp?.job_title_id)?.name || '')
async function downloadTemplate() {
  const company = auth.meta?.companies?.find((c) => c.id === auth.emp?.company_id)?.name || ''
  await exportReportForm({ name: auth.emp?.name, company, job: jobName.value, manager: auth.emp?.manager?.name, period: rep.value.period }, rep.value.items)
}
// admin downloads: one employee, the selection, or the whole team, as one workbook (a sheet per employee, approved layout)
const picked = ref([])
const dl = ref(null)
async function downloadReports(ids, key) {
  if (!ids.length) return
  dl.value = key
  try {
    const list = await api.post('/reports/monthly/export', { period: period.value, employee_ids: ids })
    if (list.length === 1) await exportReportForm(list[0].info, list[0].items, { kind: 'التقرير الشهري', category: 'reports' })
    else await exportReportForms(list, `التقارير الشهرية ${period.value} (${list.length} موظفين)`)
  } finally { dl.value = null }
}
async function uploadTemplate(ev) {
  const file = ev.target.files[0]; ev.target.value = ''
  if (!file) return
  try {
    const n = applyReportRows(rep.value.items, await readXlsx(file))
    if (!n) return alertBox('لم يُطابَق أي صف مع بنود تقريرك. استخدم النموذج المحمّل من هذه الصفحة، ولا تغيّر عمودي «رقم البند» و«المهام الوظيفية».', { icon: 'warning' })
    await save(false)
    ui.toast(`استُورد ${n} بندًا وحُفظت المسودة. راجعها ثم اضغط «إرسال للمدير»`, 'ok', 6000)
  } catch (x) { alertBox(x.message || 'تعذرت قراءة الملف', { icon: 'error' }) }
}
function addItem() { rep.value.items.push({ title: '', target: '', indicator: '', weight: 5, source: 'emp', achieved: '', kpi_result: '', pct: 0, evidence: '', obstacles: '', solutions: '', next_target: '', notes: '' }) }
async function save(submit) {
  busy.value = true
  try {
    rep.value = await api.put(`/reports/monthly/${rep.value.id}`, { items: rep.value.items, dev: rep.value.dev })
    if (submit) { rep.value = await api.post(`/reports/monthly/${rep.value.id}/submit`); ui.toast('أُرسل التقرير إلى مديرك', 'ok') } else ui.toast('حُفظت المسودة', 'ok')
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function remind() { const r = await api.post('/reports/remind', { period: period.value }); ui.toast(`أُرسل تذكير إلى ${r.reminded} موظفًا ومديريهم`, 'ok') }
async function adjust() { if (!adj.value.reason?.trim()) return ui.toast('سبب التعديل إلزامي', 'warn'); busy.value = true; try { rv.value = await api.post(`/reports/monthly/${rv.value.id}/adjust`, adj.value); ui.toast('عُدّل ونُشر التقييم', 'ok'); load() } finally { busy.value = false } }
function openReview(id) { router.push(`/reports/${id}`) } // full page: long reports read better than in a dialog
async function review(action) {
  if (action === 'return' && !rvf.value.note?.trim()) return ui.toast('سبب الإرجاع إلزامي', 'warn')
  if (action === 'approve' && rv.value.review_role === 'mgr' && rv.value.items.some((i) => i.mgr_score != null && i.mgr_score !== i.pct && !i.mgr_note?.trim())) return ui.toast('اكتب ملاحظة لكل بند اختلف فيه تقييمك عن تقييم الموظف', 'warn', 5000)
  busy.value = true
  try {
    await api.post(`/reports/monthly/${rv.value.id}/review`, { action, ...rvf.value, items: rv.value.items.map((i) => ({ id: i.id, mgr_score: i.mgr_score, mgr_note: i.mgr_note })) })
    ui.toast(action === 'approve' ? 'تم الاعتماد' : 'أُرجع التقرير', 'ok'); rv.value = null; load()
  } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad', 6000) } finally { busy.value = false }
}
async function submitWeek() {
  const w = weekForm.value
  if (!w.done?.trim() && !w.items.some((i) => i.achieved?.trim())) return ui.toast('اكتب المنجز مقابل البنود أو ملخص ما أُنجز', 'warn')
  busy.value = true
  try { await api.post(`/reports/weekly/${w.id}`, { done: w.done, next: w.next, blockers: w.blockers, items: w.items.filter((i) => i.title?.trim()) }); ui.toast('رُفع التقرير الأسبوعي', 'ok'); weekForm.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
const staff = ref([])
async function loadStaff() { if (!staff.value.length) staff.value = ((await api.get('/employees', { params: { per_page: 1000 } })).data || []).map((e) => ({ id: e.id, name: e.name })) }
function evenWeights() { const n = tplForm.value.items.length; const b = Math.floor(100 / n); tplForm.value.items.forEach((it, i) => { it.weight = b + (i < 100 - b * n ? 1 : 0) }) }
async function saveTpl() {
  const x = tplForm.value
  busy.value = true
  try { if (x.id) await api.put(`/reports/templates/${x.id}`, x); else await api.post('/reports/templates', x); ui.toast('حُفظ القالب', 'ok'); tplForm.value = null; load() } catch (e) { ui.toast(Object.values(e.errors || {}).flat().join(' · ') || e.message, 'bad') } finally { busy.value = false }
}
onMounted(load)
</script>

<style scoped>
.wk input, .wk textarea { min-height: 32px; padding: 4px 8px; font-size: 13px; }
.wk td { padding: 3px; vertical-align: top; }
.wk-red td:first-child { box-shadow: inset -4px 0 0 var(--bad); }
.wk-orange td:first-child { box-shadow: inset -4px 0 0 var(--warn); }
.wk-red { background: var(--badbg); }
.wk-orange { background: var(--warnbg); }
</style>
