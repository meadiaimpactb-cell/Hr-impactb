import 'dart:io' show Platform;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import 'api.dart';

/// Push notifications (FCM). Fully optional: if Firebase is not configured for the platform
/// (no google-services.json / GoogleService-Info.plist) every call fails softly and the app keeps working.
class Push {
  static bool ready = false;
  static String? token;
  static void Function(Map<String, dynamic> data)? onOpen;
  /// A message that arrives while the app is open (no system banner is shown then): the shell refreshes its badges,
  /// shows the announcement pop-up or a snack bar.
  static void Function(Map<String, dynamic> data, String title, String body)? onForeground;

  /// Set to true once ios/Runner/GoogleService-Info.plist and the APNs key are in place (needs the paid Apple account).
  static const bool iosConfigured = false;

  static Future<void> init() async {
    if (!kIsWeb && Platform.isIOS && !iosConfigured) return;
    try {
      await Firebase.initializeApp();
      final m = FirebaseMessaging.instance;
      await m.requestPermission(alert: true, badge: true, sound: true);
      token = await m.getToken();
      ready = token != null;
      m.onTokenRefresh.listen((t) {
        token = t;
        register();
      });
      FirebaseMessaging.onMessageOpenedApp.listen((msg) => onOpen?.call({...msg.data, 'title': msg.notification?.title ?? ''}));
      FirebaseMessaging.onMessage.listen((msg) => onForeground?.call(msg.data, msg.notification?.title ?? '', msg.notification?.body ?? ''));
      final initial = await m.getInitialMessage();
      if (initial != null) onOpen?.call({...initial.data, 'title': initial.notification?.title ?? ''});
    } catch (e) {
      debugPrint('Push disabled: $e');
    }
  }

  /// Send the device token to the API after login (and on refresh).
  static Future<void> register() async {
    if (token == null || Api.token == null) return;
    try {
      await Api.post('/push/register', {'token': token, 'platform': kIsWeb ? 'web' : (Platform.isIOS ? 'ios' : 'android')});
    } catch (_) {}
  }

  static Future<void> unregister() async {
    if (token == null || Api.token == null) return;
    try {
      await Api.delete('/push/register', {'token': token});
    } catch (_) {}
  }
}
