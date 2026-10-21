import 'package:flutter/material.dart';

import '../api.dart';
import '../l10n.dart';
import '../theme.dart';
import '../widgets.dart';

/// New internal message (§115): recipients inside the employee's scope, several at once, subject and body.
class ComposeMailScreen extends StatefulWidget {
  final int? toUserId;
  const ComposeMailScreen({super.key, this.toUserId});
  @override
  State<ComposeMailScreen> createState() => _ComposeMailScreenState();
}

class _ComposeMailScreenState extends State<ComposeMailScreen> {
  List<Map<String, dynamic>> users = [];
  final Set<int> to = {};
  final subject = TextEditingController();
  final body = TextEditingController();
  final search = TextEditingController();
  String q = '';
  bool busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.toUserId != null) to.add(widget.toUserId!);
    Api.get('/mail/recipients').then((r) {
      if (mounted) setState(() => users = (r as List).map((x) => Map<String, dynamic>.from(x)).toList());
    }).catchError((_) {});
  }

  Future<void> send() async {
    if (to.isEmpty || subject.text.trim().isEmpty || body.text.trim().isEmpty) {
      toast(context, tr('المستلم والموضوع والرسالة إلزامية'), error: true);
      return;
    }
    setState(() => busy = true);
    try {
      await Api.post('/mail', {'to': to.toList(), 'subject': subject.text.trim(), 'body': body.text.trim()});
      if (mounted) {
        toast(context, tr('أُرسلت الرسالة'));
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
    final shown = users.where((u) => q.isEmpty || '${u['name']} ${u['company'] ?? ''} ${u['role'] ?? ''}'.contains(q)).toList();
    return Scaffold(
      appBar: AppBar(title: Text(tr('رسالة جديدة')), actions: [IconButton(onPressed: busy ? null : send, icon: const Icon(Icons.send), tooltip: tr('إرسال'))]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        if (to.isNotEmpty)
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final id in to)
              Chip(label: Text(users.firstWhere((u) => u['id'] == id, orElse: () => {'name': '#$id'})['name']), onDeleted: () => setState(() => to.remove(id))),
          ]),
        TextField(controller: search, decoration: InputDecoration(hintText: tr('ابحث عن مستلم (الاسم أو الشركة)'), prefixIcon: const Icon(Icons.search)), onChanged: (v) => setState(() => q = v.trim())),
        const SizedBox(height: 6),
        Container(
          constraints: const BoxConstraints(maxHeight: 220),
          decoration: BoxDecoration(border: Border.all(color: context.line), borderRadius: BorderRadius.circular(12)),
          child: users.isEmpty
              ? Padding(padding: const EdgeInsets.all(16), child: Text(tr('جارٍ التحميل…'), style: context.t.labelMedium))
              : ListView(shrinkWrap: true, children: [
                  for (final u in shown.take(60))
                    CheckboxListTile(
                      dense: true,
                      value: to.contains(u['id']),
                      title: Text(u['name'] ?? ''),
                      subtitle: Text([u['role'], u['company']].whereType<String>().where((x) => x.isNotEmpty).join(' · '), maxLines: 1, overflow: TextOverflow.ellipsis),
                      onChanged: (v) => setState(() => v == true ? to.add(u['id'] as int) : to.remove(u['id'])),
                    ),
                ]),
        ),
        const SizedBox(height: 12),
        TextField(controller: subject, decoration: InputDecoration(labelText: tr('الموضوع'))),
        const SizedBox(height: 12),
        TextField(controller: body, minLines: 5, maxLines: 12, decoration: InputDecoration(labelText: tr('الرسالة'), alignLabelWithHint: true)),
        const SizedBox(height: 16),
        PrimaryButton(label: tr('إرسال'), icon: Icons.send, loading: busy, onPressed: send),
        const SizedBox(height: 8),
        Text(tr('تصل الرسالة داخل المنصة ونسخة منها إلى البريد الإلكتروني الرسمي للمستلم.'), style: context.t.labelSmall, textAlign: TextAlign.center),
      ]),
    );
  }
}
