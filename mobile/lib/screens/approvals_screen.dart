import 'package:flutter/material.dart';

import '../api.dart';
import '../l10n.dart';
import '../widgets.dart';

class ApprovalsScreen extends StatefulWidget {
  const ApprovalsScreen({super.key});
  @override
  State<ApprovalsScreen> createState() => _ApprovalsScreenState();
}

class _ApprovalsScreenState extends State<ApprovalsScreen> {
  List? rows;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await Api.get('/requests/inbox');
      if (mounted) setState(() => rows = r as List);
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    }
  }

  Future<void> decide(Map r, String decision) async {
    String? reason;
    if (decision == 'return') {
      final c = TextEditingController();
      reason = await showDialog<String>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(tr('سبب الإرجاع')),
          content: TextField(controller: c, maxLines: 3),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('إلغاء'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('إرجاع'))),
          ],
        ),
      );
      if (reason == null || reason.isEmpty) return;
    }
    try {
      await Api.post('/requests/${r['id']}/decide', {'decision': decision, 'reason': reason});
      if (mounted) toast(context, decision == 'approve' ? tr('اعتماد') : tr('إرجاع'));
      load();
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Row(mainAxisSize: MainAxisSize.min, children: [Text(tr('الاعتمادات')), if ((rows ?? const []).isNotEmpty) ...[const SizedBox(width: 8), CountPill('${rows!.length}')]])),
      body: RefreshIndicator(
        onRefresh: load,
        child: rows == null
            ? const Center(child: CircularProgressIndicator())
            : rows!.isEmpty
                ? ListView(children: [EmptyView(tr('لا توجد بيانات'))])
                : ListView.builder(
                    padding: const EdgeInsets.all(12),
                    itemCount: rows!.length,
                    itemBuilder: (_, i) {
                      final r = rows![i];
                      final age = r['age_days'] ?? 0;
                      return Card(
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Row(children: [
                              Expanded(child: Text('${r['type_label']} — ${r['employee']?['name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w800))),
                              if (age >= 3) Text('$age d', style: const TextStyle(color: Colors.red, fontWeight: FontWeight.w700)),
                            ]),
                            const SizedBox(height: 4),
                            Text(r['summary'] ?? ''),
                            Text('${r['company'] ?? ''} · ${r['stage_label'] ?? ''}', style: const TextStyle(color: Colors.grey, fontSize: 12)),
                            const SizedBox(height: 8),
                            Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                              OutlinedButton(onPressed: () => decide(r, 'return'), child: Text(tr('إرجاع'))),
                              const SizedBox(width: 8),
                              FilledButton(style: FilledButton.styleFrom(backgroundColor: const Color(0xFF17936B)), onPressed: () => decide(r, 'approve'), child: Text(tr('اعتماد'))),
                            ]),
                          ]),
                        ),
                      );
                    },
                  ),
      ),
    );
  }
}
