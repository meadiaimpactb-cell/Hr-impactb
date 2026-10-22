import 'package:flutter/material.dart';
import 'package:local_auth/local_auth.dart';
import 'package:provider/provider.dart';

import '../l10n.dart';
import '../state.dart';
import '../theme.dart';
import '../widgets.dart';

/// Navy hero with the logo pattern and a bottom sheet card. `lockMode` = a saved session that needs biometric unlock.
class LoginScreen extends StatefulWidget {
  final bool lockMode;
  const LoginScreen({super.key, this.lockMode = false});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _login = TextEditingController();
  final _pass = TextEditingController();
  bool _busy = false;
  bool _obscure = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (widget.lockMode) WidgetsBinding.instance.addPostFrameCallback((_) => _biometric());
  }

  Future<void> _submit() async {
    if (_login.text.trim().isEmpty || _pass.text.isEmpty) return setState(() => _error = tr('أدخل اسم المستخدم وكلمة المرور'));
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<AppState>().login(_login.text.trim(), _pass.text);
    } catch (e) {
      if (mounted) setState(() => _error = errText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _biometric() async {
    try {
      final auth = LocalAuthentication();
      if (!await auth.isDeviceSupported()) return;
      final ok = await auth.authenticate(localizedReason: tr('أكّد هويتك لفتح التطبيق'), options: const AuthenticationOptions(stickyAuth: true));
      if (ok && mounted) context.read<AppState>().unlock();
    } catch (e) {
      if (mounted) toast(context, errText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    return Scaffold(
      backgroundColor: HcColors.navy,
      body: Stack(children: [
        const Positioned(top: 40, left: -30, child: LogoPattern(opacity: .12, size: 260)),
        const Positioned(top: 260, right: -60, child: LogoPattern(opacity: .08, size: 220)),
        SafeArea(
          child: Column(children: [
            const Spacer(),
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24), boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 30, offset: Offset(0, 12))]),
              child: Image.asset('assets/logo-hc.png', width: 250, fit: BoxFit.contain, errorBuilder: (_, __, ___) => const Icon(Icons.business, size: 60, color: HcColors.navy)),
            ),
            const SizedBox(height: 14),
            Text(tr('مجموعة وادي الأثر المالية'), style: context.t.labelMedium?.copyWith(color: Colors.white70)),
            const Spacer(),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
              decoration: BoxDecoration(color: context.card, borderRadius: const BorderRadius.vertical(top: Radius.circular(28))),
              child: widget.lockMode ? _lockCard(s) : _loginCard(s),
            ),
          ]),
        ),
      ]),
    );
  }

  Widget _lockCard(AppState s) => Column(mainAxisSize: MainAxisSize.min, children: [
        HcAvatar(s.user?['name'] ?? '?', size: 56),
        const SizedBox(height: 10),
        Text(s.user?['name'] ?? '', style: context.t.titleLarge),
        Text(tr('التطبيق مقفل — أكّد هويتك للمتابعة'), style: context.t.labelMedium),
        const SizedBox(height: 20),
        PrimaryButton(label: tr('الدخول بالبصمة'), icon: Icons.fingerprint, onPressed: _biometric),
        const SizedBox(height: 8),
        TextButton(onPressed: () => s.logout(), child: Text(tr('الدخول بحساب آخر'))),
      ]);

  Widget _loginCard(AppState s) => AutofillGroup(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('تسجيل الدخول'), style: context.t.headlineMedium),
          const SizedBox(height: 4),
          Text(tr('ادخل بحساب الموظف أو رقمك الوظيفي'), style: context.t.labelMedium),
          const SizedBox(height: 20),
          TextField(controller: _login, autofillHints: const [AutofillHints.username], textInputAction: TextInputAction.next, decoration: InputDecoration(labelText: tr('اسم المستخدم أو رقم الموظف'), prefixIcon: const Icon(Icons.person_outline))),
          const SizedBox(height: 14),
          TextField(
            controller: _pass,
            obscureText: _obscure,
            autofillHints: const [AutofillHints.password],
            onSubmitted: (_) => _submit(),
            decoration: InputDecoration(
              labelText: tr('كلمة المرور'),
              prefixIcon: const Icon(Icons.lock_outline),
              errorText: _error,
              suffixIcon: IconButton(icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined), onPressed: () => setState(() => _obscure = !_obscure)),
            ),
          ),
          Align(alignment: AlignmentDirectional.centerEnd, child: TextButton(onPressed: () => toast(context, tr('تواصل مع الموارد البشرية لإعادة تعيين كلمة المرور')), child: Text(tr('نسيت كلمة المرور؟')))),
          PrimaryButton(label: tr('دخول'), onPressed: _submit, loading: _busy),
          const SizedBox(height: 16),
          Segmented<String>(items: const [('ar', 'العربية'), ('en', 'English')], value: s.locale, onChanged: (v) => s.setLocale(v)),
        ]),
      );
}
