import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart' show Color;
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'l10n.dart';
import 'push.dart';

class AppState extends ChangeNotifier {
  Map<String, dynamic>? user;
  Map<String, dynamic>? meta;
  bool ready = false;
  String locale = 'ar';
  bool dark = false;
  bool appLock = false; // require fingerprint/face when the app opens with a saved session
  bool locked = false;

  Map<String, dynamic>? get employee => user?['employee'] as Map<String, dynamic>?;
  Map<String, dynamic>? get company => employee?['company'] as Map<String, dynamic>?;
  /// Company identity (§47): primary colour, accent, logo — null when the company has none (the group identity is used).
  Color? get brandColor => _hex(company?['color']);
  Color? get brandColor2 => _hex(company?['color2']);
  String? get brandLogo => company?['logo_url'] as String?;
  static Color? _hex(dynamic v) {
    final s = (v ?? '').toString().replaceFirst('#', '');
    return s.length == 6 ? Color(int.parse('FF$s', radix: 16)) : null;
  }
  List get roles => (user?['roles'] as List?) ?? [];
  bool get isApprover => roles.any((r) => r != 'emp') || user?['has_reports'] == true;
  Map<String, dynamic> get policy => Map<String, dynamic>.from(meta?['policy'] ?? {});

  Future<void> boot() async {
    await Api.load();
    final p = await SharedPreferences.getInstance();
    locale = Api.locale;
    L.lang = locale;
    dark = p.getBool('dark') ?? false;
    appLock = p.getBool('app_lock') ?? false;
    if (Api.token != null) {
      try {
        user = Map<String, dynamic>.from(await Api.get('/auth/me'));
        meta = Map<String, dynamic>.from(await Api.get('/meta'));
        locked = appLock;
        Push.register();
      } catch (_) {
        await Api.saveToken(null);
      }
    }
    ready = true;
    notifyListeners();
  }

  Future<void> login(String login, String password) async {
    final r = await Api.post('/auth/login', {'login': login, 'password': password, 'device': 'mobile'});
    await Api.saveToken(r['token']);
    user = Map<String, dynamic>.from(r['user']);
    meta = Map<String, dynamic>.from(await Api.get('/meta'));
    locked = false;
    Push.register();
    notifyListeners();
  }

  Future<void> refreshMeta() async {
    try {
      meta = Map<String, dynamic>.from(await Api.get('/meta'));
      notifyListeners();
    } catch (_) {}
  }

  void unlock() {
    locked = false;
    notifyListeners();
  }

  Future<void> logout() async {
    await Push.unregister();
    try {
      await Api.post('/auth/logout');
    } catch (_) {}
    await Api.saveToken(null);
    user = null;
    locked = false;
    notifyListeners();
  }

  Future<void> setLocale(String l) async {
    locale = l;
    L.lang = l;
    Api.locale = l;
    (await SharedPreferences.getInstance()).setString('locale', l);
    if (user != null) Api.post('/auth/locale', {'locale': l}).ignore();
    notifyListeners();
  }

  Future<void> setDark(bool v) async {
    dark = v;
    (await SharedPreferences.getInstance()).setBool('dark', v);
    notifyListeners();
  }

  Future<void> setAppLock(bool v) async {
    appLock = v;
    (await SharedPreferences.getInstance()).setBool('app_lock', v);
    notifyListeners();
  }
}
