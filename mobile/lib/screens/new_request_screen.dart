import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../l10n.dart';
import '../state.dart';
import '../widgets.dart';

/// Request form driven by the server's request-type definitions (meta.request_type_defs):
/// built-in handlers get bespoke fields, custom types render their form schema.
class NewRequestScreen extends StatefulWidget {
  final String type;
  const NewRequestScreen({super.key, required this.type});

  /// Self-service types the user may raise, from meta (code → name).
  static Map<String, String> titles(AppState s) {
    final defs = (s.meta?['request_type_defs'] as List?) ?? [];
    return {for (final d in defs.where((d) => d['self_service'] == true)) d['code'] as String: (s.locale == 'en' && d['name_en'] != null ? d['name_en'] : d['name']) as String};
  }

  @override
  State<NewRequestScreen> createState() => _NewRequestScreenState();
}

class _NewRequestScreenState extends State<NewRequestScreen> {
  final f = <String, dynamic>{};
  final reason = TextEditingController();
  final text2 = TextEditingController();
  List balances = [];
  bool busy = false;
  Map<String, dynamic> errors = {};
  Map<String, dynamic>? def;
  String? get handler => def?['handler'];

  @override
  void initState() {
    super.initState();
    final s = context.read<AppState>();
    final defs = (s.meta?['request_type_defs'] as List?) ?? [];
    def = defs.cast<Map<String, dynamic>?>().firstWhere((d) => d?['code'] == widget.type, orElse: () => null);
    final meta = s.meta;
    if (handler == 'leave') {
      f['leave_type'] = 'annual';
      Api.get('/leaves/balances').then((r) => mounted ? setState(() => balances = r['balances']) : null).catchError((_) => null);
    }
    if (handler == 'correction') f['kind'] = ((meta?['correction_kinds'] as List?) ?? [null]).elementAtOrNull(1);
    if (handler == 'letter') f['kind'] = (((meta?['lookups']?['letter_kinds']) as List?) ?? [{}]).first['code'];
    if (handler == 'advance') f['installments'] = 6;
  }

  String _d(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> pickDate(String key, {bool past = false}) async {
    final now = DateTime.now();
    final d = await showDatePicker(context: context, initialDate: now, firstDate: now.subtract(const Duration(days: 60)), lastDate: past ? now : now.add(const Duration(days: 365)));
    if (d != null) setState(() => f[key] = _d(d));
  }

  Future<void> submit() async {
    setState(() { busy = true; errors = {}; });
    final body = {'type': widget.type, ...f};
    if (handler == 'leave') body['handover'] = reason.text;
    if (handler == 'correction') body['reason'] = reason.text;
    if (handler == 'advance') { body['purpose'] = reason.text; body['amount'] = num.tryParse(text2.text); }
    if (handler == 'letter') body['to'] = reason.text;
    if (handler == 'trip') { body['purpose'] = reason.text; body['estimate'] = num.tryParse(text2.text) ?? 0; }
    if (handler == 'resignation') body['reason'] = reason.text;
    try {
      await Api.post('/requests', body);
      if (mounted) { toast(context, tr('أُرسل الطلب')); Navigator.pop(context, true); }
    } catch (e) {
      if (mounted) { setState(() => errors = e is ApiException ? e.errors : {}); toast(context, errText(e), error: true); }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Widget _dateField(String key, String label, {bool past = false}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: InkWell(
          onTap: () => pickDate(key, past: past),
          child: InputDecorator(decoration: InputDecoration(labelText: tr(label), errorText: (errors[key] as List?)?.first, suffixIcon: const Icon(Icons.calendar_month)), child: Text(f[key] ?? '—')),
        ),
      );

  Widget _text(String key, String label, {int lines = 1, TextInputType? kb}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextFormField(initialValue: f[key]?.toString(), maxLines: lines, keyboardType: kb, decoration: InputDecoration(labelText: label, errorText: (errors[key] as List?)?.first), onChanged: (v) => f[key] = kb == TextInputType.number ? num.tryParse(v) : v),
      );

  /// Custom form field from the request type schema.
  Widget _custom(Map fd) {
    final k = fd['key'] as String;
    final s = context.read<AppState>();
    final label = (s.locale == 'en' && fd['label_en'] != null ? fd['label_en'] : fd['label']).toString() + (fd['required'] == true ? ' *' : '');
    switch (fd['type']) {
      case 'date':
        return _dateField(k, label);
      case 'number':
        return _text(k, label, kb: TextInputType.number);
      case 'textarea':
        return _text(k, label, lines: 3);
      case 'bool':
        return SwitchListTile(title: Text(label), value: f[k] == true, onChanged: (v) => setState(() => f[k] = v));
      case 'select':
      case 'lookup':
        final opts = fd['type'] == 'select' ? List<String>.from(fd['options'] ?? []) : ((s.meta?['lookups']?[fd['group']] as List?) ?? []).map((o) => o['name'] as String).toList();
        return Padding(padding: const EdgeInsets.only(bottom: 12), child: DropdownButtonFormField<String>(initialValue: f[k] as String?, decoration: InputDecoration(labelText: label, errorText: (errors[k] as List?)?.first), items: opts.map((o) => DropdownMenuItem(value: o, child: Text(o))).toList(), onChanged: (v) => setState(() => f[k] = v)));
      default:
        return _text(k, label);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.read<AppState>();
    final meta = s.meta;
    final leaveTypes = (meta?['leave_types'] as List?) ?? [];
    final letterKinds = ((meta?['lookups']?['letter_kinds']) as List?) ?? [];
    return Scaffold(
      appBar: AppBar(title: Text(NewRequestScreen.titles(s)[widget.type] ?? tr('طلب جديد'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        if (handler == 'leave') ...[
          DropdownButtonFormField<String>(
            initialValue: f['leave_type'],
            decoration: InputDecoration(labelText: tr('النوع')),
            items: [for (final lt in leaveTypes) DropdownMenuItem(value: lt['code'] as String, child: Text('${lt['name']}${balances.any((b) => b['type'] == lt['code']) ? ' — ${balances.firstWhere((b) => b['type'] == lt['code'])['available']}' : ''}'))],
            onChanged: (v) => setState(() => f['leave_type'] = v),
          ),
          const SizedBox(height: 12),
          _dateField('from', 'من'),
          _dateField('to', 'إلى'),
          TextField(controller: reason, maxLines: 3, decoration: InputDecoration(labelText: tr('تسليم المهام'))),
        ],
        if (handler == 'correction') ...[
          _dateField('date', 'اليوم', past: true),
          DropdownButtonFormField<String>(initialValue: f['kind'], decoration: InputDecoration(labelText: tr('النوع')), items: ((meta?['correction_kinds'] as List?) ?? []).map((k) => DropdownMenuItem(value: k as String, child: Text(k))).toList(), onChanged: (v) => setState(() => f['kind'] = v)),
          const SizedBox(height: 12),
          InkWell(
            onTap: () async {
              final t = await showTimePicker(context: context, initialTime: const TimeOfDay(hour: 17, minute: 0));
              if (t != null) setState(() => f['time'] = '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}');
            },
            child: InputDecorator(decoration: InputDecoration(labelText: tr('الوقت'), suffixIcon: const Icon(Icons.schedule)), child: Text(f['time'] ?? '—')),
          ),
          const SizedBox(height: 12),
          TextField(controller: reason, maxLines: 3, decoration: InputDecoration(labelText: tr('السبب'), errorText: (errors['reason'] as List?)?.first)),
        ],
        if (handler == 'advance') ...[
          TextField(controller: text2, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('المبلغ'), errorText: (errors['amount'] as List?)?.first)),
          const SizedBox(height: 12),
          DropdownButtonFormField<int>(initialValue: f['installments'] ?? 6, decoration: InputDecoration(labelText: tr('عدد الأقساط')), items: [for (var i = 1; i <= 12; i++) DropdownMenuItem(value: i, child: Text('$i'))], onChanged: (v) => f['installments'] = v),
          const SizedBox(height: 12),
          TextField(controller: reason, decoration: InputDecoration(labelText: tr('الغرض'))),
        ],
        if (handler == 'letter') ...[
          DropdownButtonFormField<String>(initialValue: f['kind'], decoration: InputDecoration(labelText: tr('النوع')), items: letterKinds.map((k) => DropdownMenuItem(value: k['code'] as String, child: Text((s.locale == 'en' && k['name_en'] != null ? k['name_en'] : k['name']) as String))).toList(), onChanged: (v) => setState(() => f['kind'] = v)),
          const SizedBox(height: 12),
          TextField(controller: reason, decoration: InputDecoration(labelText: tr('الجهة الموجّه إليها'))),
        ],
        if (handler == 'trip') ...[
          _text('destination', tr('الوجهة')),
          TextField(controller: reason, maxLines: 2, decoration: InputDecoration(labelText: tr('الغرض'))),
          const SizedBox(height: 12),
          _dateField('from', 'من'), _dateField('to', 'إلى'),
          TextField(controller: text2, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('التكلفة التقديرية'))),
          const SizedBox(height: 12),
          _text('advance', tr('عهدة مالية مطلوبة'), kb: TextInputType.number),
        ],
        if (handler == 'resignation') ...[
          _dateField('requested_last', 'التاريخ المطلوب لآخر يوم عمل'),
          TextField(controller: reason, maxLines: 3, decoration: InputDecoration(labelText: tr('السبب'))),
        ],
        if (handler == null) ...[for (final fd in ((def?['form'] as List?) ?? [])) _custom(fd as Map)],
        const SizedBox(height: 20),
        SizedBox(height: 48, child: FilledButton(onPressed: busy ? null : submit, child: busy ? const CircularProgressIndicator() : Text(tr('إرسال')))),
      ]),
    );
  }
}
