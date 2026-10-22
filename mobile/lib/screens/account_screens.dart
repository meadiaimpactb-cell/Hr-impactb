import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import '../l10n.dart';
import '../state.dart';
import '../widgets.dart';

/// Change the account password (the platform's rule: 8+ characters with letters, numbers and a symbol).
/// [force] — first sign-in with a temporary password: the screen cannot be left until it is changed.
class ChangePasswordScreen extends StatefulWidget {
  final bool force;
  const ChangePasswordScreen({super.key, this.force = false});
  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final current = TextEditingController(), pass = TextEditingController(), confirm = TextEditingController();
  Map<String, dynamic> errors = {};
  bool busy = false, show = false;

  Future<void> save() async {
    setState(() { busy = true; errors = {}; });
    try {
      await Api.post('/auth/password', {'current': current.text, 'password': pass.text, 'password_confirmation': confirm.text});
      if (!mounted) return;
      context.read<AppState>().user?['must_change_password'] = false;
      toast(context, tr('غُيّرت كلمة المرور'));
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) { setState(() => errors = e is ApiException ? e.errors : {}); toast(context, errText(e), error: true); }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  InputDecoration _dec(String label, String key) => InputDecoration(labelText: tr(label), errorText: (errors[key] as List?)?.first,
      suffixIcon: IconButton(icon: Icon(show ? Icons.visibility_off : Icons.visibility), onPressed: () => setState(() => show = !show)));

  @override
  Widget build(BuildContext context) => PopScope(
        canPop: !widget.force,
        child: Scaffold(
          appBar: AppBar(title: Text(tr('تغيير كلمة المرور')), automaticallyImplyLeading: !widget.force),
          body: ListView(padding: const EdgeInsets.all(16), children: [
            if (widget.force) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(tr('سجّلت الدخول بكلمة مرور مؤقتة — اختر كلمة مرور خاصة بك للمتابعة.'))),
            TextField(controller: current, obscureText: !show, decoration: _dec('كلمة المرور الحالية', 'current')),
            const SizedBox(height: 12),
            TextField(controller: pass, obscureText: !show, decoration: _dec('كلمة المرور الجديدة', 'password')),
            const SizedBox(height: 12),
            TextField(controller: confirm, obscureText: !show, decoration: _dec('تأكيد كلمة المرور الجديدة', 'password_confirmation')),
            const SizedBox(height: 8),
            Text(tr('8 أحرف على الأقل، وتشمل حروفًا وأرقامًا ورمزًا مثل ! أو @'), style: const TextStyle(color: Colors.black54, fontSize: 12)),
            const SizedBox(height: 20),
            SizedBox(height: 48, child: FilledButton(onPressed: busy ? null : save, child: busy ? const CircularProgressIndicator() : Text(tr('حفظ')))),
          ]),
        ),
      );
}

/// Opens the regulations PDF of a version (a short-lived signed link from the platform) in the browser.
Future<void> openRegulations(BuildContext context, {String? version}) async {
  try {
    final r = await Api.get('/policy-ack/document-link', version == null ? null : {'version': version});
    if (!await launchUrl(Uri.parse('${r['url']}'), mode: LaunchMode.externalApplication) && context.mounted) toast(context, tr('تعذر فتح الملف'), error: true);
  } catch (e) {
    if (context.mounted) toast(context, errText(e), error: true);
  }
}

/// Owner's note (22/10): the employee can always read the regulations and see what he acknowledged.
class MyPolicyScreen extends StatelessWidget {
  const MyPolicyScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('لائحتي وإقراري'))),
        body: FutureBuilder(
          future: Api.get('/policy-ack'),
          builder: (ctx, snap) {
            if (!snap.hasData) return Center(child: snap.hasError ? Text(errText(snap.error!)) : const CircularProgressIndicator());
            final st = Map<String, dynamic>.from(snap.data as Map);
            final mine = st['mine'] as Map?;
            final history = (st['history'] as List?) ?? [];
            return ListView(padding: const EdgeInsets.all(16), children: [
              Text('${tr('الإصدار الحالي')}: ${st['version']}', style: Theme.of(ctx).textTheme.titleLarge),
              const SizedBox(height: 6),
              StatusBadge(mine != null ? '${tr('أقررت')} ${mine['signed_at'] ?? ''}' : tr('لم تقرّ بعد بهذا الإصدار'), color: mine != null ? Colors.green : Colors.orange),
              const SizedBox(height: 12),
              if (st['document'] == true)
                FilledButton.icon(style: FilledButton.styleFrom(minimumSize: const Size(0, 46)), onPressed: () => openRegulations(ctx), icon: const Icon(Icons.menu_book_outlined), label: Text(tr('قراءة اللائحة كاملة')))
              else
                Text(tr('لم ترفع الموارد البشرية ملف اللائحة بعد'), style: const TextStyle(color: Colors.black54)),
              const SizedBox(height: 16),
              Text(tr('نص الإقرار'), style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 6),
              Text('${st['text'] ?? ''}', style: const TextStyle(height: 1.8)),
              if (mine != null) ...[
                const SizedBox(height: 10),
                Text('${tr('الاسم المكتوب توقيعًا')}: ${mine['typed_name'] ?? ''}'),
              ],
              if (history.isNotEmpty) ...[
                const Divider(height: 28),
                Text(tr('إقراراتي'), style: const TextStyle(fontWeight: FontWeight.w700)),
                for (final h in history)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text('${tr('الإصدار')} ${h['version']}'),
                    subtitle: Text('${h['signed_at'] ?? ''}'),
                    trailing: h['has_document'] == true ? TextButton(onPressed: () => openRegulations(ctx, version: '${h['version']}'), child: Text(tr('قراءة'))) : null,
                  ),
              ],
            ]);
          },
        ),
      );
}

/// §119 / appendix 4: the employee acknowledges the policy in force before using the app (same text and record as the web).
class PolicyAckScreen extends StatefulWidget {
  final Map<String, dynamic> status;
  const PolicyAckScreen({super.key, required this.status});

  /// Opens the acknowledgement when the current policy version is not yet acknowledged.
  static Future<void> check(BuildContext context) async {
    try {
      final st = Map<String, dynamic>.from(await Api.get('/policy-ack'));
      if (st['required'] == true && context.mounted) {
        await Navigator.push(context, MaterialPageRoute(fullscreenDialog: true, builder: (_) => PolicyAckScreen(status: st)));
      }
    } catch (_) {/* offline: asked again next time */}
  }

  @override
  State<PolicyAckScreen> createState() => _PolicyAckScreenState();
}

class _PolicyAckScreenState extends State<PolicyAckScreen> {
  late final name = TextEditingController(text: '${widget.status['employee']?['name'] ?? ''}');
  bool agree = false, busy = false;

  Future<void> sign() async {
    if (!agree || name.text.trim().length < 3) return toast(context, tr('اكتب اسمك ووافق على نص الإقرار'), error: true);
    setState(() => busy = true);
    try {
      await Api.post('/policy-ack', {'typed_name': name.text.trim(), 'agree': true});
      if (!mounted) return;
      toast(context, tr('سُجّل إقرارك وحُفظ في ملفك الوظيفي'));
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
        canPop: false,
        child: Scaffold(
          appBar: AppBar(title: Text('${tr('إقرار اللائحة')} — ${widget.status['version'] ?? ''}'), automaticallyImplyLeading: false),
          body: ListView(padding: const EdgeInsets.all(16), children: [
            Text('${widget.status['text'] ?? ''}', style: const TextStyle(height: 1.8)),
            if (widget.status['document'] == true) ...[
              const SizedBox(height: 10),
              OutlinedButton.icon(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 44)), onPressed: () => openRegulations(context), icon: const Icon(Icons.menu_book_outlined), label: Text(tr('قراءة اللائحة كاملة قبل الإقرار'))),
            ],
            const SizedBox(height: 16),
            TextField(controller: name, decoration: InputDecoration(labelText: tr('الاسم كما في الهوية (توقيع)'))),
            CheckboxListTile(contentPadding: EdgeInsets.zero, value: agree, onChanged: (v) => setState(() => agree = v == true), title: Text(tr('أقرّ بأنني اطلعت على اللائحة وألتزم بها'))),
            const SizedBox(height: 12),
            SizedBox(height: 48, child: FilledButton(onPressed: busy ? null : sign, child: busy ? const CircularProgressIndicator() : Text(tr('أقرّ وأوقّع')))),
          ]),
        ),
      );
}
