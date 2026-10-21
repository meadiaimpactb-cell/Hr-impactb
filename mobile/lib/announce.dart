import 'package:flutter/material.dart';

import 'api.dart';
import 'l10n.dart';
import 'theme.dart';
import 'widgets.dart';

/// Announcements and manager alerts pop up in the app (owner's note), as on the website: the ones that require a read
/// confirmation stay until the employee confirms (the time is recorded as proof); the others until he marks them read
/// or chooses "later" (they come back on the next start).
class Announce {
  static final Set<int> _later = {};
  static bool _busy = false;

  static Future<void> check(BuildContext context) async {
    if (_busy || Api.token == null) return;
    _busy = true;
    try {
      final list = (await Api.get('/announcements/pending-ack')) as List;
      for (final a in list) {
        if (!context.mounted) break;
        final ack = a['require_ack'] == true;
        final id = a['id'] as int;
        if (!ack && _later.contains(id)) continue;
        final r = await showDialog<String>(context: context, barrierDismissible: false, builder: (ctx) => _Popup(a: a));
        if (r == 'ack') {
          await Api.post('/announcements/$id/ack');
          if (context.mounted) toast(context, tr('سُجّل تأكيد قراءتك'));
        } else if (r == 'read') {
          await Api.post('/announcements/$id/read');
        } else {
          _later.add(id);
        }
      }
    } catch (_) {
      // offline or no announcements: nothing to show
    } finally {
      _busy = false;
    }
  }
}

class _Popup extends StatelessWidget {
  final Map a;
  const _Popup({required this.a});
  @override
  Widget build(BuildContext context) {
    final ack = a['require_ack'] == true;
    final team = a['audience']?['scope'] == 'team';
    return PopScope(
      canPop: false,
      child: AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        titlePadding: const EdgeInsets.fromLTRB(20, 16, 8, 0),
        title: Row(children: [
          Container(width: 36, height: 36, alignment: Alignment.center, decoration: BoxDecoration(color: HcColors.cyan.withValues(alpha: .15), borderRadius: BorderRadius.circular(10)), child: const Icon(Icons.campaign_outlined, color: HcColors.deepBlue, size: 20)),
          const SizedBox(width: 10),
          Expanded(child: Text(tr(team ? 'تنبيه من مديرك' : 'إعلان جديد'), style: context.t.titleLarge)),
          if (!ack) IconButton(tooltip: tr('لاحقًا'), onPressed: () => Navigator.pop(context, 'later'), icon: const Icon(Icons.close)),
        ]),
        content: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * .55),
          child: SingleChildScrollView(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              Text('${a['title'] ?? ''}', style: context.t.bodyLarge?.copyWith(fontWeight: FontWeight.w800)),
              Text('${a['sender'] ?? ''} · ${fmtDateTime(a['published_at'])}', style: context.t.labelSmall),
              const SizedBox(height: 10),
              Text('${a['body'] ?? ''}'),
              if (ack) ...[
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(color: HcColors.warn.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
                  child: Text(tr('المرسل طلب تأكيد القراءة: يُسجَّل تأكيدك بالتاريخ والوقت إثباتًا لاطلاعك'), style: context.t.labelMedium),
                ),
              ],
            ]),
          ),
        ),
        actions: ack
            ? [FilledButton.icon(style: FilledButton.styleFrom(minimumSize: const Size(160, 46)), icon: const Icon(Icons.check_circle_outline), label: Text(tr('أؤكد قراءتي واطلاعي')), onPressed: () => Navigator.pop(context, 'ack'))]
            : [TextButton(onPressed: () => Navigator.pop(context, 'later'), child: Text(tr('لاحقًا'))), FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(120, 46)), onPressed: () => Navigator.pop(context, 'read'), child: Text(tr('تمت القراءة')))],
      ),
    );
  }
}
