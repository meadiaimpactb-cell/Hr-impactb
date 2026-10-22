import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

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
            const SizedBox(height: 16),
            TextField(controller: name, decoration: InputDecoration(labelText: tr('الاسم كما في الهوية (توقيع)'))),
            CheckboxListTile(contentPadding: EdgeInsets.zero, value: agree, onChanged: (v) => setState(() => agree = v == true), title: Text(tr('أقرّ بأنني اطلعت على اللائحة وألتزم بها'))),
            const SizedBox(height: 12),
            SizedBox(height: 48, child: FilledButton(onPressed: busy ? null : sign, child: busy ? const CircularProgressIndicator() : Text(tr('أقرّ وأوقّع')))),
          ]),
        ),
      );
}
