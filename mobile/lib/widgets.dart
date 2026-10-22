import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'api.dart';
import 'l10n.dart';
import 'state.dart';
import 'theme.dart';

// ---------------------------------------------------------------- feedback
void toast(BuildContext context, String msg, {bool error = false}) {
  ScaffoldMessenger.of(context).hideCurrentSnackBar();
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Row(children: [Icon(error ? Icons.error_outline : Icons.check_circle_outline, color: Colors.white, size: 20), const SizedBox(width: 10), Expanded(child: Text(msg, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)))]),
    backgroundColor: error ? HcColors.bad : HcColors.ok,
    duration: Duration(seconds: error ? 4 : 2),
  ));
}

String errText(Object e) => e is ApiException ? e.message : tr('تعذر الاتصال بالخادم');

// ---------------------------------------------------------------- formatting
const arMonths = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
const enMonths = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const arDays = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
const enDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
String monthName(int m) => (L.lang == 'en' ? enMonths : arMonths)[m - 1];
String dayName(int dow) => (L.lang == 'en' ? enDays : arDays)[dow % 7];
DateTime? _parse(dynamic iso) => iso == null ? null : DateTime.tryParse(iso.toString())?.toLocal();
String two(int n) => n.toString().padLeft(2, '0');

/// 28 سبتمبر 2026
String fmtDate(dynamic iso) {
  final d = _parse(iso);
  return d == null ? '—' : '${d.day} ${monthName(d.month)} ${d.year}';
}

/// 08:34 ص
String hm(dynamic iso) {
  final d = _parse(iso);
  if (d == null) return '—';
  final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
  final ap = L.lang == 'en' ? (d.hour < 12 ? 'AM' : 'PM') : (d.hour < 12 ? 'ص' : 'م');
  return '${two(h)}:${two(d.minute)} $ap';
}

/// 28 سبتمبر 2026 · 08:34 ص
String fmtDateTime(dynamic iso) => _parse(iso) == null ? '—' : '${fmtDate(iso)} · ${hm(iso)}';

/// منذ ساعتين / أمس / 12 سبتمبر
String relTime(dynamic iso) {
  final d = _parse(iso);
  if (d == null) return '';
  final diff = DateTime.now().difference(d);
  final en = L.lang == 'en';
  if (diff.inMinutes < 1) return en ? 'now' : 'الآن';
  if (diff.inMinutes < 60) return en ? '${diff.inMinutes} min ago' : 'منذ ${diff.inMinutes} دقيقة';
  if (diff.inHours < 24) return en ? '${diff.inHours} h ago' : (diff.inHours == 1 ? 'منذ ساعة' : diff.inHours == 2 ? 'منذ ساعتين' : 'منذ ${diff.inHours} ساعات');
  if (diff.inDays == 1) return en ? 'yesterday' : 'أمس';
  if (diff.inDays < 7) return en ? '${diff.inDays} d ago' : 'منذ ${diff.inDays} أيام';
  return '${d.day} ${monthName(d.month)}';
}

/// Day bucket for grouped lists: اليوم / أمس / هذا الأسبوع / أقدم
String dayBucket(dynamic iso) {
  final d = _parse(iso);
  if (d == null) return '';
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final day = DateTime(d.year, d.month, d.day);
  final diff = today.difference(day).inDays;
  if (diff == 0) return tr('اليوم');
  if (diff == 1) return tr('أمس');
  if (diff < 7) return tr('هذا الأسبوع');
  return '${monthName(d.month)} ${d.year}';
}

String fmtMoney(dynamic n) => money(n);

String money(dynamic n) {
  final v = (num.tryParse('$n') ?? 0).toStringAsFixed(2);
  return v.replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+\.)'), (m) => '${m[1]},');
}

// ---------------------------------------------------------------- primitives
class HcCard extends StatelessWidget {
  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? color;
  const HcCard({super.key, required this.child, this.padding = const EdgeInsets.all(16), this.onTap, this.color});
  @override
  Widget build(BuildContext context) {
    final box = Container(
      decoration: BoxDecoration(color: color ?? context.card, borderRadius: BorderRadius.circular(16), border: Border.all(color: context.line), boxShadow: context.isDark ? null : const [BoxShadow(color: Color(0x0F262E6B), blurRadius: 16, offset: Offset(0, 4))]),
      padding: padding,
      child: child,
    );
    return onTap == null ? box : Material(color: Colors.transparent, child: InkWell(borderRadius: BorderRadius.circular(16), onTap: onTap, child: box));
  }
}

Color statusColor(String s) => switch (s) {
      'approved' || 'present' || 'ok' || 'verified' || 'submitted' || 'settled' || 'paid' || 'active' => HcColors.ok,
      'pending' || 'late' || 'warn' || 'pending_ack' || 'awaiting_statement' || 'awaiting_approval' || 'missing' || 'draft' => HcColors.warn,
      'returned' || 'absent' || 'bad' || 'rejected' || 'cancelled' || 'expired' => HcColors.bad,
      _ => HcColors.periwinkle,
    };

class StatusBadge extends StatelessWidget {
  final String label;
  final Color color;
  final IconData? icon;
  const StatusBadge(this.label, {super.key, this.color = HcColors.periwinkle, this.icon});
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(999)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 13, color: color), const SizedBox(width: 4)],
          Flexible(child: Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700, height: 1.3), maxLines: 1, overflow: TextOverflow.ellipsis)),
        ]),
      );
}

/// Status chip from a request/attendance status code.
/// The company identity colour (the group's navy when none is set) and a readable text colour on it.
Color brandOf(BuildContext context) {
  try {
    return context.watch<AppState>().brandColor ?? HcColors.navy;
  } catch (_) {
    return HcColors.navy;
  }
}

Color inkOn(Color c) => c.computeLuminance() > 0.45 ? HcColors.navy : Colors.white;

/// A count on the company identity colour — used for every counter in the app (owner's note).
class CountPill extends StatelessWidget {
  final String text;
  const CountPill(this.text, {super.key});
  @override
  Widget build(BuildContext context) {
    final c = brandOf(context);
    return Container(
      constraints: const BoxConstraints(minWidth: 22),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(999)),
      child: Text(text, textAlign: TextAlign.center, style: TextStyle(color: inkOn(c), fontWeight: FontWeight.w800, fontSize: 12, height: 1.2)),
    );
  }
}

class StatusChip extends StatelessWidget {
  final String status;
  const StatusChip(this.status, {super.key});
  @override
  Widget build(BuildContext context) => StatusBadge(tr(statusLabels[status] ?? status), color: statusColor(status));
}

class HcAvatar extends StatelessWidget {
  final String name;
  final double size;
  final String? url; // profile photo; the first letter is shown when there is none
  const HcAvatar(this.name, {super.key, this.size = 44, this.url});
  @override
  Widget build(BuildContext context) {
    final letter = Text(name.trim().isEmpty ? '?' : name.trim().characters.first, style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: size * .42));
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      clipBehavior: Clip.antiAlias,
      decoration: const BoxDecoration(color: HcColors.purple, shape: BoxShape.circle),
      child: url == null || url!.isEmpty ? letter : Image.network(url!, width: size, height: size, fit: BoxFit.cover, errorBuilder: (_, __, ___) => letter),
    );
  }
}

class PrimaryButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final bool loading;
  final IconData? icon;
  final bool secondary;
  final bool destructive;
  const PrimaryButton({super.key, required this.label, this.onPressed, this.loading = false, this.icon, this.secondary = false, this.destructive = false});
  @override
  Widget build(BuildContext context) {
    final child = loading
        ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white))
        : Row(mainAxisAlignment: MainAxisAlignment.center, mainAxisSize: MainAxisSize.min, children: [if (icon != null) ...[Icon(icon, size: 20), const SizedBox(width: 8)], Text(label)]);
    if (secondary) return OutlinedButton(onPressed: loading ? null : onPressed, child: child);
    return FilledButton(onPressed: loading ? null : onPressed, style: destructive ? FilledButton.styleFrom(backgroundColor: HcColors.bad) : null, child: child);
  }
}

/// Big number + small muted label (metric tile).
class MetricTile extends StatelessWidget {
  final String label;
  final String value;
  final String? hint;
  final Color color;
  final VoidCallback? onTap;
  final IconData? icon;
  const MetricTile({super.key, required this.label, required this.value, this.hint, this.color = HcColors.deepBlue, this.onTap, this.icon});
  @override
  Widget build(BuildContext context) => HcCard(
        onTap: onTap,
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Row(children: [
            if (icon != null) ...[Container(width: 28, height: 28, alignment: Alignment.center, decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(8)), child: Icon(icon, size: 16, color: color)), const SizedBox(width: 8)],
            Expanded(child: Text(label, style: context.t.labelMedium, maxLines: 1, overflow: TextOverflow.ellipsis)),
          ]),
          const SizedBox(height: 8),
          Text(value, style: context.t.headlineMedium?.copyWith(fontFeatures: const [FontFeature.tabularFigures()])),
          if (hint != null) Text(hint!, style: context.t.labelSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
        ]),
      );
}

/// Backwards-compatible alias used by module screens.
class KpiTile extends StatelessWidget {
  final String label;
  final String value;
  final String? hint;
  final Color color;
  final VoidCallback? onTap;
  const KpiTile({super.key, required this.label, required this.value, this.hint, this.color = HcColors.deepBlue, this.onTap});
  @override
  Widget build(BuildContext context) => Padding(padding: const EdgeInsets.only(bottom: 10), child: MetricTile(label: label, value: value, hint: hint, color: color, onTap: onTap));
}

/// Grouped list card: an overline title and rows separated by dividers.
class ListGroup extends StatelessWidget {
  final String? title;
  final List<Widget> children;
  const ListGroup({super.key, this.title, required this.children});
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (title != null) Padding(padding: const EdgeInsets.fromLTRB(4, 0, 4, 8), child: Text(title!.toUpperCase(), style: context.t.labelSmall)),
        HcCard(padding: EdgeInsets.zero, child: Column(children: [for (var i = 0; i < children.length; i++) ...[children[i], if (i < children.length - 1) const Divider(indent: 68)]])),
        const SizedBox(height: 20),
      ]);
}

class ListRow extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;
  final Color? titleColor;
  const ListRow({super.key, required this.icon, required this.title, this.subtitle, this.color = HcColors.deepBlue, this.trailing, this.onTap, this.titleColor});
  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(children: [
            Container(width: 40, height: 40, alignment: Alignment.center, decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)), child: Icon(icon, size: 20, color: color)),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, style: context.t.bodyLarge?.copyWith(fontWeight: FontWeight.w600, color: titleColor)),
              if (subtitle != null) Text(subtitle!, style: context.t.labelMedium, maxLines: 2, overflow: TextOverflow.ellipsis),
            ])),
            trailing ?? Icon(Icons.chevron_left, color: context.muted),
          ]),
        ),
      );
}

class SectionTitle extends StatelessWidget {
  final String text;
  final Widget? action;
  const SectionTitle(this.text, {super.key, this.action});
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(4, 8, 4, 10),
        child: Row(children: [Expanded(child: Text(text, style: context.t.titleLarge)), if (action != null) action!]),
      );
}

/// The three tilted rectangles from the logo, drawn faintly (headers, login, empty states).
class LogoPattern extends StatelessWidget {
  final double opacity;
  final double size;
  final Color? color;
  const LogoPattern({super.key, this.opacity = .10, this.size = 160, this.color});
  @override
  Widget build(BuildContext context) => IgnorePointer(child: CustomPaint(size: Size(size, size * .7), painter: _LogoPainter(opacity, color ?? Colors.white)));
}

class _LogoPainter extends CustomPainter {
  final double opacity;
  final Color color;
  _LogoPainter(this.opacity, this.color);
  @override
  void paint(Canvas canvas, Size s) {
    final w = s.width * .28, h = s.height * .95;
    for (var i = 0; i < 3; i++) {
      final p = Paint()..color = [HcColors.purple, HcColors.periwinkle, HcColors.cyan][i].withValues(alpha: opacity);
      canvas.save();
      canvas.translate(s.width * (.18 + i * .27), s.height / 2);
      canvas.rotate(-40 * math.pi / 180);
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromCenter(center: Offset.zero, width: w, height: h), Radius.circular(w * .3)), p);
      canvas.restore();
    }
  }

  @override
  bool shouldRepaint(_LogoPainter o) => o.opacity != opacity;
}

class EmptyView extends StatelessWidget {
  final String text;
  final String? hint;
  const EmptyView(this.text, {super.key, this.hint});
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 40, horizontal: 24),
        child: Column(children: [
          const LogoPattern(opacity: .25, size: 140, color: HcColors.periwinkle),
          const SizedBox(height: 8),
          Text(text, style: context.t.bodyLarge?.copyWith(fontWeight: FontWeight.w600, color: context.muted), textAlign: TextAlign.center),
          if (hint != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(hint!, style: context.t.labelMedium, textAlign: TextAlign.center)),
        ]),
      );
}

/// Loading placeholder: a few grey cards.
class SkeletonList extends StatelessWidget {
  final int count;
  const SkeletonList({super.key, this.count = 4});
  @override
  Widget build(BuildContext context) => Column(children: [
        for (var i = 0; i < count; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Container(height: 84, decoration: BoxDecoration(color: context.line.withValues(alpha: .6), borderRadius: BorderRadius.circular(16))),
          ),
      ]);
}

/// Segmented control (two or more options).
class Segmented<T> extends StatelessWidget {
  final List<(T, String)> items;
  final T value;
  final ValueChanged<T> onChanged;
  const Segmented({super.key, required this.items, required this.value, required this.onChanged});
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(color: context.line.withValues(alpha: .5), borderRadius: BorderRadius.circular(12)),
        child: Row(children: [
          for (final it in items)
            Expanded(
              child: GestureDetector(
                onTap: () => onChanged(it.$1),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 250),
                  curve: Curves.easeOut,
                  height: 36,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: it.$1 == value ? context.card : Colors.transparent, borderRadius: BorderRadius.circular(9), boxShadow: it.$1 == value ? const [BoxShadow(color: Color(0x14262E6B), blurRadius: 6)] : null),
                  child: Text(it.$2, style: context.t.labelMedium?.copyWith(color: it.$1 == value ? context.text : context.muted, fontWeight: FontWeight.w700)),
                ),
              ),
            ),
        ]),
      );
}

/// Bottom-sheet picker of labelled options.
Future<T?> pickSheet<T>(BuildContext context, String title, List<(T, String, IconData)> options) => showModalBottomSheet<T>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: ctx.t.titleLarge),
            const SizedBox(height: 8),
            for (final o in options) ListRow(icon: o.$3, title: o.$2, onTap: () => Navigator.pop(ctx, o.$1)),
          ]),
        ),
      ),
    );

Future<String?> promptText(BuildContext context, String title, {String? hint, int lines = 3, String? confirm}) async {
  final c = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (ctx) => AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Text(title, style: ctx.t.titleLarge),
      content: TextField(controller: c, maxLines: lines, decoration: InputDecoration(hintText: hint)),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('إلغاء'))), FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(100, 44)), onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(confirm ?? tr('إرسال')))],
    ),
  );
}

// ---------------------------------------------------------------- searchable lists
/// Every drop-down list has a search box (owner's note): a field that opens a bottom sheet with a search field and
/// the filtered options. Arabic letter variants (أ/إ/آ/ا, ة/ه, ى/ي) and Arabic-Indic digits match their plain forms.
String _norm(String s) => s
    .toLowerCase()
    .replaceAll(RegExp('[ً-ْـ]'), '')
    .replaceAll(RegExp('[أإآٱ]'), 'ا')
    .replaceAll('ة', 'ه')
    .replaceAll('ى', 'ي')
    .replaceAllMapped(RegExp('[٠-٩]'), (m) => '${m[0]!.codeUnitAt(0) - 0x660}')
    .trim();

Future<T?> pickFromList<T>(BuildContext context, String title, List<(T, String)> items, {T? current}) => showModalBottomSheet<T>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => _PickSheet<T>(title: title, items: items, current: current),
    );

class _PickSheet<T> extends StatefulWidget {
  final String title;
  final List<(T, String)> items;
  final T? current;
  const _PickSheet({required this.title, required this.items, this.current});
  @override
  State<_PickSheet<T>> createState() => _PickSheetState<T>();
}

class _PickSheetState<T> extends State<_PickSheet<T>> {
  String q = '';
  @override
  Widget build(BuildContext context) {
    final nq = _norm(q);
    final shown = widget.items.where((i) => nq.isEmpty || _norm(i.$2).contains(nq)).toList();
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * .75),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 8), child: Text(widget.title, style: context.t.titleLarge)),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: TextField(autofocus: widget.items.length > 8, onChanged: (v) => setState(() => q = v), decoration: InputDecoration(hintText: tr('ابحث في القائمة…'), prefixIcon: const Icon(Icons.search), isDense: true)),
          ),
          Flexible(
            child: shown.isEmpty
                ? Padding(padding: const EdgeInsets.all(24), child: Text(tr('لا نتائج'), style: context.t.labelMedium))
                : ListView.builder(
                    shrinkWrap: true,
                    itemCount: shown.length,
                    itemBuilder: (_, i) {
                      final it = shown[i];
                      final sel = it.$1 == widget.current;
                      return ListTile(
                        title: Text(it.$2, style: TextStyle(fontWeight: sel ? FontWeight.w800 : FontWeight.w500, color: sel ? HcColors.deepBlue : null)),
                        trailing: sel ? const Icon(Icons.check, color: HcColors.deepBlue) : null,
                        onTap: () => Navigator.pop(context, it.$1),
                      );
                    },
                  ),
          ),
          const SizedBox(height: 8),
        ]),
      ),
    );
  }
}

/// Form field that picks one value from a searchable list.
class SearchPicker<T> extends StatelessWidget {
  final String label;
  final T? value;
  final List<(T, String)> items;
  final ValueChanged<T?> onChanged;
  final String? errorText;
  const SearchPicker({super.key, required this.label, required this.value, required this.items, required this.onChanged, this.errorText});
  @override
  Widget build(BuildContext context) {
    final cur = items.where((i) => i.$1 == value).map((i) => i.$2).firstOrNull;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () async {
        final v = await pickFromList<T>(context, label, items, current: value);
        if (v != null && context.mounted) onChanged(v);
      },
      child: InputDecorator(
        decoration: InputDecoration(labelText: label, errorText: errorText, suffixIcon: const Icon(Icons.arrow_drop_down)),
        isEmpty: cur == null,
        child: Text(cur ?? '', maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
    );
  }
}
