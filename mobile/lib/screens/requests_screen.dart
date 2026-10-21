import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../l10n.dart';
import '../state.dart';
import '../theme.dart';
import '../widgets.dart';
import 'new_request_screen.dart';

IconData requestIcon(String? type, [String? handler]) => switch (handler ?? type) {
      'leave' => Icons.beach_access_outlined,
      'correction' => Icons.edit_calendar_outlined,
      'advance' => Icons.payments_outlined,
      'trip' => Icons.flight_takeoff_outlined,
      'letter' => Icons.description_outlined,
      'resignation' => Icons.logout_outlined,
      'allowance' || 'salary_change' => Icons.account_balance_wallet_outlined,
      'benefit' || 'balance_adj' => Icons.card_giftcard_outlined,
      'handover' => Icons.swap_horiz_outlined,
      _ => Icons.assignment_outlined,
    };

class RequestsScreen extends StatefulWidget {
  const RequestsScreen({super.key});
  @override
  State<RequestsScreen> createState() => _RequestsScreenState();
}

class _RequestsScreenState extends State<RequestsScreen> {
  List? rows;
  bool _failed = false;
  String tab = 'all';

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await Api.get('/requests/mine');
      if (mounted) {
        setState(() {
          rows = r as List;
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

  Future<void> _new() async {
    final s = context.read<AppState>();
    final defs = ((s.meta?['request_type_defs'] as List?) ?? []).where((d) => d['self_service'] == true).toList();
    final titles = NewRequestScreen.titles(s);
    final type = await pickSheet<String>(context, tr('طلب جديد'), [for (final d in defs) (d['code'] as String, titles[d['code']] ?? d['name'] as String, requestIcon(d['code'], d['handler']))]);
    if (type == null || !mounted) return;
    await Navigator.push(context, MaterialPageRoute(builder: (_) => NewRequestScreen(type: type)));
    load();
  }

  Future<void> _cancel(Map r) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(tr('إلغاء الطلب؟'), style: ctx.t.titleLarge),
        content: Text('${r['type_label']}\n${r['summary']}', style: ctx.t.labelMedium),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('تراجع'))), FilledButton(style: FilledButton.styleFrom(backgroundColor: HcColors.bad, minimumSize: const Size(110, 44)), onPressed: () => Navigator.pop(ctx, true), child: Text(tr('إلغاء الطلب')))],
      ),
    );
    if (ok != true) return;
    try {
      await Api.post('/requests/${r['id']}/cancel');
      if (mounted) toast(context, tr('أُلغي الطلب'));
      load();
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    }
  }

  List get filtered => switch (tab) {
        'pending' => rows!.where((r) => r['status'] == 'pending').toList(),
        'approved' => rows!.where((r) => r['status'] == 'approved').toList(),
        'closed' => rows!.where((r) => r['status'] == 'returned' || r['status'] == 'cancelled').toList(),
        _ => rows!,
      };

  @override
  Widget build(BuildContext context) {
    final pending = rows?.where((r) => r['status'] == 'pending').length ?? 0;
    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: FloatingActionButton(onPressed: _new, backgroundColor: HcColors.deepBlue, foregroundColor: Colors.white, shape: const CircleBorder(), child: const Icon(Icons.add, size: 28)),
      body: RefreshIndicator(
        onRefresh: load,
        child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 96), children: [
          Row(children: [Expanded(child: Text(tr('طلباتي'), style: context.t.headlineMedium)), if (pending > 0) StatusBadge('$pending ${tr('معلّق')}', color: HcColors.warn)]),
          const SizedBox(height: 12),
          Segmented<String>(items: [('all', tr('الكل')), ('pending', tr('معلّق')), ('approved', tr('معتمد')), ('closed', tr('مغلق'))], value: tab, onChanged: (v) => setState(() => tab = v)),
          const SizedBox(height: 16),
          if (rows == null && !_failed) const SkeletonList(),
          if (rows == null && _failed) EmptyView(tr('تعذر الاتصال بالخادم'), hint: tr('اسحب للأسفل لإعادة المحاولة')),
          if (rows != null) ..._grouped(),
        ]),
      ),
    );
  }

  List<Widget> _grouped() {
    final list = filtered;
    if (list.isEmpty) return [EmptyView(tr('لا توجد طلبات'), hint: tr('اضغط + لإنشاء طلب جديد'))];
    final out = <Widget>[];
    String? last;
    for (final r in list) {
      final dt = DateTime.tryParse(r['created_at']?.toString() ?? '');
      final key = dt == null ? '' : '${monthName(dt.month)} ${dt.year}';
      if (key != last) {
        out.add(Padding(padding: EdgeInsets.fromLTRB(4, out.isEmpty ? 0 : 12, 4, 8), child: Text(key, style: context.t.labelSmall)));
        last = key;
      }
      out.add(_RequestCard(r: r, onCancel: r['status'] == 'pending' ? () => _cancel(r) : null));
    }
    return out;
  }
}

class _RequestCard extends StatelessWidget {
  final Map r;
  final VoidCallback? onCancel;
  const _RequestCard({required this.r, this.onCancel});
  @override
  Widget build(BuildContext context) {
    final color = statusColor(r['status']);
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: HcCard(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Container(width: 40, height: 40, alignment: Alignment.center, decoration: BoxDecoration(color: HcColors.deepBlue.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)), child: Icon(requestIcon(r['type']), color: HcColors.deepBlue, size: 20)),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(r['type_label'] ?? '', style: context.t.bodyLarge?.copyWith(fontWeight: FontWeight.w700)),
                Text(r['summary'] ?? '', style: context.t.labelMedium, maxLines: 2, overflow: TextOverflow.ellipsis),
              ]),
            ),
            const SizedBox(width: 8),
            StatusChip(r['status']),
          ]),
          if (r['status'] == 'pending' || r['created_at'] != null) ...[
            const SizedBox(height: 10),
            Row(children: [
              Icon(Icons.schedule_outlined, size: 14, color: context.muted),
              const SizedBox(width: 4),
              Expanded(child: Text(r['status'] == 'pending' && r['stage_label'] != null ? '${tr('المرحلة')}: ${r['stage_label']} · ${relTime(r['created_at'])}' : fmtDateTime(r['created_at']), style: context.t.labelSmall, maxLines: 1, overflow: TextOverflow.ellipsis)),
              if (onCancel != null) InkWell(onTap: onCancel, borderRadius: BorderRadius.circular(8), child: Padding(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2), child: Text(tr('إلغاء'), style: context.t.labelMedium?.copyWith(color: HcColors.bad, fontWeight: FontWeight.w700)))),
              if (onCancel == null) Container(width: 6, height: 6, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
            ]),
          ],
        ]),
      ),
    );
  }
}
