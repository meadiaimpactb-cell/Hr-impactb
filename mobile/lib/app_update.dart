import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import 'api.dart';
import 'l10n.dart';
import 'widgets.dart';

/// Android self-update: compares the installed build with /app/version and opens the APK published on the platform.
class AppUpdate {
  static Future<Map<String, dynamic>?> latest() async {
    try {
      return Map<String, dynamic>.from(await Api.get('/app/version'));
    } catch (_) {
      return null;
    }
  }

  static Future<String> installed() async {
    final i = await PackageInfo.fromPlatform();
    return '${i.version}+${i.buildNumber}';
  }

  static Future<void> run(BuildContext context, {bool silent = false}) async {
    final info = await PackageInfo.fromPlatform();
    final latest = await AppUpdate.latest();
    if (latest == null) {
      if (!silent && context.mounted) toast(context, tr('تعذر الاتصال بالخادم'), error: true);
      return;
    }
    final mine = int.tryParse(info.buildNumber) ?? 0;
    final remote = (latest['build'] ?? 0) as int;
    if (remote <= mine || latest['available'] != true) {
      if (!silent && context.mounted) toast(context, '${tr('لديك أحدث إصدار')} ${info.version}+${info.buildNumber}');
      return;
    }
    if (!context.mounted) return;
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('${tr('إصدار جديد')} ${latest['version']}'),
        content: Text('${tr('الإصدار الحالي')}: ${info.version}+${info.buildNumber}\n${latest['notes'] ?? ''}\n\n${tr('يُنزَّل الملف من المنصة ثم يُثبَّت فوق النسخة الحالية دون فقد بياناتك.')}'),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('لاحقًا'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('تنزيل وتثبيت')))],
      ),
    );
    if (go == true) {
      final uri = Uri.parse(latest['url']);
      if (!await launchUrl(uri, mode: LaunchMode.externalApplication) && context.mounted) toast(context, tr('تعذر فتح رابط التنزيل'), error: true);
    }
  }
}
