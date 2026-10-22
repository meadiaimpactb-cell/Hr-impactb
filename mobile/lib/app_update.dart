import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show defaultTargetPlatform, TargetPlatform;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'api.dart';
import 'l10n.dart';
import 'widgets.dart';

/// Android self-update: compares the installed build with /app/version; a newer build is downloaded inside the app and
/// handed to the system installer, which installs it over the current app — nothing to uninstall, data kept.
class AppUpdate {
  static const _ch = MethodChannel('hc/update');
  static bool _busy = false;
  static DateTime? _lastAuto;

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

  /// On launch and when the app comes back: offers a newer version by itself (at most every 3 hours; «later» waits a day).
  static Future<void> auto(BuildContext context) async {
    if (defaultTargetPlatform != TargetPlatform.android || Platform.environment.containsKey('FLUTTER_TEST')) return;
    if (_lastAuto != null && DateTime.now().difference(_lastAuto!) < const Duration(hours: 3)) return;
    _lastAuto = DateTime.now();
    try {
      final p = await SharedPreferences.getInstance();
      final snooze = DateTime.tryParse(p.getString('update_snooze_until') ?? '');
      if (snooze != null && DateTime.now().isBefore(snooze)) return;
      if (context.mounted) await run(context, silent: true);
    } catch (_) {/* offline or no plugin: asked again next time */}
  }

  static Future<void> run(BuildContext context, {bool silent = false}) async {
    if (_busy) return;
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
    final mb = ((latest['size'] ?? 0) as num) / 1048576;
    final go = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        title: Text('${tr('تحديث جديد متاح')} ${latest['version']}'),
        content: SingleChildScrollView(
          child: Text('${tr('الإصدار الحالي')}: ${info.version}\n\n${latest['notes'] ?? ''}\n\n'
              '${tr('يُنزَّل التحديث داخل التطبيق ثم يُثبَّت فوق النسخة الحالية — لا تحذف التطبيق، وتبقى بياناتك.')}${mb > 0 ? ' (${mb.toStringAsFixed(0)} MB)' : ''}'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('لاحقًا'))),
          FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 44)), onPressed: () => Navigator.pop(ctx, true), child: Text(tr('تحديث الآن'))),
        ],
      ),
    );
    if (go != true) {
      final p = await SharedPreferences.getInstance();
      await p.setString('update_snooze_until', DateTime.now().add(const Duration(days: 1)).toIso8601String());
      return;
    }
    if (context.mounted) await _install(context, '${latest['url']}');
  }

  static Future<void> _install(BuildContext context, String url) async {
    _busy = true;
    try {
      // Android 8+: HC must be allowed to install updates (asked once by the system)
      if (await _ch.invokeMethod<bool>('canInstall') != true) {
        if (!context.mounted) return;
        final ok = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: Text(tr('السماح بتثبيت التحديث')),
            content: Text(tr('فعّل «السماح من هذا المصدر» لتطبيق HC في الشاشة التالية، ثم ارجع إلى التطبيق ليكمل التحديث.')),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('إلغاء'))),
              FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 44)), onPressed: () => Navigator.pop(ctx, true), child: Text(tr('فتح الإعدادات'))),
            ],
          ),
        );
        if (ok == true) {
          _lastAuto = null; // back from the settings → offered again right away
          await _ch.invokeMethod('allowInstall');
        }
        return;
      }
      if (!context.mounted) return;
      final progress = ValueNotifier<int>(0);
      _ch.setMethodCallHandler((c) async {
        if (c.method == 'progress') progress.value = (c.arguments as int?) ?? -1;
      });
      showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => PopScope(
          canPop: false,
          child: AlertDialog(
            title: Text(tr('جارٍ تنزيل التحديث')),
            content: ValueListenableBuilder<int>(
              valueListenable: progress,
              builder: (_, p, __) => Column(mainAxisSize: MainAxisSize.min, children: [
                LinearProgressIndicator(value: p >= 0 ? p / 100 : null),
                const SizedBox(height: 10),
                Text(p >= 0 ? '$p%' : tr('جارٍ التنزيل…')),
              ]),
            ),
          ),
        ),
      );
      String? path;
      try {
        path = await _ch.invokeMethod<String>('download', {'url': url});
      } finally {
        _ch.setMethodCallHandler(null);
        if (context.mounted) Navigator.of(context, rootNavigator: true).pop();
      }
      if (path == null) return;
      await _ch.invokeMethod('install', {'path': path}); // the system shows «تحديث هذا التطبيق؟» → تثبيت
    } on MissingPluginException {
      // an older native shell: fall back to downloading through the browser
      if (!await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication) && context.mounted) toast(context, tr('تعذر فتح رابط التنزيل'), error: true);
    } catch (e) {
      if (context.mounted) toast(context, '${tr('تعذر تنزيل التحديث')}: $e', error: true);
    } finally {
      _busy = false;
    }
  }
}
