import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

/// Base URL of the Laravel API.
/// Android emulator reaches the host machine via 10.0.2.2; a real phone needs the PC's LAN IP
/// (run Laravel with: php artisan serve --host=0.0.0.0 --port=8000).
const String kApiBase = String.fromEnvironment('API_BASE', defaultValue: 'https://hc.impactv.sa/api/v1');

class ApiException implements Exception {
  final int status;
  final String message;
  final Map<String, dynamic> errors;
  ApiException(this.status, this.message, [this.errors = const {}]);
  @override
  String toString() => message;
}

class Api {
  static String? token;
  static String locale = 'ar';

  static Future<void> load() async {
    final p = await SharedPreferences.getInstance();
    token = p.getString('token');
    locale = p.getString('locale') ?? 'ar';
  }

  static Future<void> saveToken(String? t) async {
    token = t;
    final p = await SharedPreferences.getInstance();
    t == null ? await p.remove('token') : await p.setString('token', t);
  }

  static Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Channel': 'mobile',
        'X-Locale': locale,
        if (token != null) 'Authorization': 'Bearer $token',
      };

  static Future<dynamic> get(String path, [Map<String, String>? query]) async {
    final uri = Uri.parse('$kApiBase$path').replace(queryParameters: query);
    return _handle(await http.get(uri, headers: _headers).timeout(const Duration(seconds: 20)));
  }

  static Future<dynamic> post(String path, [Map<String, dynamic>? body]) async {
    final res = await http.post(Uri.parse('$kApiBase$path'), headers: _headers, body: jsonEncode(body ?? {})).timeout(const Duration(seconds: 20));
    return _handle(res);
  }

  /// Multipart POST (fields + optional files as path → field name).
  static Future<dynamic> postMultipart(String path, Map<String, String> fields, {Map<String, String> files = const {}}) async {
    final req = http.MultipartRequest('POST', Uri.parse('$kApiBase$path'));
    req.headers.addAll({..._headers}..remove('Content-Type'));
    req.fields.addAll(fields);
    for (final e in files.entries) {
      req.files.add(await http.MultipartFile.fromPath(e.value, e.key));
    }
    final streamed = await req.send().timeout(const Duration(seconds: 40));
    return _handle(await http.Response.fromStream(streamed));
  }

  static Future<dynamic> put(String path, [Map<String, dynamic>? body]) async {
    final res = await http.put(Uri.parse('$kApiBase$path'), headers: _headers, body: jsonEncode(body ?? {})).timeout(const Duration(seconds: 20));
    return _handle(res);
  }

  static Future<dynamic> delete(String path, [Map<String, dynamic>? body]) async {
    final res = await http.delete(Uri.parse('$kApiBase$path'), headers: _headers, body: jsonEncode(body ?? {})).timeout(const Duration(seconds: 20));
    return _handle(res);
  }

  static dynamic _handle(http.Response r) {
    final body = r.body.isEmpty ? null : jsonDecode(utf8.decode(r.bodyBytes));
    if (r.statusCode >= 200 && r.statusCode < 300) return body;
    final msg = (body is Map && body['message'] is String) ? body['message'] as String : 'خطأ ${r.statusCode}';
    final errs = (body is Map && body['errors'] is Map) ? Map<String, dynamic>.from(body['errors']) : <String, dynamic>{};
    throw ApiException(r.statusCode, msg, errs);
  }
}
