import 'package:flutter/material.dart';

import '../api.dart';
import '../l10n.dart';
import '../theme.dart';
import '../widgets.dart';

class AttendanceScreen extends StatefulWidget {
  const AttendanceScreen({super.key});
  @override
  State<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends State<AttendanceScreen> {
  Map<String, dynamic>? d;
  bool _failed = false;
  DateTime month = DateTime(DateTime.now().year, DateTime.now().month);

  String get period => '${month.year}-${two(month.month)}';
  bool get isCurrent => month.year == DateTime.now().year && month.month == DateTime.now().month;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await Api.get('/attendance/me', {'period': period});
      if (mounted) {
        setState(() {
          d = Map<String, dynamic>.from(r);
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

  void shift(int m) {
    if (m > 0 && isCurrent) return;
    setState(() {
      month = DateTime(month.year, month.month + m);
      d = null;
    });
    load();
  }

  /// present / late / absent / null per day-of-month
  Map<int, String> _byDay(List recs) {
    final out = <int, String>{};
    for (final r in recs) {
      final day = int.tryParse(r['work_date'].toString().substring(8, 10)) ?? 0;
      final late = (r['late_minutes'] ?? 0) as int;
      out[day] = r['status'] == 'absent' ? 'absent' : late > 10 ? 'late' : 'present';
    }
    return out;
  }

  @override
  Widget build(BuildContext context) {
    final s = d?['summary'];
    final recs = ((d?['records'] as List?) ?? []).toList()..sort((a, b) => b['work_date'].toString().compareTo(a['work_date'].toString()));
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
        // month switcher
        Row(children: [
          IconButton.outlined(onPressed: () => shift(-1), icon: const Icon(Icons.chevron_right), style: IconButton.styleFrom(side: BorderSide(color: context.line))),
          Expanded(child: Column(children: [Text('${monthName(month.month)} ${month.year}', style: context.t.titleLarge), Text(tr('سجل الحضور'), style: context.t.labelMedium)])),
          IconButton.outlined(onPressed: isCurrent ? null : () => shift(1), icon: const Icon(Icons.chevron_left), style: IconButton.styleFrom(side: BorderSide(color: context.line))),
        ]),
        const SizedBox(height: 14),
        if (d == null && !_failed) const SkeletonList(count: 4),
        if (d == null && _failed) EmptyView(tr('تعذر الاتصال بالخادم'), hint: tr('اسحب للأسفل لإعادة المحاولة')),
        if (s != null) ...[
          Row(children: [
            Expanded(child: MetricTile(label: tr('حاضر'), value: '${s['present']}', hint: '${tr('من')} ${s['required']} ${tr('يوم')}', icon: Icons.check_circle_outline, color: HcColors.ok)),
            const SizedBox(width: 10),
            Expanded(child: MetricTile(label: tr('متأخر'), value: '${s['late']}', hint: '${s['late_minutes']} ${tr('دقيقة')}', icon: Icons.schedule_outlined, color: HcColors.warn)),
            const SizedBox(width: 10),
            Expanded(child: MetricTile(label: tr('غياب'), value: '${s['absent']}', hint: tr('يوم'), icon: Icons.cancel_outlined, color: HcColors.bad)),
          ]),
          const SizedBox(height: 12),
          _MiniCalendar(month: month, marks: _byDay(recs)),
          const SizedBox(height: 20),
          SectionTitle(tr('السجلات')),
          if (recs.isEmpty) EmptyView(tr('لا توجد سجلات لهذا الشهر')),
          for (final r in recs) _RecordCard(r: r),
        ],
      ]),
    );
  }
}

class _MiniCalendar extends StatelessWidget {
  final DateTime month;
  final Map<int, String> marks;
  const _MiniCalendar({required this.month, required this.marks});
  @override
  Widget build(BuildContext context) {
    final days = DateTime(month.year, month.month + 1, 0).day;
    final firstDow = DateTime(month.year, month.month, 1).weekday % 7; // 0 = Sunday
    final today = DateTime.now();
    final heads = L.lang == 'en' ? ['S', 'M', 'T', 'W', 'T', 'F', 'S'] : ['ح', 'ن', 'ث', 'ر', 'خ', 'ج', 'س'];
    final cells = <Widget>[
      for (final h in heads) Center(child: Text(h, style: context.t.labelSmall)),
      for (var i = 0; i < firstDow; i++) const SizedBox(),
      for (var dd = 1; dd <= days; dd++) _cell(context, dd, marks[dd], today.year == month.year && today.month == month.month && today.day == dd),
    ];
    return HcCard(
      padding: const EdgeInsets.fromLTRB(12, 14, 12, 10),
      child: GridView.count(crossAxisCount: 7, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(), mainAxisSpacing: 4, childAspectRatio: 1.15, children: cells),
    );
  }

  Widget _cell(BuildContext context, int day, String? mark, bool isToday) {
    final c = mark == null ? null : statusColor(mark);
    // scales down on the narrowest phones (iPhone SE 320pt) instead of overflowing the grid cell
    return FittedBox(fit: BoxFit.scaleDown, child: Column(mainAxisSize: MainAxisSize.min, children: [
      Container(
        width: 28,
        height: 28,
        alignment: Alignment.center,
        decoration: BoxDecoration(shape: BoxShape.circle, color: c?.withValues(alpha: .14), border: isToday ? Border.all(color: HcColors.deepBlue, width: 1.5) : null),
        child: Text('$day', style: context.t.labelMedium?.copyWith(color: c ?? context.muted, fontWeight: mark == null ? FontWeight.w500 : FontWeight.w700, fontFeatures: const [FontFeature.tabularFigures()])),
      ),
      const SizedBox(height: 2),
      Container(width: 5, height: 5, decoration: BoxDecoration(shape: BoxShape.circle, color: c ?? Colors.transparent)),
    ]));
  }
}

class _RecordCard extends StatelessWidget {
  final Map r;
  const _RecordCard({required this.r});
  @override
  Widget build(BuildContext context) {
    final late = (r['late_minutes'] ?? 0) as int;
    final absent = r['status'] == 'absent';
    final st = absent ? 'absent' : late > 10 ? 'late' : 'present';
    final color = statusColor(st);
    final date = DateTime.tryParse(r['work_date'].toString());
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: HcCard(
        padding: const EdgeInsets.all(14),
        child: Row(children: [
          Container(
            width: 48,
            height: 52,
            decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              Text('${date?.day ?? ''}', style: context.t.titleLarge?.copyWith(color: color, height: 1.1)),
              Text(date == null ? '' : dayName(date.weekday % 7).substring(0, L.lang == 'en' ? 3 : 2), style: context.t.labelSmall?.copyWith(color: color)),
            ]),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(absent ? tr('غياب') : '${hm(r['check_in'])} → ${hm(r['check_out'])}', style: context.t.bodyLarge?.copyWith(fontWeight: FontWeight.w700, fontFeatures: const [FontFeature.tabularFigures()]))),
                StatusBadge(absent ? tr('غياب') : late > 10 ? '${tr('متأخر')} $late ${tr('د')}' : tr('حاضر'), color: color),
              ]),
              const SizedBox(height: 4),
              Row(children: [
                Icon(r['location'] != null ? Icons.location_on_outlined : Icons.location_off_outlined, size: 14, color: context.muted),
                const SizedBox(width: 4),
                Expanded(child: Text(r['location']?['name'] ?? (absent ? tr('لم يُسجّل حضور') : tr('خارج النطاق')), style: context.t.labelMedium, maxLines: 1, overflow: TextOverflow.ellipsis)),
                if (r['pending'] == true) ...[const Icon(Icons.hourglass_top, size: 14, color: HcColors.warn), const SizedBox(width: 4), Text(tr('بانتظار المراجعة'), style: context.t.labelSmall?.copyWith(color: HcColors.warn))],
              ]),
              // §33: the daily achievement written at check-out — tap to read it in full
              if ((r['note'] ?? '').toString().isNotEmpty) ...[
                const SizedBox(height: 6),
                InkWell(
                  onTap: () => showDialog(context: context, builder: (d) => AlertDialog(title: Text(tr('المنجز اليومي')), content: Text(r['note'].toString()), actions: [TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('إغلاق')))])),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Icon(Icons.task_alt, size: 14, color: context.muted),
                    const SizedBox(width: 4),
                    Expanded(child: Text(r['note'].toString(), style: context.t.labelMedium, maxLines: 2, overflow: TextOverflow.ellipsis)),
                  ]),
                ),
              ],
            ]),
          ),
        ]),
      ),
    );
  }
}
