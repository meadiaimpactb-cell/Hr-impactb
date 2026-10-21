import 'package:flutter/material.dart';

import '../api.dart';
import '../l10n.dart';
import '../theme.dart';
import '../widgets.dart';

class NotificationsScreen extends StatefulWidget {
  final VoidCallback? onChanged;
  const NotificationsScreen({super.key, this.onChanged});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List? items;
  bool _failed = false;
  static const icons = {'approval': Icons.task_alt_outlined, 'ok': Icons.check_circle_outline, 'bad': Icons.block_outlined, 'warn': Icons.warning_amber_outlined, 'info': Icons.info_outline};

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await Api.get('/notifications');
      if (mounted) {
        setState(() {
          items = r as List;
          _failed = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _failed = true);
        toast(context, errText(e), error: true);
      }
    }
  }

  int get unread => items?.where((n) => n['read_at'] == null).length ?? 0;

  Future<void> _read(Map n) async {
    if (n['read_at'] != null) return;
    setState(() => n['read_at'] = DateTime.now().toIso8601String());
    try {
      await Api.post('/notifications/read', {'id': n['id']});
      widget.onChanged?.call();
    } catch (_) {}
  }

  Future<void> _readAll() async {
    setState(() {
      for (final n in items!) {
        n['read_at'] ??= DateTime.now().toIso8601String();
      }
    });
    try {
      await Api.post('/notifications/read');
      widget.onChanged?.call();
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
        Row(children: [
          Expanded(child: Text(tr('الإشعارات'), style: context.t.headlineMedium)),
          if (unread > 0) TextButton.icon(onPressed: _readAll, icon: const Icon(Icons.done_all, size: 18), label: Text(tr('تعليم الكل كمقروء'))),
        ]),
        const SizedBox(height: 8),
        if (items == null && !_failed) const SkeletonList(),
        if (items == null && _failed) EmptyView(tr('تعذر الاتصال بالخادم'), hint: tr('اسحب للأسفل لإعادة المحاولة')),
        if (items != null && items!.isEmpty) EmptyView(tr('لا توجد إشعارات'), hint: tr('ستظهر هنا اعتمادات طلباتك وتنبيهات النظام')),
        if (items != null) ..._grouped(),
      ]),
    );
  }

  List<Widget> _grouped() {
    final out = <Widget>[];
    String? last;
    var group = <Widget>[];
    void flush() {
      if (group.isEmpty) return;
      out.add(HcCard(padding: EdgeInsets.zero, child: Column(children: [for (var i = 0; i < group.length; i++) ...[if (i > 0) const Divider(indent: 68), group[i]]])));
      group = <Widget>[];
    }

    for (final n in items!) {
      final key = dayBucket(n['created_at']);
      if (key != last) {
        flush();
        out.add(Padding(padding: EdgeInsets.fromLTRB(4, out.isEmpty ? 4 : 16, 4, 8), child: Text(key, style: context.t.labelSmall)));
        last = key;
      }
      group.add(_Row(n: n, onTap: () => _read(n)));
    }
    flush();
    return out;
  }
}

class _Row extends StatelessWidget {
  final Map n;
  final VoidCallback onTap;
  const _Row({required this.n, required this.onTap});
  @override
  Widget build(BuildContext context) {
    final unread = n['read_at'] == null;
    final color = statusColor(n['kind'] ?? 'info');
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(width: 40, height: 40, alignment: Alignment.center, decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)), child: Icon(_NotificationsScreenState.icons[n['kind']] ?? Icons.notifications_outlined, size: 20, color: color)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(n['title'] ?? '', style: context.t.bodyLarge?.copyWith(fontWeight: unread ? FontWeight.w700 : FontWeight.w500))),
                const SizedBox(width: 8),
                Text(relTime(n['created_at']), style: context.t.labelSmall),
              ]),
              if ((n['body'] ?? '').toString().isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(n['body'], style: context.t.labelMedium?.copyWith(color: unread ? context.text : context.muted), maxLines: 3, overflow: TextOverflow.ellipsis)),
            ]),
          ),
          if (unread) Padding(padding: const EdgeInsets.only(top: 6, left: 8, right: 8), child: Container(width: 8, height: 8, decoration: const BoxDecoration(color: HcColors.cyan, shape: BoxShape.circle))),
        ]),
      ),
    );
  }
}
