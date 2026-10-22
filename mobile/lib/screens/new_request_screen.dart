import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
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
  String? attachment; // local path of the supporting document (photo of a medical report, invoices…)
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
    if (handler == 'trip') { f['scope'] = 'domestic'; f['travel_class'] = 'economy'; f['outside_city'] = true; }
  }

  static const _tripCosts = {'cost_flight': 'الطيران', 'cost_lodging': 'السكن', 'cost_allowance': 'البدل اليومي', 'cost_transport': 'التنقل', 'cost_fees': 'الرسوم'};
  num get _tripTotal => _tripCosts.keys.fold<num>(0, (a, k) => a + ((f[k] as num?) ?? 0));
  int get _tripDays {
    final a = DateTime.tryParse(f['from'] ?? ''), b = DateTime.tryParse(f['to'] ?? '');
    return a == null || b == null || b.isBefore(a) ? 0 : b.difference(a).inDays + 1;
  }

  String _d(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> pickDate(String key, {bool past = false}) async {
    final now = DateTime.now();
    final d = await showDatePicker(context: context, initialDate: DateTime.tryParse(f[key] ?? '') ?? now, firstDate: now.subtract(const Duration(days: 60)), lastDate: past ? now : now.add(const Duration(days: 365)));
    if (d != null) setState(() => f[key] = _d(d));
  }

  Future<void> submit() async {
    setState(() { busy = true; errors = {}; });
    final body = {'type': widget.type, ...f};
    if (handler == 'leave') body['handover'] = reason.text;
    if (handler == 'correction') body['reason'] = reason.text;
    if (handler == 'advance') { body['purpose'] = reason.text; body['amount'] = num.tryParse(text2.text); }
    if (handler == 'letter') body['to'] = reason.text;
    if (handler == 'trip') {
      body['purpose'] = reason.text;
      body['estimate'] = _tripTotal > 0 ? _tripTotal : (num.tryParse(text2.text) ?? 0);
      if (body['scope'] == 'intl') body.remove('outside_city');
    }
    if (handler == 'resignation') body['reason'] = reason.text;
    try {
      if (attachment != null) {
        await Api.postMultipart('/requests', {
          for (final e in body.entries)
            if (e.value != null) e.key: e.value is bool ? (e.value ? '1' : '0') : e.value.toString(),
        }, files: {attachment!: 'attachment'});
      } else {
        await Api.post('/requests', body);
      }
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
        return Padding(padding: const EdgeInsets.only(bottom: 12), child: SearchPicker<String>(label: label, value: f[k] as String?, errorText: (errors[k] as List?)?.first, items: [for (final o in opts) (o, o)], onChanged: (v) => setState(() => f[k] = v)));
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
          SearchPicker<String>(
            label: tr('النوع'),
            value: f['leave_type'],
            items: [for (final lt in leaveTypes) (lt['code'] as String, '${lt['name']}${balances.any((b) => b['type'] == lt['code']) ? ' — ${balances.firstWhere((b) => b['type'] == lt['code'])['available']}' : ''}')],
            onChanged: (v) => setState(() => f['leave_type'] = v),
          ),
          const SizedBox(height: 12),
          _dateField('from', 'من'),
          _dateField('to', 'إلى'),
          TextField(controller: reason, maxLines: 3, decoration: InputDecoration(labelText: tr('تسليم المهام'))),
        ],
        if (handler == 'correction') ...[
          _dateField('date', 'اليوم', past: true),
          SearchPicker<String>(label: tr('النوع'), value: f['kind'], items: [for (final k in ((meta?['correction_kinds'] as List?) ?? [])) (k as String, k)], onChanged: (v) => setState(() => f['kind'] = v)),
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
          SearchPicker<int>(label: tr('عدد الأقساط'), value: f['installments'] ?? 6, items: [for (var i = 1; i <= 12; i++) (i, '$i')], onChanged: (v) => setState(() => f['installments'] = v)),
          const SizedBox(height: 12),
          TextField(controller: reason, decoration: InputDecoration(labelText: tr('الغرض'))),
        ],
        if (handler == 'letter') ...[
          SearchPicker<String>(label: tr('النوع'), value: f['kind'], items: [for (final k in letterKinds) (k['code'] as String, (s.locale == 'en' && k['name_en'] != null ? k['name_en'] : k['name']) as String)], onChanged: (v) => setState(() => f['kind'] = v)),
          const SizedBox(height: 12),
          TextField(controller: reason, decoration: InputDecoration(labelText: tr('الجهة الموجّه إليها'))),
        ],
        if (handler == 'trip') ...[
          SearchPicker<String>(label: tr('نوع الرحلة'), value: f['scope'], items: [('domestic', tr('داخل المملكة')), ('intl', tr('خارج المملكة'))], onChanged: (v) => setState(() => f['scope'] = v)),
          if (f['scope'] == 'domestic')
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('خارج مدينة العمل ولمسافة تزيد على 100 كيلومتر')), subtitle: (errors['outside_city'] as List?) != null ? Text((errors['outside_city'] as List).first, style: const TextStyle(color: Colors.red)) : null,
                value: f['outside_city'] == true, onChanged: (v) => setState(() => f['outside_city'] = v)),
          const SizedBox(height: 12),
          _text('destination', '${tr('الوجهة')} *'),
          TextField(controller: reason, maxLines: 2, decoration: InputDecoration(labelText: '${tr('الغرض')} *', errorText: (errors['purpose'] as List?)?.first)),
          const SizedBox(height: 12),
          _text('outputs', '${tr('المخرجات المتوقعة')} *', lines: 2),
          _dateField('from', 'من'), _dateField('to', 'إلى'),
          if (_tripDays > 0) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text('${tr('مدة الرحلة')}: $_tripDays ${tr('يوم')}', style: const TextStyle(color: Colors.black54))),
          if (_tripDays == 1) _text('hours', tr('مدة المهمة بالساعات (البدل اليومي لـ10 ساعات فأكثر)'), kb: TextInputType.number),
          SearchPicker<String>(label: tr('درجة السفر'), value: f['travel_class'], items: [('economy', tr('السياحية')), ('business', tr('رجال الأعمال (استثناء)')), ('first', tr('الأولى (استثناء)'))], onChanged: (v) => setState(() => f['travel_class'] = v)),
          const SizedBox(height: 12),
          if (f['travel_class'] != 'economy' || errors['exception_reason'] != null) _text('exception_reason', '${tr('مبرر الاستثناء')} *', lines: 2),
          Text(tr('التكلفة التقديرية'), style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          for (final c in _tripCosts.entries)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: TextFormField(keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr(c.value), isDense: true), onChanged: (v) => setState(() => f[c.key] = num.tryParse(v))),
            ),
          if (_tripTotal > 0)
            Padding(padding: const EdgeInsets.only(bottom: 12), child: Text('${tr('الإجمالي')}: $_tripTotal', style: const TextStyle(fontWeight: FontWeight.w700)))
          else ...[
            TextField(controller: text2, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: '${tr('الإجمالي')} *', errorText: (errors['estimate'] as List?)?.first)),
            const SizedBox(height: 12),
          ],
          _text('advance', tr('عهدة مالية مطلوبة'), kb: TextInputType.number),
        ],
        if (handler == 'resignation') ...[
          _dateField('requested_last', 'التاريخ المطلوب لآخر يوم عمل'),
          TextField(controller: reason, maxLines: 3, decoration: InputDecoration(labelText: tr('السبب'))),
        ],
        if (handler == null) ...[for (final fd in ((def?['form'] as List?) ?? [])) _custom(fd as Map)],
        const SizedBox(height: 8),
        // supporting document for every request type (medical report, invoices, trip documents…)
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(minimumSize: const Size(0, 44)),
          onPressed: () async {
            final x = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1800, imageQuality: 80);
            if (x != null) setState(() => attachment = x.path);
          },
          icon: Icon(attachment == null ? Icons.attach_file : Icons.check_circle, color: attachment == null ? null : Colors.green),
          label: Text(attachment == null ? tr('إرفاق مستند (صورة)') : tr('تم إرفاق المستند — اضغط للتغيير')),
        ),
        if ((errors['attachment'] as List?) != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text((errors['attachment'] as List).first, style: const TextStyle(color: Colors.red, fontSize: 12))),
        const SizedBox(height: 20),
        SizedBox(height: 48, child: FilledButton(onPressed: busy ? null : submit, child: busy ? const CircularProgressIndicator() : Text(tr('إرسال')))),
      ]),
    );
  }
}
