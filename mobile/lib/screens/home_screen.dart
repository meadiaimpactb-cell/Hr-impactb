import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:local_auth/local_auth.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../l10n.dart';
import '../nav.dart';
import '../state.dart';
import '../theme.dart';
import '../widgets.dart';
import 'approvals_screen.dart';
import 'modules_screens.dart';
import 'new_request_screen.dart';

class HomeScreen extends StatefulWidget {
  final void Function(int) onNavigate;
  const HomeScreen({super.key, required this.onNavigate});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  Map<String, dynamic>? d;
  bool _punching = false;
  bool _failed = false;
  int mailUnread = 0;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await Api.get('/dashboard');
      Api.get('/mail/unread').then((m) { if (mounted) setState(() => mailUnread = (m['count'] ?? 0) as int); }).catchError((_) {});
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

  Future<Position?> _position() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      var p = await Geolocator.checkPermission();
      if (p == LocationPermission.denied) p = await Geolocator.requestPermission();
      if (p == LocationPermission.denied || p == LocationPermission.deniedForever) return null;
      return await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 10)));
    } catch (_) {
      return null;
    }
  }

  Future<void> punch(bool isOut) async {
    String? note;
    if (isOut) {
      final today = d?['me']?['today'] as Map?;
      final required = today?['note_required'] == true;
      final min = (context.read<AppState>().policy['daily_note_min'] ?? 20) as int;
      note = await _noteDialog(required, min, '${today?['note'] ?? ''}');
      if (note == null) return;
    }
    // Identity confirmation with the phone's fingerprint / face (policy biometric_punch); falls back gracefully when unavailable
    if (!mounted) return;
    final bioOn = (context.read<AppState>().meta?['policy']?['biometric_punch']) != false;
    if (bioOn) {
      try {
        final auth = LocalAuthentication();
        if (await auth.isDeviceSupported() && await auth.canCheckBiometrics) {
          final ok = await auth.authenticate(localizedReason: tr(isOut ? 'أكّد هويتك لتسجيل الانصراف' : 'أكّد هويتك لتسجيل الحضور'), options: const AuthenticationOptions(biometricOnly: false, stickyAuth: true));
          if (!ok) {
            if (mounted) toast(context, tr('لم يتم تأكيد الهوية'), error: true);
            return;
          }
        }
      } catch (e) {
        if (mounted) toast(context, '${tr('تعذر استخدام البصمة')}: $e', error: true);
        return;
      }
    }
    // Selfie is required when the company policy enables it (meta.policy.selfie_enabled)
    String? selfiePath;
    if (!mounted) return;
    final selfieOn = (context.read<AppState>().meta?['policy']?['selfie_enabled']) == true;
    if (selfieOn) {
      final shot = await ImagePicker().pickImage(source: ImageSource.camera, preferredCameraDevice: CameraDevice.front, maxWidth: 640, imageQuality: 70);
      if (shot == null) {
        if (mounted) toast(context, tr('التقط صورتك أولًا'), error: true);
        return;
      }
      selfiePath = shot.path;
    }
    setState(() => _punching = true);
    try {
      final pos = await _position();
      final fields = <String, String>{
        if (pos != null) 'lat': '${pos.latitude}',
        if (pos != null) 'lng': '${pos.longitude}',
        if (pos != null) 'accuracy': '${pos.accuracy.round()}',
        if (note != null && note.isNotEmpty) 'note': note,
        'client_time': DateTime.now().toIso8601String(),
        'device': 'HC mobile app',
      };
      final r = selfiePath != null ? await Api.postMultipart('/attendance/punch', fields, files: {selfiePath: 'selfie'}) : await Api.post('/attendance/punch', fields);
      final rec = r['record'];
      if (mounted) {
        final late = rec['late_minutes'] ?? 0;
        final pending = rec['pending'] == true;
        if (isOut && rec['note_pending'] == true) {
          toast(context, '${tr('سجّلت انصرافك')} ${hm(rec['check_out'])}\n${tr('تحضير اليوم معلّق حتى تكتب المنجز اليومي من «حضوري»')}', error: true);
        } else {
          toast(context, isOut ? '${tr('سجّلت انصرافك')} ${hm(rec['check_out'])}' : '${tr('سجّلت حضورك')} ${hm(rec['check_in'])}${late > 0 ? ' · ${tr('متأخر')} $late ${tr('دقيقة')}' : ''}${pending ? '\n${r['geo']['msg']}' : ''}', error: pending);
        }
      }
      await load();
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    } finally {
      if (mounted) setState(() => _punching = false);
    }
  }

  /// The daily achievement at check-out (owner's note): when required, check-out still works without it but the day
  /// stays pending until it is written — the employee is told so before confirming.
  Future<String?> _noteDialog(bool required, int min, String initial) {
    final c = TextEditingController(text: initial);
    return showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, set) {
        final len = c.text.trim().length;
        final short = required && len < min;
        return AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Text(tr('المنجز اليومي'), style: ctx.t.titleLarge),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            TextField(controller: c, maxLines: 5, onChanged: (_) => set(() {}), decoration: InputDecoration(hintText: tr('ماذا أنجزت اليوم؟'), labelText: required ? '${tr('مطلوب لاعتماد تحضير اليوم')} *' : null)),
            if (required) ...[
              const SizedBox(height: 8),
              Text(short ? '${tr('اكتب')} $min ${tr('حرفًا على الأقل')} ($len/$min). ${tr('إن انصرفت دونه يبقى تحضير اليوم معلّقًا حتى تكتبه.')}' : tr('المنجز مكتمل — سيُعتمد تحضير اليوم'), style: ctx.t.labelMedium?.copyWith(color: short ? HcColors.warn : HcColors.ok)),
            ],
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('إلغاء'))), FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(110, 44)), onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('تسجيل انصراف')))],
        );
      }),
    );
  }

  void _new(String type) => Navigator.push(context, MaterialPageRoute(builder: (_) => NewRequestScreen(type: type))).then((_) => load());

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final emp = s.employee;
    final me = d?['me'];
    final inbox = d?['kpis']?['inbox'] ?? 0;
    final now = DateTime.now();
    final firstName = (s.user?['name'] ?? '').toString().split(' ').first;
    final greeting = now.hour < 12 ? tr('صباح الخير') : tr('مساء الخير');

    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
        // ------------------------------------------------------------ header
        if (s.company != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            // owner's note: the company's official logo alone identifies it — its name only when no logo is set
            child: Row(children: [
              if (s.brandLogo != null)
                Flexible(child: ConstrainedBox(constraints: const BoxConstraints(maxHeight: 44, maxWidth: 220), child: Image.network(s.brandLogo!, fit: BoxFit.contain,
                    errorBuilder: (_, __, ___) => Text(s.company?['name'] ?? '', maxLines: 1, overflow: TextOverflow.ellipsis, style: context.t.titleLarge?.copyWith(color: s.brandColor ?? context.text, fontSize: 16)))))
              else
                Expanded(child: Text(s.company?['name'] ?? '', style: context.t.titleLarge?.copyWith(color: s.brandColor ?? context.text, fontSize: 16), maxLines: 1, overflow: TextOverflow.ellipsis)),
            ]),
          ),
        Row(children: [
          HcAvatar(s.user?['name'] ?? '?', size: 48),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('$greeting، $firstName', style: context.t.titleLarge),
              Text('${dayName(now.weekday % 7)} ${now.day} ${monthName(now.month)}${emp?['job_title']?['name'] != null ? ' · ${emp!['job_title']['name']}' : ''}', style: context.t.labelMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
            ]),
          ),
          IconButton(
            tooltip: tr('البريد الداخلي'),
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const MailScreen())).then((_) => load()),
            icon: Badge(isLabelVisible: mailUnread > 0, label: Text('$mailUnread'), backgroundColor: brandOf(context), textColor: inkOn(brandOf(context)), child: Icon(Icons.mail_outline, color: context.text)),
          ),
          if (s.isApprover)
            IconButton(
              tooltip: tr('الاعتمادات'),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ApprovalsScreen())).then((_) => load()),
              icon: Badge(isLabelVisible: inbox > 0, label: Text('$inbox'), backgroundColor: brandOf(context), textColor: inkOn(brandOf(context)), child: Icon(Icons.task_alt_outlined, color: context.text)),
            ),
        ]),
        const SizedBox(height: 16),
        if (d == null && !_failed) const SkeletonList(count: 3),
        if (d == null && _failed) EmptyView(tr('تعذر الاتصال بالخادم'), hint: tr('اسحب للأسفل لإعادة المحاولة')),
        if (me != null) ...[
          _PunchCard(me: me, punching: _punching, onPunch: punch),
          const SizedBox(height: 12),
          _MonthBar(month: me['month'], onTap: () => widget.onNavigate(1)),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: MetricTile(label: tr('رصيد الإجازة'), value: '${me['annual']?['available'] ?? 0}', hint: tr('يوم متاح'), icon: Icons.beach_access_outlined, color: HcColors.periwinkle, onTap: () => _new('leave'))),
            const SizedBox(width: 12),
            Expanded(child: MetricTile(label: tr('طلبات معلّقة'), value: '${me['pending_mine'] ?? 0}', hint: tr('بانتظار الاعتماد'), icon: Icons.hourglass_top_outlined, color: HcColors.warn, onTap: () => widget.onNavigate(2))),
          ]),
          const SizedBox(height: 20),
          if (s.isApprover && inbox > 0) ...[
            HcCard(
              color: HcColors.warn.withValues(alpha: context.isDark ? .18 : .10),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ApprovalsScreen())).then((_) => load()),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              child: Row(children: [
                const Icon(Icons.pending_actions_outlined, color: HcColors.warn),
                const SizedBox(width: 12),
                Expanded(child: Text('$inbox ${tr('بانتظار اعتمادك')}', style: context.t.bodyLarge?.copyWith(fontWeight: FontWeight.w700))),
                Icon(Icons.chevron_left, color: context.muted),
              ]),
            ),
            const SizedBox(height: 12),
          ],
          if ((me['todo'] as List?)?.isNotEmpty == true) ...[
            SectionTitle(tr('مهام تنتظرك')),
            HcCard(
              padding: EdgeInsets.zero,
              child: Column(children: [
                for (final (i, t) in (me['todo'] as List).indexed) ...[
                  if (i > 0) const Divider(indent: 68),
                  ListRow(icon: _todoIcon(t['kind']), color: statusColor(t['kind'] ?? 'info'), title: t['label'] ?? '', onTap: () => openScreen(context, '${t['screen'] ?? ''}', tab: widget.onNavigate, title: '${t['label'] ?? ''}')),
                ],
              ]),
            ),
            const SizedBox(height: 20),
          ],
          SectionTitle(tr('إجراءات سريعة')),
          Row(children: [
            Expanded(child: _QuickAction(icon: Icons.beach_access_outlined, label: tr('طلب إجازة'), color: HcColors.periwinkle, onTap: () => _new('leave'))),
            const SizedBox(width: 10),
            Expanded(child: _QuickAction(icon: Icons.edit_calendar_outlined, label: tr('تصحيح حضور'), color: HcColors.deepBlue, onTap: () => _new('correction'))),
            const SizedBox(width: 10),
            Expanded(child: _QuickAction(icon: Icons.add_circle_outline, label: tr('طلب جديد'), color: HcColors.purple, onTap: () => widget.onNavigate(2))),
          ]),
        ],
      ]),
    );
  }

  IconData _todoIcon(dynamic kind) => switch (kind) {
        'bad' => Icons.error_outline,
        'warn' => Icons.warning_amber_outlined,
        _ => Icons.info_outline,
      };
}

class _QuickAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;
  const _QuickAction({required this.icon, required this.label, required this.color, required this.onTap});
  @override
  Widget build(BuildContext context) => HcCard(
        onTap: onTap,
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
        child: Column(children: [
          Container(width: 40, height: 40, alignment: Alignment.center, decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)), child: Icon(icon, color: color, size: 20)),
          const SizedBox(height: 8),
          Text(label, style: context.t.labelMedium?.copyWith(color: context.text, fontWeight: FontWeight.w600), textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis),
        ]),
      );
}

/// Present / late / absent proportions for the month, as a segmented bar.
class _MonthBar extends StatelessWidget {
  final Map? month;
  final VoidCallback onTap;
  const _MonthBar({required this.month, required this.onTap});
  @override
  Widget build(BuildContext context) {
    final p = (month?['present'] ?? 0) as num, l = (month?['late'] ?? 0) as num, a = (month?['absent'] ?? 0) as num;
    final req = (month?['required'] ?? 0) as num;
    final total = math.max(1, math.max(req, p + a).toInt());
    Widget seg(num v, Color c) => Expanded(flex: math.max(0, (v * 1000 / total).round()), child: Container(color: c));
    return HcCard(
      onTap: onTap,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [Expanded(child: Text(tr('هذا الشهر'), style: context.t.titleLarge)), Text('${p.toInt()}/${req.toInt()} ${tr('يوم')}', style: context.t.labelMedium)]),
        const SizedBox(height: 12),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: SizedBox(
            height: 10,
            child: Row(children: [
              seg(p - l, HcColors.ok),
              seg(l, HcColors.warn),
              seg(a, HcColors.bad),
              Expanded(flex: math.max(0, ((total - p - a) * 1000 / total).round()), child: Container(color: context.line)),
            ]),
          ),
        ),
        const SizedBox(height: 10),
        Row(children: [
          _Legend(color: HcColors.ok, label: tr('حاضر'), value: (p - l).toInt()),
          _Legend(color: HcColors.warn, label: tr('متأخر'), value: l.toInt()),
          _Legend(color: HcColors.bad, label: tr('غياب'), value: a.toInt()),
        ]),
      ]),
    );
  }
}

class _Legend extends StatelessWidget {
  final Color color;
  final String label;
  final int value;
  const _Legend({required this.color, required this.label, required this.value});
  @override
  Widget build(BuildContext context) => Expanded(
        child: Row(children: [
          Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Flexible(child: Text('$label ', style: context.t.labelMedium, maxLines: 1, overflow: TextOverflow.ellipsis)),
          Text('$value', style: context.t.labelMedium?.copyWith(color: context.text, fontWeight: FontWeight.w700)),
        ]),
      );
}

/// The attendance ring: progress = share of today's shift elapsed since check-in.
class _PunchCard extends StatefulWidget {
  final Map me;
  final bool punching;
  final Future<void> Function(bool isOut) onPunch;
  const _PunchCard({required this.me, required this.punching, required this.onPunch});
  @override
  State<_PunchCard> createState() => _PunchCardState();
}

class _PunchCardState extends State<_PunchCard> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(vsync: this, duration: const Duration(seconds: 2))..repeat(reverse: true);

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  double _progress(Map? today, List? shift) {
    if (today?['check_out'] != null) return 1;
    final inAt = today?['check_in'] == null ? null : DateTime.tryParse(today!['check_in'].toString())?.toLocal();
    if (inAt == null) return 0;
    var hours = 8.0;
    if (shift != null && shift.length == 2) {
      final a = shift[0].toString().split(':'), b = shift[1].toString().split(':');
      final start = int.parse(a[0]) * 60 + int.parse(a[1]), end = int.parse(b[0]) * 60 + int.parse(b[1]);
      if (end > start) hours = (end - start) / 60;
    }
    return (DateTime.now().difference(inAt).inMinutes / (hours * 60)).clamp(0.0, 1.0);
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.me;
    final today = me['today'] as Map?;
    final shift = me['shift'] as List?;
    final checkedIn = today?['check_in'] != null;
    final checkedOut = today?['check_out'] != null;
    final required = me['punch_required'] == true;
    final progress = _progress(today, shift);
    final color = checkedOut ? HcColors.ok : checkedIn ? HcColors.warn : HcColors.deepBlue;
    final label = !required ? tr('معفى من التحضير') : checkedOut ? tr('يوم مكتمل') : checkedIn ? tr('تسجيل انصراف') : tr('تسجيل حضور');
    final subtitle = !required
        ? tr('لا يُطلب منك تسجيل الحضور')
        : checkedOut
            ? '${hm(today!['check_in'])} → ${hm(today['check_out'])}'
            : checkedIn
                ? '${tr('سجّلت حضورك')} ${hm(today!['check_in'])}${(today['late_minutes'] ?? 0) > 0 ? ' · ${tr('متأخر')} ${today['late_minutes']} ${tr('دقيقة')}' : ''}'
                : shift != null
                    ? '${tr('دوام اليوم')} ${_hm(shift[0])} – ${_hm(shift[1])}'
                    : tr('لم تسجّل حضورك بعد');

    return HcCard(
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 16),
      child: Column(children: [
        Text(tr('حضور اليوم'), style: context.t.labelSmall),
        const SizedBox(height: 14),
        GestureDetector(
          onTap: !required || widget.punching || checkedOut ? null : () => widget.onPunch(checkedIn),
          child: AnimatedBuilder(
            animation: _pulse,
            builder: (_, child) => CustomPaint(painter: _RingPainter(progress: progress, color: color, track: context.line, glow: checkedIn && !checkedOut ? .35 + _pulse.value * .35 : 0), child: child),
            child: SizedBox(
              width: 176,
              height: 176,
              child: Center(
                child: Container(
                  width: 136,
                  height: 136,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(shape: BoxShape.circle, gradient: checkedOut || !required ? null : const LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [HcColors.navy, HcColors.deepBlue]), color: checkedOut || !required ? color.withValues(alpha: .12) : null, boxShadow: checkedOut || !required ? null : const [BoxShadow(color: Color(0x40262E6B), blurRadius: 24, offset: Offset(0, 10))]),
                  child: widget.punching
                      ? const CircularProgressIndicator(color: Colors.white)
                      : Column(mainAxisSize: MainAxisSize.min, children: [
                          Icon(checkedOut ? Icons.check_rounded : Icons.fingerprint, color: checkedOut || !required ? color : Colors.white, size: 40),
                          const SizedBox(height: 4),
                          Text(label, textAlign: TextAlign.center, style: TextStyle(color: checkedOut || !required ? color : Colors.white, fontSize: 14, fontWeight: FontWeight.w700)),
                        ]),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 12),
        Text(subtitle, textAlign: TextAlign.center, style: context.t.bodyLarge?.copyWith(fontWeight: FontWeight.w600)),
        if (required && !checkedOut) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('اضغط الدائرة ثم أكّد ببصمتك — يُسجَّل موقعك تلقائيًا'), textAlign: TextAlign.center, style: context.t.labelMedium)),
        if (today?['pending'] == true) Padding(padding: const EdgeInsets.only(top: 8), child: StatusBadge(tr('بانتظار مراجعة الموقع'), color: HcColors.warn, icon: Icons.location_off_outlined)),
      ]),
    );
  }

  String _hm(dynamic v) {
    final p = v.toString().split(':');
    final h = int.tryParse(p[0]) ?? 0;
    final ap = L.lang == 'en' ? (h < 12 ? 'AM' : 'PM') : (h < 12 ? 'ص' : 'م');
    return '${two(h % 12 == 0 ? 12 : h % 12)}:${p.length > 1 ? p[1] : '00'} $ap';
  }
}

class _RingPainter extends CustomPainter {
  final double progress, glow;
  final Color color, track;
  _RingPainter({required this.progress, required this.color, required this.track, required this.glow});
  @override
  void paint(Canvas canvas, Size size) {
    final c = size.center(Offset.zero);
    final r = size.width / 2 - 8;
    canvas.drawCircle(c, r, Paint()..color = track..style = PaintingStyle.stroke..strokeWidth = 10);
    if (glow > 0) canvas.drawCircle(c, r, Paint()..color = color.withValues(alpha: glow * .5)..style = PaintingStyle.stroke..strokeWidth = 10 + glow * 10..maskFilter = const MaskFilter.blur(BlurStyle.normal, 8));
    if (progress > 0) {
      final rect = Rect.fromCircle(center: c, radius: r);
      canvas.drawArc(rect, -math.pi / 2, 2 * math.pi * progress, false, Paint()..shader = SweepGradient(startAngle: -math.pi / 2, endAngle: 3 * math.pi / 2, colors: [color, HcColors.cyan], transform: const GradientRotation(-math.pi / 2)).createShader(rect)..style = PaintingStyle.stroke..strokeWidth = 10..strokeCap = StrokeCap.round);
    }
  }

  @override
  bool shouldRepaint(_RingPainter o) => o.progress != progress || o.color != color || o.glow != glow;
}
