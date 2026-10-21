import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../l10n.dart';
import '../state.dart';
import '../widgets.dart';
import 'compose_mail_screen.dart';

const _custodyAck = 'أقرّ بأنني استلمت هذه العهدة، وأتحمّل مسؤولية المحافظة عليها واستخدامها في أغراض العمل فقط، وأوافق على استيفاء ما لا يُسوّى منها وفق أحكام الباب الثامن (المادة 43)، وأتعهد بتسوية العهدة النقدية بالفواتير والمستندات المؤيدة خلال المدة المحددة في المادة (44).';

/// Shared loader: fetch → list with pull-to-refresh, error and empty states.
class ApiList extends StatefulWidget {
  final String path;
  final Map<String, String>? query;
  final Widget Function(BuildContext, dynamic data, VoidCallback reload) builder;
  const ApiList({super.key, required this.path, required this.builder, this.query});
  @override
  State<ApiList> createState() => ApiListState();
}

class ApiListState extends State<ApiList> {
  dynamic data;
  Object? error;
  Future<void> load() async {
    try {
      final r = await Api.get(widget.path, widget.query);
      if (mounted) setState(() { data = r; error = null; });
    } catch (e) {
      if (mounted) setState(() => error = e);
    }
  }

  @override
  void initState() { super.initState(); load(); }

  @override
  Widget build(BuildContext context) {
    if (error != null) return EmptyView(errText(error!));
    if (data == null) return const Center(child: CircularProgressIndicator());
    return RefreshIndicator(onRefresh: load, child: widget.builder(context, data, load));
  }
}

String fmtMoney(dynamic n) => (num.tryParse('$n') ?? 0).toStringAsFixed(2).replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+\.)'), (m) => '${m[1]},');

Future<String?> promptText(BuildContext context, String title, {String? hint, int lines = 3}) async {
  final c = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(title),
      content: TextField(controller: c, maxLines: lines, decoration: InputDecoration(hintText: hint)),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('إلغاء'))), FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('إرسال')))],
    ),
  );
}

Future<void> act(BuildContext context, Future<dynamic> Function() fn, VoidCallback reload, {String ok = 'تم'}) async {
  try {
    await fn();
    if (context.mounted) toast(context, tr(ok));
    reload();
  } catch (e) {
    if (context.mounted) toast(context, errText(e), error: true);
  }
}

// ---------------- Payslips ----------------
class PayslipsScreen extends StatelessWidget {
  const PayslipsScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('قسائم الراتب'))),
        body: ApiList(path: '/payroll/payslips/mine', builder: (ctx, data, reload) {
          final list = data as List;
          if (list.isEmpty) return EmptyView(tr('لا توجد بيانات'));
          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final p in list)
              Card(child: ListTile(
                title: Text(p['period'] ?? '', style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text('${tr('الإجمالي')} ${fmtMoney(p['gross'])} · ${tr('الاستقطاعات')} ${fmtMoney(p['ded_total'])}'),
                trailing: Text(fmtMoney(p['net']), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900, color: Colors.green)),
                onTap: () => Navigator.push(ctx, MaterialPageRoute(builder: (_) => PayslipDetail(id: p['id']))),
              )),
          ]);
        }),
      );
}

class PayslipDetail extends StatelessWidget {
  final int id;
  const PayslipDetail({super.key, required this.id});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('قسيمة الراتب'))),
        body: ApiList(path: '/payroll/payslips/$id', builder: (ctx, d, reload) {
          final line = d['payslip']['line'];
          Widget rows(List items, Color c) => Column(children: [for (final x in items) ListTile(dense: true, title: Text(x['name']), trailing: Text(fmtMoney(x['amount']), style: TextStyle(color: c, fontWeight: FontWeight.w700)))]);
          return ListView(padding: const EdgeInsets.all(12), children: [
            KpiTile(label: '${tr('صافي المستحق')} ${d['payslip']['period']}', value: '${fmtMoney(d['net'])} ر.س', color: Colors.green),
            Card(child: Column(children: [ListTile(title: Text(tr('المستحقات'), style: const TextStyle(fontWeight: FontWeight.w800))), rows(line['earnings'] as List, Colors.green)])),
            Card(child: Column(children: [ListTile(title: Text(tr('الاستقطاعات'), style: const TextStyle(fontWeight: FontWeight.w800))), rows(line['deductions'] as List, Colors.red)])),
          ]);
        }),
      );
}

// ---------------- Custody ----------------
class CustodyScreen extends StatelessWidget {
  const CustodyScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('عهدي'))),
        body: ApiList(path: '/custody/mine', builder: (ctx, data, reload) {
          final list = data as List;
          if (list.isEmpty) return EmptyView(tr('لا توجد بيانات'));
          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final i in list)
              Card(child: ListTile(
                title: Text(i['ident'], style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text('${i['type']['name']} · ${fmtMoney(i['value'])} · ${i['status_label']}'),
                trailing: i['status'] == 'pending_ack'
                    ? FilledButton(onPressed: () async {
                        // §43: the employee reads and accepts the undertaking before acknowledging the custody
                        final yes = await showDialog<bool>(context: ctx, builder: (d) => AlertDialog(
                              title: Text(tr('إقرار استلام العهدة')),
                              content: Text((i['ack_text'] ?? _custodyAck).toString()),
                              actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('إلغاء'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('أوافق وأقر')))],
                            ));
                        if (yes == true && ctx.mounted) act(ctx, () => Api.post('/custody/${i['id']}/ack', {'agree': true}), reload, ok: 'أقررت بالاستلام');
                      }, child: Text(tr('إقرار الاستلام')))
                    : i['status'] == 'in_use' && i['type']['is_cash'] != true
                        ? OutlinedButton(onPressed: () => act(ctx, () => Api.post('/custody/${i['id']}/request-return'), reload), child: Text(tr('طلب استرداد')))
                        : null,
              )),
          ]);
        }),
      );
}

// ---------------- Announcements ----------------
class AnnouncementsScreen extends StatelessWidget {
  const AnnouncementsScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('الإعلانات'))),
        body: ApiList(path: '/announcements', builder: (ctx, data, reload) {
          final list = data as List;
          if (list.isEmpty) return EmptyView(tr('لا توجد بيانات'));
          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final a in list)
              Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(a['title'], style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                Text('${a['sender']} · ${(a['published_at'] ?? '').toString().substring(0, 10)}', style: const TextStyle(color: Colors.grey, fontSize: 12)),
                const SizedBox(height: 6),
                Text(a['body'] ?? ''),
                if (a['require_ack'] == true && a['ack_at'] == null && a['is_sender'] != true)
                  Align(alignment: AlignmentDirectional.centerEnd, child: FilledButton.icon(icon: const Icon(Icons.check_circle_outline), label: Text(tr('أقرّ بالاطلاع')), onPressed: () => act(ctx, () => Api.post('/announcements/${a['id']}/ack'), reload))),
                if (a['ack_at'] != null) Text(tr('أقررت بالاطلاع'), style: const TextStyle(color: Colors.green, fontSize: 12)),
              ]))),
          ]);
        }),
      );
}

// ---------------- Documents ----------------
class DocumentsScreen extends StatelessWidget {
  const DocumentsScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('وثائقي'))),
        body: ApiList(path: '/documents/mine', builder: (ctx, data, reload) {
          final list = data as List;
          if (list.isEmpty) return EmptyView(tr('لا توجد بيانات'));
          const colors = {'ok': Colors.green, 'warn': Colors.orange, 'bad': Colors.red, 'info': Colors.blue, 'gray': Colors.grey};
          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final d in list)
              Card(child: ListTile(
                leading: Icon(Icons.description_outlined, color: colors[d['state']['color']] ?? Colors.grey),
                title: Text(d['type_name'], style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text('${d['number'] ?? '—'} · ${tr('ينتهي')} ${d['expiry_date'] ?? '—'}'),
                trailing: Text(d['state']['label'], style: TextStyle(color: colors[d['state']['color']] ?? Colors.grey, fontWeight: FontWeight.w700)),
              )),
          ]);
        }),
      );
}

// ---------------- Evaluation ----------------
class EvaluationScreen extends StatelessWidget {
  const EvaluationScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('تقييماتي'))),
        body: ApiList(path: '/evaluation/mine', builder: (ctx, d, reload) {
          final list = d['monthly'] as List;
          final tpl = d['template'];
          return ListView(padding: const EdgeInsets.all(12), children: [
            Row(children: [
              Expanded(child: KpiTile(label: tr('آخر تقييم'), value: list.isEmpty ? '—' : '${list.first['score']}', hint: list.isEmpty ? null : '${list.first['grade']} · ${list.first['grade_label']}', color: Colors.green)),
              Expanded(child: KpiTile(label: tr('المعدل السنوي'), value: d['annual'] == null ? '—' : '${d['annual']['score']}', hint: '${tr('حد القبول')} ${tpl['pass_score']}%', color: Colors.blue)),
            ]),
            if (list.isEmpty) EmptyView(tr('لا تقييمات منشورة')),
            for (final e in list)
              Card(child: ListTile(
                title: Text(e['period'], style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text('${tr('الإنجاز')} ${e['axes']['achieve']} · ${tr('الحضور')} ${e['axes']['attend'] ?? tr('معفى')} · ${tr('التطوير')} ${e['axes']['dev']} · ${tr('الانضباط')} ${e['axes']['disc']}'),
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, children: [Text('${e['score']}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900)), Text(e['grade'], style: const TextStyle(fontSize: 11))]),
                onTap: e['objection_status'] == 'none'
                    ? () async {
                        final txt = await promptText(ctx, tr('اعتراض على التقييم'));
                        if (txt != null && txt.isNotEmpty && ctx.mounted) act(ctx, () => Api.post('/evaluations/${e['id']}/object', {'text': txt}), reload, ok: 'قُدّم الاعتراض');
                      }
                    : null,
              )),
          ]);
        }),
      );
}

// ---------------- Monthly report ----------------
class MonthlyReportScreen extends StatefulWidget {
  const MonthlyReportScreen({super.key});
  @override
  State<MonthlyReportScreen> createState() => _MonthlyReportScreenState();
}

class _MonthlyReportScreenState extends State<MonthlyReportScreen> {
  Map<String, dynamic>? rep;
  bool busy = false;
  @override
  void initState() { super.initState(); load(); }
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

  @override
  Widget build(BuildContext context) {
    final r = rep;
    return Scaffold(
      appBar: AppBar(title: Text(tr('تقريري الشهري'))),
      body: r == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(12), children: [
              Card(child: ListTile(title: Text('${r['period']} — ${r['status_label']}', style: const TextStyle(fontWeight: FontWeight.w800)), subtitle: r['preview'] != null ? Text('${tr('الدرجة المتوقعة')} ${r['preview']['score']} · ${r['preview']['grade']['label']}') : null)),
              if (r['return_note'] != null) Card(color: const Color(0xFFFDE8E6), child: ListTile(title: Text('${tr('سبب الإرجاع')}: ${r['return_note']}'))),
              for (final it in (r['items'] as List))
                Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${it['title']}  (${tr('وزن')} ${it['weight']})', style: const TextStyle(fontWeight: FontWeight.w800)),
                  if (it['target'] != null) Text('${it['target']}', style: const TextStyle(color: Colors.grey, fontSize: 12)),
                  const SizedBox(height: 8),
                  TextFormField(initialValue: it['achieved'] ?? '', maxLines: 2, enabled: r['can_edit'] == true, decoration: InputDecoration(labelText: tr('ماذا أنجزت؟')), onChanged: (v) => it['achieved'] = v),
                  const SizedBox(height: 8),
                  Row(children: [
                    Expanded(child: Slider(value: (num.tryParse('${it['pct'] ?? 0}') ?? 0).toDouble().clamp(0.0, 100.0).toDouble(), min: 0, max: 100, divisions: 20, label: '${it['pct'] ?? 0}%', onChanged: r['can_edit'] == true ? (v) => setState(() => it['pct'] = v.round()) : null)),
                    SizedBox(width: 48, child: Text('${it['pct'] ?? 0}%', style: const TextStyle(fontWeight: FontWeight.w800))),
                  ]),
                  if (it['mgr_score'] != null) Text('${tr('تقييم المدير')}: ${it['mgr_score']}% ${it['mgr_note'] != null ? '— ${it['mgr_note']}' : ''}', style: const TextStyle(color: Colors.blue, fontSize: 12)),
                ]))),
              if (r['can_edit'] == true)
                Row(children: [
                  Expanded(child: OutlinedButton(onPressed: busy ? null : () => save(), child: Text(tr('حفظ مسودة')))),
                  const SizedBox(width: 8),
                  Expanded(child: FilledButton(onPressed: busy ? null : () => save(submit: true), child: Text(tr('إرسال للمدير')))),
                ]),
            ]),
    );
  }
}

// ---------------- Weekly ----------------
class WeeklyScreen extends StatelessWidget {
  const WeeklyScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('التقرير الأسبوعي'))),
        body: ApiList(path: '/reports/weekly/mine', builder: (ctx, data, reload) {
          final list = data as List;
          const labels = {'submitted': 'في الموعد', 'late': 'متأخر', 'missing': 'لم يُرفع'};
          const colors = {'submitted': Colors.green, 'late': Colors.orange, 'missing': Colors.red};
          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final w in list)
              Card(child: ListTile(
                title: Text('${w['week_start']} → ${w['week_end']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text(w['done'] ?? tr('لم يُرفع')),
                trailing: Text(tr(labels[w['status']] ?? ''), style: TextStyle(color: colors[w['status']], fontWeight: FontWeight.w700)),
                onTap: () async {
                  final txt = await promptText(ctx, tr('ما أُنجز هذا الأسبوع'), lines: 5);
                  if (txt != null && txt.isNotEmpty && ctx.mounted) act(ctx, () => Api.post('/reports/weekly/${w['id']}', {'done': txt}), reload, ok: 'رُفع التقرير الأسبوعي');
                },
              )),
          ]);
        }),
      );
}

// ---------------- Letters ----------------
class LettersScreen extends StatelessWidget {
  const LettersScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('خطاباتي'))),
        body: ApiList(path: '/letters/mine', builder: (ctx, data, reload) {
          final list = data as List;
          if (list.isEmpty) return EmptyView(tr('لا توجد بيانات'));
          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final l in list)
              Card(child: ExpansionTile(
                title: Text('${l['kind_name']} — ${l['no']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text('${l['to'] ?? ''} · ${l['issued_at']} · ${l['signed'] == true ? tr('موقَّع') : tr('غير موقَّع')}'),
                children: [Padding(padding: const EdgeInsets.all(12), child: Text(l['body'] ?? '', textDirection: l['lang'] == 'en' ? TextDirection.ltr : TextDirection.rtl))],
              )),
          ]);
        }),
      );
}

// ---------------- Insurance ----------------
class InsuranceScreen extends StatelessWidget {
  const InsuranceScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('التأمين الطبي'))),
        body: ApiList(path: '/insurance/mine', builder: (ctx, d, reload) {
          final ins = d['insurance'];
          if (ins == null) return EmptyView('${tr('لم تُضف بعد إلى وثيقة تأمين')} · ${tr('الفئة')} ${d['tier_class'] ?? '—'}');
          return ListView(padding: const EdgeInsets.all(12), children: [
            KpiTile(label: tr('شركة التأمين'), value: '${d['policy']?['provider'] ?? '—'}', hint: '${d['policy']?['policy_no'] ?? ''} · ${tr('ينتهي')} ${d['policy']?['to'] ?? ''}', color: Colors.blue),
            Row(children: [
              Expanded(child: KpiTile(label: tr('الفئة'), value: '${ins['class'] ?? '—'}', color: Colors.green)),
              Expanded(child: KpiTile(label: tr('التابعون'), value: '${ins['dependents'] ?? 0}', hint: '${tr('المسموح')} ${d['tier_dependents'] ?? '—'}', color: Colors.orange)),
            ]),
            KpiTile(label: tr('رقم البطاقة'), value: '${ins['card'] ?? '—'}', color: Colors.purple),
          ]);
        }),
      );
}

// ---------------- Violations ----------------
class ViolationsScreen extends StatelessWidget {
  const ViolationsScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('مخالفاتي'))),
        body: ApiList(path: '/violations/mine', builder: (ctx, data, reload) {
          final list = data as List;
          if (list.isEmpty) return EmptyView(tr('لا مخالفات مسجلة بحقك'));
          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final v in list)
              Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${v['type_name']} · ${v['occurred_on']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                Text(v['description'] ?? ''),
                Text('${v['status_label']} · ${v['degree_name'] ?? '${v['proposed_name']} (${tr('مقترح')})'}${(num.tryParse('${v['amount']}') ?? 0) > 0 ? ' · ${tr('خصم')} ${fmtMoney(v['amount'])}' : ''}', style: const TextStyle(color: Colors.grey, fontSize: 12)),
                if (v['statement'] != null) Text('${tr('إفادتي')}: ${v['statement']}', style: const TextStyle(fontSize: 12)),
                if (v['status'] == 'awaiting_statement')
                  Align(alignment: AlignmentDirectional.centerEnd, child: FilledButton(onPressed: () async { final t = await promptText(ctx, tr('إفادتك'), lines: 5); if (t != null && t.isNotEmpty && ctx.mounted) act(ctx, () => Api.post('/violations/${v['id']}/statement', {'statement': t}), reload); }, child: Text(tr('كتابة الإفادة')))),
                if (v['status'] == 'active' && v['objection'] == 'none')
                  Align(alignment: AlignmentDirectional.centerEnd, child: OutlinedButton(onPressed: () async { final t = await promptText(ctx, tr('نص الاعتراض'), lines: 4); if (t != null && t.isNotEmpty && ctx.mounted) act(ctx, () => Api.post('/violations/${v['id']}/object', {'text': t}), reload); }, child: Text(tr('اعتراض')))),
              ]))),
          ]);
        }),
      );
}

// ---------------- Development ----------------
class DevelopmentScreen extends StatelessWidget {
  const DevelopmentScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('التطوير والتدريب'))),
        body: ApiList(path: '/development/mine', builder: (ctx, d, reload) {
          final list = d['records'] as List;
          const colors = {'planned': Colors.blue, 'pending': Colors.orange, 'verified': Colors.green, 'rejected': Colors.red};
          return ListView(padding: const EdgeInsets.all(12), children: [
            Row(children: [
              Expanded(child: KpiTile(label: tr('ميزانية التطوير'), value: fmtMoney(d['budget'] ?? 0), hint: '${tr('المصروف')} ${fmtMoney(d['spent'])}', color: Colors.green)),
              Expanded(child: KpiTile(label: tr('موثَّق'), value: '${list.where((r) => r['status'] == 'verified').length}', color: Colors.blue)),
            ]),
            FilledButton.icon(icon: const Icon(Icons.add), label: Text(tr('سجل تطوير')), onPressed: () async {
              final p = await promptText(ctx, tr('اسم البرنامج'), lines: 1);
              if (p == null || p.isEmpty) return;
              if (!ctx.mounted) return;
              final prov = await promptText(ctx, tr('الجهة'), lines: 1);
              if (!ctx.mounted) return;
              act(ctx, () => Api.post('/development', {'program': p, 'provider': prov}), reload, ok: 'أُرسل للتحقق');
            }),
            for (final r in list)
              Card(child: ListTile(
                title: Text(r['program'], style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text('${r['provider'] ?? ''} · ${r['from'] ?? ''} → ${r['to'] ?? ''} · ${r['hours']} ${tr('ساعة')}'),
                trailing: r['status'] == 'planned'
                    ? FilledButton(onPressed: () => act(ctx, () => Api.post('/development/${r['id']}/complete'), reload, ok: 'أُرسل للتحقق'), child: Text(tr('أكملته')))
                    : Text(r['status_label'], style: TextStyle(color: colors[r['status']], fontWeight: FontWeight.w700)),
              )),
          ]);
        }),
      );
}

// ---------------- Mail ----------------
class MailScreen extends StatelessWidget {
  const MailScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('البريد الداخلي'))),
        floatingActionButton: Builder(builder: (ctx) => FloatingActionButton.extended(icon: const Icon(Icons.edit_outlined), label: Text(tr('رسالة جديدة')), onPressed: () => Navigator.push(ctx, MaterialPageRoute(builder: (_) => const ComposeMailScreen())).then((_) { if (ctx.mounted) ctx.findAncestorStateOfType<ApiListState>()?.load(); }))),
        body: ApiList(path: '/mail', builder: (ctx, data, reload) {
          final list = data as List;
          if (list.isEmpty) return EmptyView(tr('لا رسائل'), hint: tr('ابدأ رسالة جديدة من الزر أدناه'));
          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final t in list)
              Card(child: ListTile(
                leading: Icon(t['unread'] == true ? Icons.mark_email_unread : Icons.mail_outline, color: t['unread'] == true ? Colors.blue : Colors.grey),
                title: Text(t['subject'], style: TextStyle(fontWeight: t['unread'] == true ? FontWeight.w900 : FontWeight.w700)),
                subtitle: Text('${t['others']} · ${t['preview']}'),
                onTap: () => Navigator.push(ctx, MaterialPageRoute(builder: (_) => MailThreadScreen(id: t['id']))).then((_) => reload()),
              )),
          ]);
        }),
      );
}

class MailThreadScreen extends StatelessWidget {
  final int id;
  const MailThreadScreen({super.key, required this.id});
  @override
  Widget build(BuildContext context) {
    final c = TextEditingController();
    return Scaffold(
      appBar: AppBar(title: Text(tr('المحادثة'))),
      body: ApiList(path: '/mail/$id', builder: (ctx, d, reload) {
        return Column(children: [
          Expanded(child: ListView(padding: const EdgeInsets.all(12), children: [
            Text(d['subject'], style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
            for (final m in (d['messages'] as List))
              Align(
                alignment: m['mine'] == true ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
                child: Card(color: m['mine'] == true ? const Color(0xFFE3F0FA) : null, child: Padding(padding: const EdgeInsets.all(10), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${m['from']?['name'] ?? ''}', style: const TextStyle(fontSize: 11, color: Colors.grey)), Text(m['body'] ?? ''),
                ]))),
              ),
          ])),
          SafeArea(child: Padding(padding: const EdgeInsets.all(8), child: Row(children: [
            Expanded(child: TextField(controller: c, decoration: InputDecoration(hintText: tr('اكتب ردًا…')))),
            IconButton.filled(icon: const Icon(Icons.send), onPressed: () { if (c.text.trim().isEmpty) return; act(ctx, () => Api.post('/mail/$id/reply', {'body': c.text.trim()}), reload); c.clear(); }),
          ]))),
        ]);
      }),
    );
  }
}

/// Signature-less helper for screens needing the app state.
AppState appState(BuildContext c) => c.read<AppState>();
