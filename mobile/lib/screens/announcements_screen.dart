import 'package:flutter/material.dart';

import '../api.dart';
import '../l10n.dart';
import '../theme.dart';
import '../widgets.dart';

/// Announcements and manager alerts, read / unread like mail: opening one marks it read; the ones that require a read
/// confirmation keep their button until confirmed. A manager sends an alert to his team from here.
class AnnouncementsScreen extends StatefulWidget {
  const AnnouncementsScreen({super.key});
  @override
  State<AnnouncementsScreen> createState() => _AnnouncementsScreenState();
}

class _AnnouncementsScreenState extends State<AnnouncementsScreen> {
  List? rows;
  Map? pub;
  Object? error;
  final open = <int>{};

  @override
  void initState() {
    super.initState();
    load();
    Api.get('/announcements/can-publish').then((r) { if (mounted) setState(() => pub = r as Map); }).catchError((_) {});
  }

  Future<void> load() async {
    try {
      final r = await Api.get('/announcements');
      if (mounted) setState(() { rows = r as List; error = null; });
    } catch (e) {
      if (mounted) setState(() => error = e);
    }
  }

  bool unread(Map a) => a['is_sender'] != true && (a['require_ack'] == true ? a['ack_at'] == null : a['read_at'] == null);

  Future<void> tapCard(Map a) async {
    setState(() => open.contains(a['id']) ? open.remove(a['id']) : open.add(a['id'] as int));
    if (a['is_sender'] != true && a['read_at'] == null) {
      try {
        await Api.post('/announcements/${a['id']}/read');
        if (mounted) setState(() => a['read_at'] = DateTime.now().toIso8601String());
      } catch (_) {}
    }
  }

  Future<void> confirm(Map a) async {
    try {
      await Api.post('/announcements/${a['id']}/ack');
      if (mounted) toast(context, tr('سُجّل تأكيد قراءتك'));
      load();
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final canTeam = ((pub?['team'] ?? 0) as num) > 0;
    return Scaffold(
      appBar: AppBar(title: Text(tr('الإعلانات'))),
      floatingActionButton: canTeam
          ? FloatingActionButton.extended(
              icon: const Icon(Icons.campaign_outlined),
              label: Text(tr('تنبيه لفريقي')),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => TeamAlertScreen(members: List.from(pub?['team_members'] ?? [])))).then((_) => load()),
            )
          : null,
      body: error != null
          ? EmptyView(errText(error!))
          : rows == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: load,
                  child: rows!.isEmpty
                      ? ListView(children: [EmptyView(tr('لا إعلانات'))])
                      : ListView(padding: const EdgeInsets.fromLTRB(12, 12, 12, 90), children: [
                          for (final a in rows!.cast<Map>()) _card(context, a),
                        ]),
                ),
    );
  }

  Widget _card(BuildContext context, Map a) {
    final isUnread = unread(a);
    final expanded = open.contains(a['id']);
    final team = a['audience']?['scope'] == 'team';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: HcCard(
        onTap: () => tapCard(a),
        color: isUnread ? HcColors.deepBlue.withValues(alpha: context.isDark ? .16 : .06) : null,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            if (isUnread) Container(width: 9, height: 9, margin: const EdgeInsetsDirectional.only(end: 8), decoration: const BoxDecoration(color: HcColors.deepBlue, shape: BoxShape.circle)),
            Expanded(child: Text('${a['title']}', style: context.t.bodyLarge?.copyWith(fontWeight: isUnread ? FontWeight.w900 : FontWeight.w700))),
            if (team) StatusBadge(tr('تنبيه للفريق'), color: HcColors.cyan),
          ]),
          const SizedBox(height: 2),
          Text('${a['sender'] ?? ''} · ${fmtDateTime(a['published_at'])}', style: context.t.labelSmall),
          const SizedBox(height: 8),
          Text('${a['body'] ?? ''}', maxLines: expanded ? null : 3, overflow: expanded ? null : TextOverflow.ellipsis),
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            if (a['is_sender'] != true) StatusBadge(tr(a['read_at'] != null ? 'مقروء' : 'غير مقروء'), color: a['read_at'] != null ? HcColors.ok : HcColors.deepBlue),
            if (a['require_ack'] == true && a['ack_at'] != null) StatusBadge('${tr('أكّدت القراءة')} ${fmtDate(a['ack_at'])}', color: HcColors.ok, icon: Icons.verified_outlined),
            if (a['is_sender'] == true) StatusBadge('${a['reads']}/${a['recipients']} ${tr('قرأوا')}${a['require_ack'] == true ? ' · ${a['acks']} ${tr('أكّدوا')}' : ''}', color: HcColors.purple),
          ]),
          if (a['require_ack'] == true && a['ack_at'] == null && a['is_sender'] != true)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Align(alignment: AlignmentDirectional.centerEnd, child: FilledButton.icon(icon: const Icon(Icons.check_circle_outline), label: Text(tr('أؤكد قراءتي واطلاعي')), onPressed: () => confirm(a))),
            ),
        ]),
      ),
    );
  }
}

/// A direct manager alerts his team (everyone under him, or the people he picks): a pop-up on their phones and the site.
class TeamAlertScreen extends StatefulWidget {
  final List members;
  const TeamAlertScreen({super.key, required this.members});
  @override
  State<TeamAlertScreen> createState() => _TeamAlertScreenState();
}

class _TeamAlertScreenState extends State<TeamAlertScreen> {
  final title = TextEditingController();
  final body = TextEditingController();
  bool ack = false;
  bool all = true;
  final picked = <int>{};
  bool busy = false;

  Future<void> send() async {
    if (title.text.trim().isEmpty || body.text.trim().isEmpty) return toast(context, tr('العنوان والنص إلزاميان'), error: true);
    if (!all && picked.isEmpty) return toast(context, tr('اختر موظفًا واحدًا على الأقل'), error: true);
    setState(() => busy = true);
    try {
      final r = await Api.post('/announcements', {
        'title': title.text.trim(), 'body': body.text.trim(), 'require_ack': ack, 'popup': true,
        'audience': {'scope': 'team', 'employee_ids': all ? [] : picked.toList()},
      });
      if (mounted) {
        toast(context, '${tr('أُرسل التنبيه إلى')} ${r['recipients']}');
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('تنبيه لفريقي'))),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          TextField(controller: title, decoration: InputDecoration(labelText: tr('العنوان'))),
          const SizedBox(height: 12),
          TextField(controller: body, maxLines: 5, decoration: InputDecoration(labelText: tr('النص'))),
          const SizedBox(height: 8),
          SwitchListTile(contentPadding: EdgeInsets.zero, value: ack, onChanged: (v) => setState(() => ack = v), title: Text(tr('تأكيد القراءة إلزامي')), subtitle: Text(tr('يضغط الموظف «أؤكد قراءتي» ويُسجَّل الوقت إثباتًا'))),
          SwitchListTile(contentPadding: EdgeInsets.zero, value: all, onChanged: (v) => setState(() => all = v), title: Text('${tr('كل الفريق')} (${widget.members.length})')),
          if (!all)
            for (final m in widget.members.cast<Map>())
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: picked.contains(m['id']),
                onChanged: (v) => setState(() => v == true ? picked.add(m['id'] as int) : picked.remove(m['id'])),
                title: Text('${m['name']}'),
                subtitle: m['direct'] == true ? null : Text(tr('غير مباشر')),
              ),
          const SizedBox(height: 16),
          SizedBox(height: 48, child: FilledButton.icon(icon: const Icon(Icons.send), label: Text(tr('إرسال التنبيه')), onPressed: busy ? null : send)),
        ]),
      );
}
