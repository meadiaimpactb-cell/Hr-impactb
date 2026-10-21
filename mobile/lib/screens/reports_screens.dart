import 'package:flutter/material.dart';

import '../api.dart';
import '../l10n.dart';
import '../theme.dart';
import '../widgets.dart';

// ---------------- Monthly report ----------------
/// The monthly report: HR's items (item · target · success indicator) plus the employee's own items, and the
/// development of the month pulled automatically from the development register.
class MonthlyReportScreen extends StatefulWidget {
  const MonthlyReportScreen({super.key});
  @override
  State<MonthlyReportScreen> createState() => _MonthlyReportScreenState();
}

class _MonthlyReportScreenState extends State<MonthlyReportScreen> {
  Map<String, dynamic>? rep;
  bool busy = false;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await Api.get('/reports/monthly/mine');
      if (mounted) setState(() => rep = Map<String, dynamic>.from(r));
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    }
  }

  Future<void> save({bool submit = false}) async {
    setState(() => busy = true);
    try {
      await Api.put('/reports/monthly/${rep!['id']}', {'items': rep!['items'], 'dev': rep!['dev']});
      if (submit) await Api.post('/reports/monthly/${rep!['id']}/submit');
      if (mounted) toast(context, tr(submit ? 'أُرسل التقرير إلى مديرك' : 'حُفظت المسودة'));
      load();
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  int get ownCount => ((rep?['items'] as List?) ?? []).where((i) => i['source'] == 'emp').length;
  int get ownMax => (rep?['limits']?['emp_items'] ?? 0) as int;

  void addOwn() => setState(() => (rep!['items'] as List).add({'title': '', 'target': '', 'indicator': '', 'weight': 5, 'source': 'emp', 'achieved': '', 'pct': 0}));

  @override
  Widget build(BuildContext context) {
    final r = rep;
    final edit = r?['can_edit'] == true;
    return Scaffold(
      appBar: AppBar(title: Text(tr('تقريري الشهري'))),
      body: r == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(12), children: [
              Card(child: ListTile(title: Text('${r['period']} — ${r['status_label']}', style: const TextStyle(fontWeight: FontWeight.w800)), subtitle: r['preview'] != null ? Text('${tr('الدرجة المتوقعة')} ${r['preview']['score']} · ${r['preview']['grade']['label']}') : null)),
              if (r['return_note'] != null) Card(color: const Color(0xFFFDE8E6), child: ListTile(title: Text('${tr('سبب الإرجاع')}: ${r['return_note']}'))),
              for (final it in (r['items'] as List)) _item(context, it as Map, edit),
              if (edit && ownCount < ownMax)
                Align(alignment: AlignmentDirectional.centerStart, child: TextButton.icon(onPressed: addOwn, icon: const Icon(Icons.add), label: Text('${tr('بند شخصي')} (${tr('حتى')} $ownMax)'))),
              const SizedBox(height: 8),
              _devCard(context, r),
              const SizedBox(height: 12),
              if (edit)
                Row(children: [
                  Expanded(child: OutlinedButton(onPressed: busy ? null : () => save(), child: Text(tr('حفظ مسودة')))),
                  const SizedBox(width: 8),
                  Expanded(child: FilledButton(onPressed: busy ? null : () => save(submit: true), child: Text(tr('إرسال للمدير')))),
                ]),
            ]),
    );
  }

  Widget _item(BuildContext context, Map it, bool edit) {
    final own = it['source'] == 'emp';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (own && edit) ...[
            TextFormField(initialValue: it['title'] ?? '', decoration: InputDecoration(labelText: tr('البند (بند شخصي)')), onChanged: (v) => it['title'] = v),
            Row(children: [
              Expanded(child: TextFormField(initialValue: it['target'] ?? '', decoration: InputDecoration(labelText: tr('المستهدف')), onChanged: (v) => it['target'] = v)),
              const SizedBox(width: 8),
              Expanded(child: TextFormField(initialValue: it['indicator'] ?? '', decoration: InputDecoration(labelText: tr('مؤشر النجاح')), onChanged: (v) => it['indicator'] = v)),
            ]),
            Row(children: [
              SizedBox(width: 90, child: TextFormField(initialValue: '${it['weight'] ?? 5}', keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('الوزن')), onChanged: (v) => it['weight'] = num.tryParse(v) ?? 0)),
              const Spacer(),
              TextButton.icon(onPressed: () => setState(() => (rep!['items'] as List).remove(it)), icon: const Icon(Icons.delete_outline, color: HcColors.bad), label: Text(tr('حذف'), style: const TextStyle(color: HcColors.bad))),
            ]),
          ] else ...[
            Text('${it['title']}  (${tr('وزن')} ${it['weight']})', style: const TextStyle(fontWeight: FontWeight.w800)),
            if ((it['target'] ?? '').toString().isNotEmpty) Text('${tr('المستهدف')}: ${it['target']}', style: const TextStyle(color: Colors.grey, fontSize: 12)),
            if ((it['indicator'] ?? '').toString().isNotEmpty) Text('${tr('مؤشر النجاح')}: ${it['indicator']}', style: const TextStyle(color: Colors.grey, fontSize: 12)),
            if (own) Text(it['mgr_approved'] == false ? tr('بند شخصي — رفضه المشرف') : it['mgr_approved'] == true ? tr('بند شخصي — وافق عليه المشرف') : tr('بند شخصي — بانتظار موافقة المشرف'), style: TextStyle(color: it['mgr_approved'] == false ? HcColors.bad : HcColors.purple, fontSize: 12)),
          ],
          const SizedBox(height: 8),
          TextFormField(initialValue: it['achieved'] ?? '', maxLines: 2, enabled: edit, decoration: InputDecoration(labelText: tr('ماذا أنجزت؟')), onChanged: (v) => it['achieved'] = v),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: Slider(value: (num.tryParse('${it['pct'] ?? 0}') ?? 0).toDouble().clamp(0.0, 100.0).toDouble(), min: 0, max: 100, divisions: 20, label: '${it['pct'] ?? 0}%', onChanged: edit ? (v) => setState(() => it['pct'] = v.round()) : null)),
            SizedBox(width: 48, child: Text('${it['pct'] ?? 0}%', style: const TextStyle(fontWeight: FontWeight.w800))),
          ]),
          if (it['mgr_score'] != null) Text('${tr('تقييم المدير')}: ${it['mgr_score']}% ${it['mgr_note'] != null ? '— ${it['mgr_note']}' : ''}', style: const TextStyle(color: Colors.blue, fontSize: 12)),
        ]),
      ),
    );
  }

  Widget _devCard(BuildContext context, Map r) {
    final list = (r['dev_records'] as List?) ?? [];
    final pts = r['dev_points'];
    return HcCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr('التطوير خلال الشهر'), style: context.t.titleLarge),
        Text('${tr('يُدرج تلقائيًا من سجل التطوير')} · ${pts?['total'] ?? 0} / ${pts?['target'] ?? 100} ${tr('نقطة')}', style: context.t.labelSmall),
        const SizedBox(height: 8),
        if (list.isEmpty) Text(tr('لا تطوير مسجّل لهذا الشهر — سجّله من «التطوير والتدريب» ليظهر هنا'), style: context.t.labelMedium),
        for (final d in list.cast<Map>())
          ListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            leading: Icon(Icons.school_outlined, color: d['counted'] == true ? HcColors.ok : HcColors.warn),
            title: Text('${d['program']}', style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text([d['provider'], d['activity_name'], if (d['hours'] != null) '${d['hours']} ${tr('ساعة')}'].where((x) => x != null && '$x'.isNotEmpty).join(' · ')),
            trailing: Text(d['counted'] == true ? '${d['points']} ${tr('نقطة')}' : '${d['status_label']}', style: TextStyle(color: d['counted'] == true ? HcColors.ok : HcColors.warn, fontWeight: FontWeight.w700, fontSize: 12)),
          ),
      ]),
    );
  }
}

// ---------------- Weekly ----------------
const _weekLabel = {'submitted': 'في الموعد', 'late': 'متأخر', 'missing': 'لم يُرفع', 'open': 'لم يُرفع بعد'};
const _weekColor = {'submitted': HcColors.ok, 'late': HcColors.warn, 'missing': HcColors.bad, 'open': Colors.grey};
const _planLabel = {'none': 'الخطة لم تُرفع', 'submitted': 'الخطة بانتظار المدير', 'approved': 'الخطة معتمدة', 'returned': 'أُرجعت الخطة للتعديل'};
const _planColor = {'none': Colors.grey, 'submitted': HcColors.deepBlue, 'approved': HcColors.ok, 'returned': HcColors.bad};

String weekState(Map w) {
  if (w['status'] != 'missing') return '${w['status']}';
  final due = DateTime.tryParse('${w['due_at'] ?? ''}'.replaceFirst(' ', 'T'));
  return due != null && due.isAfter(DateTime.now()) ? 'open' : 'missing';
}

/// Weekly reports (owner's notes): the week runs Sunday–Thursday (Saturday for six-day employees); a plan at the start
/// of the week for the manager's approval, then the achievement before the deadline the manager set. Late is orange,
/// missing red — noted in the personal file and deducted from the monthly evaluation.
class WeeklyScreen extends StatefulWidget {
  const WeeklyScreen({super.key});
  @override
  State<WeeklyScreen> createState() => _WeeklyScreenState();
}

class _WeeklyScreenState extends State<WeeklyScreen> {
  List? weeks;
  Object? error;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await Api.get('/reports/weekly/mine');
      if (mounted) setState(() { weeks = r as List; error = null; });
    } catch (e) {
      if (mounted) setState(() => error = e);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('التقرير الأسبوعي'))),
        body: error != null
            ? EmptyView(errText(error!))
            : weeks == null
                ? const Center(child: CircularProgressIndicator())
                : RefreshIndicator(
                    onRefresh: load,
                    child: ListView(padding: const EdgeInsets.all(12), children: [
                      if (weeks!.isEmpty) EmptyView(tr('لا أسابيع مطلوبة منك هذا الشهر')),
                      for (final w in weeks!.cast<Map>()) _week(context, w),
                    ]),
                  ),
      );

  Widget _week(BuildContext context, Map w) {
    final st = weekState(w);
    final plan = '${w['plan_status'] ?? 'none'}';
    final color = _weekColor[st] ?? Colors.grey;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: HcCard(
        color: st == 'missing' ? HcColors.bad.withValues(alpha: .07) : st == 'late' ? HcColors.warn.withValues(alpha: .08) : null,
        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => WeekScreen(week: Map<String, dynamic>.from(w)))).then((_) => load()),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text('${fmtDate(w['week_start'])} → ${fmtDate(w['week_end'])}', style: context.t.bodyLarge?.copyWith(fontWeight: FontWeight.w800))),
            StatusBadge(tr(_weekLabel[st] ?? ''), color: color),
          ]),
          const SizedBox(height: 4),
          Text('${tr('موعد رفع المنجز')}: ${fmtDateTime(w['due_at'])} · ${w['due_rule'] ?? ''}', style: context.t.labelSmall),
          const SizedBox(height: 6),
          Row(children: [
            StatusBadge(tr(_planLabel[plan] ?? ''), color: _planColor[plan] ?? Colors.grey),
            const Spacer(),
            Text(tr(plan == 'none' || plan == 'returned' ? 'ارفع الخطة' : w['status'] == 'missing' ? 'ارفع المنجز' : 'عرض'), style: context.t.labelMedium?.copyWith(color: HcColors.deepBlue, fontWeight: FontWeight.w700)),
          ]),
          if (plan == 'returned' && (w['mgr_note'] ?? '').toString().isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text('${tr('ملاحظة المدير')}: ${w['mgr_note']}', style: context.t.labelSmall?.copyWith(color: HcColors.bad))),
        ]),
      ),
    );
  }
}

/// One week: the plan (items with their period target and this week's share) or the achievement against it.
class WeekScreen extends StatefulWidget {
  final Map<String, dynamic> week;
  const WeekScreen({super.key, required this.week});
  @override
  State<WeekScreen> createState() => _WeekScreenState();
}

class _WeekScreenState extends State<WeekScreen> {
  late Map<String, dynamic> w = widget.week;
  late List<Map<String, dynamic>> items = ((w['items'] as List?) ?? []).map((i) => {'horizon': 'month', ...Map<String, dynamic>.from(i as Map)}).toList();
  late final done = TextEditingController(text: '${w['done'] ?? ''}');
  late final blockers = TextEditingController(text: '${w['blockers'] ?? ''}');
  late final next = TextEditingController(text: '${w['next'] ?? ''}');
  bool busy = false;

  String get plan => '${w['plan_status'] ?? 'none'}';
  bool get planMode => (plan == 'none' || plan == 'returned' || plan == 'submitted') && w['status'] == 'missing' && !_reportChosen;
  bool _reportChosen = false;

  /// "20 عميل" over 4 weeks (13 for 3 months) → "5 عميل"; rates and text targets are left as they are.
  String share(Map it) {
    final t = '${it['period_target'] ?? ''}'.trim().replaceAllMapped(RegExp('[٠-٩]'), (m) => '${m[0]!.codeUnitAt(0) - 0x660}');
    final m = RegExp(r'^(\d+(?:\.\d+)?)\s*(%?)\s*(.*)$').firstMatch(t);
    if (m == null) return '';
    if (m[2]!.isNotEmpty) return '${m[1]}%${m[3]!.isNotEmpty ? ' ${m[3]}' : ''}';
    final n = it['horizon'] == 'quarter' ? 13 : 4;
    final v = (double.parse(m[1]!) / n * 10).round() / 10;
    return '${v == v.roundToDouble() ? v.toInt() : v}${m[3]!.isNotEmpty ? ' ${m[3]}' : ''}';
  }

  Future<void> seed(String source) async {
    try {
      final r = await Api.get('/reports/weekly/${w['id']}/from-monthly', {'source': source});
      final list = (r as List).map((i) => {'horizon': 'month', ...Map<String, dynamic>.from(i as Map)}).toList();
      if (list.isEmpty) {
        if (mounted) toast(context, tr(source == 'prev' ? 'لا خطة للأسبوع الماضي' : 'لا بنود في التقرير الشهري بعد'), error: true);
        return;
      }
      setState(() => items = list);
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    }
  }

  Future<void> sendPlan() async {
    final list = items.where((i) => '${i['title'] ?? ''}'.trim().isNotEmpty).toList();
    if (list.isEmpty) return toast(context, tr('أضف بندًا واحدًا على الأقل'), error: true);
    setState(() => busy = true);
    try {
      await Api.post('/reports/weekly/${w['id']}/plan', {'items': list});
      if (mounted) {
        toast(context, tr('رُفعت الخطة لاعتماد المدير'));
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> sendReport() async {
    if (done.text.trim().isEmpty && !items.any((i) => '${i['achieved'] ?? ''}'.trim().isNotEmpty)) return toast(context, tr('اكتب المنجز مقابل البنود أو ملخص ما أُنجز'), error: true);
    setState(() => busy = true);
    try {
      await Api.post('/reports/weekly/${w['id']}', {'items': items, 'done': done.text.trim(), 'blockers': blockers.text.trim(), 'next': next.text.trim()});
      if (mounted) {
        toast(context, tr('رُفع التقرير الأسبوعي'));
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final canReport = plan == 'submitted' || plan == 'approved';
    return Scaffold(
      appBar: AppBar(title: Text('${tr(planMode ? 'خطة الأسبوع' : 'منجز الأسبوع')} ${fmtDate(w['week_start'])}')),
      body: ListView(padding: const EdgeInsets.all(12), children: [
        if (plan == 'returned' && (w['mgr_note'] ?? '').toString().isNotEmpty)
          Card(color: HcColors.bad.withValues(alpha: .1), child: ListTile(leading: const Icon(Icons.undo, color: HcColors.bad), title: Text('${tr('أعاد مديرك الخطة')}: ${w['mgr_note']}'))),
        if (planMode) ...[
          Text(tr('لكل بند مستهدف لمدة شهر أو 3 أشهر (أو من تقريرك الشهري)، ويُقسَّم إلى مستهدف هذا الأسبوع ويمكنك تعديله. ثم ترفع الخطة لمديرك ليعتمدها.'), style: context.t.labelMedium),
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: [
            OutlinedButton.icon(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 42)), onPressed: () => seed('monthly'), icon: const Icon(Icons.download_outlined, size: 18), label: Text(tr('من التقرير الشهري'))),
            OutlinedButton.icon(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 42)), onPressed: () => seed('prev'), icon: const Icon(Icons.repeat, size: 18), label: Text(tr('من الأسبوع الماضي'))),
            TextButton.icon(onPressed: () => setState(() => items.add({'axis': '', 'title': '', 'kpi': '', 'horizon': 'month', 'period_target': '', 'target': '', 'source': 'own'})), icon: const Icon(Icons.add), label: Text(tr('بند'))),
          ]),
          const SizedBox(height: 8),
          for (final (i, it) in items.indexed) _planItem(context, it, i),
          const SizedBox(height: 12),
          SizedBox(height: 48, child: FilledButton.icon(onPressed: busy ? null : sendPlan, icon: const Icon(Icons.send), label: Text(tr('رفع الخطة لاعتماد المدير')))),
          if (canReport) TextButton(onPressed: () => setState(() => _reportChosen = true), child: Text(tr('الخطة مرفوعة — انتقل إلى رفع المنجز'))),
        ] else ...[
          if (!canReport) EmptyView(tr('ارفع خطة هذا الأسبوع أولًا ليعتمدها مديرك، ثم ارفع المنجز')),
          for (final it in items) _reportItem(context, it),
          const SizedBox(height: 8),
          TextField(controller: done, maxLines: 3, enabled: canReport, decoration: InputDecoration(labelText: tr('ملخص ما أُنجز'))),
          const SizedBox(height: 8),
          TextField(controller: blockers, enabled: canReport, decoration: InputDecoration(labelText: tr('العقبات'))),
          const SizedBox(height: 8),
          TextField(controller: next, enabled: canReport, decoration: InputDecoration(labelText: tr('ماذا ستفعل الأسبوع القادم'))),
          const SizedBox(height: 6),
          Text('${tr('موعد الرفع')}: ${fmtDateTime(w['due_at'])} (${w['due_rule'] ?? ''})', style: context.t.labelSmall),
          const SizedBox(height: 12),
          if (canReport) SizedBox(height: 48, child: FilledButton.icon(onPressed: busy ? null : sendReport, icon: const Icon(Icons.send), label: Text(tr('رفع المنجز')))),
        ],
      ]),
    );
  }

  Widget _planItem(BuildContext context, Map<String, dynamic> it, int i) => Card(
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(children: [
            Row(children: [
              Expanded(child: TextFormField(initialValue: it['title'] ?? '', decoration: InputDecoration(labelText: tr('البند *')), onChanged: (v) => it['title'] = v)),
              IconButton(onPressed: () => setState(() => items.removeAt(i)), icon: const Icon(Icons.close)),
            ]),
            Row(children: [
              Expanded(child: TextFormField(initialValue: it['axis'] ?? '', decoration: InputDecoration(labelText: tr('المحور')), onChanged: (v) => it['axis'] = v)),
              const SizedBox(width: 8),
              Expanded(child: TextFormField(initialValue: it['kpi'] ?? '', decoration: InputDecoration(labelText: tr('المؤشر')), onChanged: (v) => it['kpi'] = v)),
            ]),
            const SizedBox(height: 6),
            Row(children: [
              Expanded(
                child: SearchPicker<String>(label: tr('مدة المستهدف'), value: '${it['horizon'] ?? 'month'}', items: [('month', tr('شهر')), ('quarter', tr('3 أشهر'))], onChanged: (v) => setState(() {
                  it['horizon'] = v;
                  final x = share(it);
                  if (x.isNotEmpty) it['target'] = x;
                })),
              ),
              const SizedBox(width: 8),
              Expanded(child: TextFormField(initialValue: it['period_target'] ?? '', decoration: InputDecoration(labelText: tr('المستهدف للمدة')), onChanged: (v) => setState(() {
                it['period_target'] = v;
                final x = share(it);
                if (x.isNotEmpty) it['target'] = x;
              }))),
            ]),
            TextFormField(key: ValueKey('t$i${it['target']}'), initialValue: it['target'] ?? '', decoration: InputDecoration(labelText: tr('مستهدف هذا الأسبوع')), onChanged: (v) => it['target'] = v),
          ]),
        ),
      );

  Widget _reportItem(BuildContext context, Map<String, dynamic> it) => Card(
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${it['title']}', style: const TextStyle(fontWeight: FontWeight.w800)),
            Text('${tr('مستهدف الأسبوع')}: ${(it['target'] ?? '').toString().isEmpty ? '—' : it['target']}${(it['period_target'] ?? '').toString().isNotEmpty ? ' · ${tr(it['horizon'] == 'quarter' ? 'مستهدف 3 أشهر' : 'مستهدف الشهر')}: ${it['period_target']}' : ''}', style: context.t.labelSmall),
            TextFormField(initialValue: it['achieved'] ?? '', maxLines: 2, decoration: InputDecoration(labelText: tr('المنجز')), onChanged: (v) => it['achieved'] = v),
            TextFormField(initialValue: it['next_target'] ?? '', decoration: InputDecoration(labelText: tr('مستهدف الأسبوع القادم')), onChanged: (v) => it['next_target'] = v),
          ]),
        ),
      );
}
