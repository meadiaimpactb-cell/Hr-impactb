import 'dart:async';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'api.dart';
import 'l10n.dart';
import 'theme.dart';
import 'widgets.dart';

/// §28 — presence checks and location tracking.
///  * A manager's presence check arrives as a push / is found on resume; the employee confirms with the phone's location.
///  * While checked in (and not yet checked out) the app reports its position every few minutes — only when the group
///    CEO enabled tracking for the company. Nothing is sent after check-out or outside working time.
class Presence {
  static Timer? _timer;
  static bool _dialogOpen = false;
  static bool tracking = false;
  static int interval = 10;

  static Future<Position?> position() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      var p = await Geolocator.checkPermission();
      if (p == LocationPermission.denied) p = await Geolocator.requestPermission();
      if (p == LocationPermission.denied || p == LocationPermission.deniedForever) return null;
      return await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 12)));
    } catch (_) {
      return null;
    }
  }

  /// Looks for an open presence check and shows the confirmation dialog; also refreshes the tracking settings.
  static Future<void> check(BuildContext context) async {
    if (Api.token == null) return;
    try {
      final r = await Api.get('/attendance/presence/pending');
      tracking = r['tracking'] == true;
      interval = (r['track_interval'] ?? 10) as int;
      _schedule();
      final c = r['check'];
      if (c != null && context.mounted && !_dialogOpen) await _dialog(context, Map<String, dynamic>.from(c));
    } catch (_) {}
  }

  static Future<void> _dialog(BuildContext context, Map<String, dynamic> c) async {
    _dialogOpen = true;
    try {
      await showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.where_to_vote_outlined, color: HcColors.warn, size: 36),
          title: Text(tr('طلب تحضير — أكّد تواجدك')),
          content: Text('${c['by'] ?? ''} ${tr('يطلب تأكيد تواجدك في موقع العمل الآن. يُرسل موقعك الحالي مع التأكيد.')}\n${tr('المهلة حتى')} ${hm(c['due_at'])}'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('لاحقًا'))),
            FilledButton.icon(
              icon: const Icon(Icons.my_location),
              label: Text(tr('أؤكد تواجدي')),
              onPressed: () async {
                final pos = await position();
                try {
                  final r = await Api.post('/attendance/presence/${c['id']}/confirm', {if (pos != null) 'lat': pos.latitude, if (pos != null) 'lng': pos.longitude, if (pos != null) 'accuracy': pos.accuracy.round()});
                  if (ctx.mounted) Navigator.pop(ctx);
                  if (context.mounted) toast(context, r['msg'] ?? tr('تم تأكيد تواجدك'), error: r['inside'] != true);
                } catch (e) {
                  if (context.mounted) toast(context, errText(e), error: true);
                }
              },
            ),
          ],
        ),
      );
    } finally {
      _dialogOpen = false;
    }
  }

  static void _schedule() {
    _timer?.cancel();
    if (!tracking) return;
    _timer = Timer.periodic(Duration(minutes: interval.clamp(2, 60)), (_) => ping());
  }

  /// One position report; the server ignores it outside working time (before check-in / after check-out).
  static Future<void> ping() async {
    if (!tracking || Api.token == null) return;
    final pos = await position();
    if (pos == null) return;
    try {
      await Api.post('/attendance/track', {'lat': pos.latitude, 'lng': pos.longitude, 'accuracy': pos.accuracy.round()});
    } catch (_) {}
  }

  static void stop() {
    _timer?.cancel();
    _timer = null;
  }
}
