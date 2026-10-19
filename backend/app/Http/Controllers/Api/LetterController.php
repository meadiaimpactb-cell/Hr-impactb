<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Letter;
use App\Models\LetterTemplate;
use App\Models\Lookup;
use App\Models\SignedDoc;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Official letters from editable templates (placeholders), numbered, optionally e-signed; printable from the Vue print view. */
class LetterController extends Controller
{
    public const PLACEHOLDERS = ['employee', 'emp_no', 'company', 'company_en', 'job', 'hire_date', 'gross', 'to', 'date', 'hijri', 'last_day', 'nationality', 'national_id'];

    public static function nextNo(string $prefix): string
    {
        return DB::transaction(function () use ($prefix) {
            $row = DB::table('document_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
            $n = $row ? $row->next : 1;
            DB::table('document_sequences')->updateOrInsert(['prefix' => $prefix], ['next' => $n + 1]);

            return sprintf('%s-%s-%04d', $prefix, now()->format('Y'), $n);
        });
    }

    /** Resolve kind by code or (legacy) display name. */
    public static function kindCode(string $kind): string
    {
        foreach (Lookup::grouped()['letter_kinds'] ?? [] as $k) {
            if ($k['code'] === $kind || $k['name'] === $kind) {
                return $k['code'];
            }
        }

        return $kind;
    }

    public static function render(string $text, Employee $e, ?string $to, string $lang): string
    {
        $e->loadMissing('company', 'jobTitle');
        $hijri = self::hijri(now());
        $vals = ['employee' => $e->name, 'emp_no' => $e->emp_no, 'company' => $lang === 'en' ? ($e->company?->name_en ?: $e->company?->name) : $e->company?->name, 'company_en' => $e->company?->name_en,
            'job' => $lang === 'en' ? ($e->jobTitle?->name_en ?: $e->jobTitle?->name) : $e->jobTitle?->name, 'hire_date' => $e->hire_date?->toDateString(), 'gross' => number_format($e->monthlyGross(), 2),
            'to' => $to ?: ($lang === 'en' ? 'whom it may concern' : 'من يهمه الأمر'), 'date' => now()->toDateString(), 'hijri' => $hijri, 'last_day' => $e->last_working_day?->toDateString() ?? now()->toDateString(),
            'nationality' => $e->nationality, 'national_id' => $e->national_id];

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($vals[$m[1]] ?? ''), $text);
    }

    /** Umm al-Qura-approximate Hijri date (tabular calendar) for letter headers. */
    public static function hijri(\DateTimeInterface $d): string
    {
        $jd = gregoriantojd((int) $d->format('m'), (int) $d->format('d'), (int) $d->format('Y'));
        $l = $jd - 1948440 + 10632;
        $n = intdiv($l - 1, 10631);
        $l = $l - 10631 * $n + 354;
        $j = intdiv(10985 - $l, 5316) * intdiv(50 * $l, 17719) + intdiv($l, 5670) * intdiv(43 * $l, 15238);
        $l = $l - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50) - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;
        $m = intdiv(24 * $l, 709);
        $day = $l - intdiv(709 * $m, 24);
        $year = 30 * $n + $j - 30;
        $months = ['محرم', 'صفر', 'ربيع الأول', 'ربيع الآخر', 'جمادى الأولى', 'جمادى الآخرة', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذو القعدة', 'ذو الحجة'];

        return "$day {$months[$m - 1]} {$year}هـ";
    }

    // ---------- templates ----------
    public function templates()
    {
        return ['templates' => LetterTemplate::with('company:id,name')->orderBy('company_id')->orderBy('kind')->orderBy('lang')->get(), 'kinds' => Lookup::grouped()['letter_kinds'] ?? [], 'placeholders' => self::PLACEHOLDERS];
    }

    public function saveTemplate(Request $request, ?LetterTemplate $template = null)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);
        $d = $request->validate(['kind' => ['required', Rule::in(array_column(Lookup::grouped()['letter_kinds'] ?? [], 'code'))], 'lang' => 'required|in:ar,en', 'title' => 'required|string|max:160',
            'body' => 'required|string|max:8000', 'includes_salary' => 'boolean', 'active' => 'boolean', 'company_id' => 'nullable|exists:companies,id']);
        $before = $template?->toArray();
        $template = $template ? tap($template)->update($d + ['version' => $template->version + 1]) : LetterTemplate::updateOrCreate(['kind' => $d['kind'], 'lang' => $d['lang'], 'company_id' => $d['company_id'] ?? null], $d);
        Audit::log('letter_templates', $template->id, $before ? 'update' : 'create', $before, $template->toArray());

        return $template;
    }

    // ---------- letters ----------
    public function index(Request $request)
    {
        abort_unless(Access::for()->canAny('EMP', 'view'), 403);
        $ids = Access::for()->employees()->pluck('id');

        return Letter::with('employee:id,name,emp_no', 'company:id,name', 'issuer:id,name')->whereIn('employee_id', $ids)->orderByDesc('id')->limit(300)->get()->map(fn ($l) => $this->row($l));
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return Letter::with('company:id,name')->where('employee_id', $e->id)->orderByDesc('id')->get()->map(fn ($l) => $this->row($l));
    }

    public function show(Request $request, Letter $letter)
    {
        $u = $request->user();
        $own = $letter->employee_id === $u->employee_id;
        abort_unless($own || Access::for()->canSeeEmployee($letter->employee), 403);
        abort_if(! $own && $letter->includes_salary && ! Access::for()->canSeeSalary($letter->employee), 403, 'خطاب يتضمن الراتب — يتطلب صلاحية الاطلاع على الرواتب');
        $letter->load('employee.company', 'employee.jobTitle', 'company', 'issuer:id,name', 'signedDoc.signer:id,name');

        return array_merge($this->row($letter), ['purpose' => $letter->purpose, 'justification' => $letter->justification, 'employee' => $letter->employee->only('id', 'name', 'emp_no', 'nationality', 'national_id'), 'company' => $letter->company->only('id', 'name', 'name_en', 'legal_no', 'logo_url', 'color'),
            'job' => $letter->employee->jobTitle?->only('name', 'name_en'), 'hijri' => self::hijri($letter->issued_at), 'signature' => $letter->signedDoc?->signature, 'signer' => $letter->signedDoc?->signer?->name,
            'can_sign' => ! $letter->signed_doc_id && $u->isHr() && (bool) $u->signature]);
    }

    /** Issue a letter (HR directly, or from an approved request). Salary letters need PAY view. */
    public static function issue(Employee $e, string $kind, ?string $to, string $lang, ?int $by, ?int $requestId = null, ?array $extra = null): Letter
    {
        $code = self::kindCode($kind);
        // §114: the company's own template (its identity) first, then the group template
        $q = fn () => LetterTemplate::where('kind', $code)->where('active', true);
        $tpl = $q()->where('company_id', $e->company_id)->where('lang', $lang)->first() ?? $q()->whereNull('company_id')->where('lang', $lang)->first() ?? $q()->where('company_id', $e->company_id)->first() ?? $q()->whereNull('company_id')->first();
        $title = $tpl ? self::render($tpl->title, $e, $to, $lang) : Lookup::name('letter_kinds', $code);
        $body = $tpl ? self::render($tpl->body, $e, $to, $lang) : '';
        $letter = Letter::create(['employee_id' => $e->id, 'company_id' => $e->company_id, 'kind' => $code, 'to' => $to, 'lang' => $lang, 'no' => self::nextNo('LTR'), 'title' => $title, 'body' => $body,
            'purpose' => $extra['purpose'] ?? null, 'justification' => $extra['justification'] ?? null,
            'includes_salary' => (bool) ($tpl?->includes_salary), 'issued_at' => now()->toDateString(), 'issued_by' => $by, 'request_id' => $requestId, 'hash' => hash('sha256', $title."\n".$body)]);
        Audit::log('letters', $letter->id, 'issue', null, ['kind' => $code, 'no' => $letter->no]);
        Audit::notify(User::where('employee_id', $e->id)->value('id'), 'صدر خطابك الرسمي', Lookup::name('letter_kinds', $code).' — '.$letter->no, 'ok', ['screen' => 'letters']);

        return $letter;
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'kind' => ['required', Rule::in(array_column(Lookup::grouped()['letter_kinds'] ?? [], 'code'))], 'to' => 'nullable|string|max:160', 'lang' => 'in:ar,en',
            'purpose' => 'nullable|string|max:500', 'justification' => 'nullable|string|max:1000']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless($u->isHr() && Access::for()->can('EMP', 'edit', ['employee' => $e]), 403);
        $tpl = LetterTemplate::where('kind', $d['kind'])->first();
        abort_if($tpl?->includes_salary && ! Access::for()->canSeeSalary($e), 403, 'خطاب الراتب يتطلب صلاحية الاطلاع على الرواتب');

        return response()->json($this->row(self::issue($e, $d['kind'], $d['to'] ?? null, $d['lang'] ?? 'ar', $u->id, null, ['purpose' => $d['purpose'] ?? null, 'justification' => $d['justification'] ?? null])->load('employee:id,name,emp_no', 'company:id,name')), 201);
    }

    // ---------- e-signature ----------
    public function saveSignature(Request $request)
    {
        $d = $request->validate(['signature' => 'required|string|max:200000|starts_with:data:image/png;base64,']);
        $request->user()->update(['signature' => $d['signature']]);

        return ['ok' => true];
    }

    public function mySignature(Request $request)
    {
        return ['signature' => $request->user()->signature];
    }

    /** Sign a letter with the current user's stored signature; the hash freezes the text. */
    public function sign(Request $request, Letter $letter)
    {
        $u = $request->user();
        abort_unless($u->isHr() && Access::for()->can('EMP', 'edit', ['employee' => $letter->employee]), 403);
        abort_unless($u->signature, 422, 'احفظ توقيعك أولًا من ملفك');
        abort_if($letter->signed_doc_id, 422, 'الخطاب موقَّع مسبقًا');
        $doc = SignedDoc::create(['type' => 'letter', 'ref_id' => $letter->id, 'title' => $letter->title.' '.$letter->no, 'hash' => $letter->hash, 'signed_by' => $u->id, 'employee_id' => $letter->employee_id,
            'signature' => $u->signature, 'device' => mb_substr($request->userAgent() ?? '', 0, 120), 'ip' => $request->ip()]);
        $letter->update(['signed_doc_id' => $doc->id]);
        Audit::log('letters', $letter->id, 'sign', null, ['doc' => $doc->id, 'hash' => $doc->hash]);

        return $this->show($request, $letter->fresh());
    }

    public function signedDocs(Request $request)
    {
        $u = $request->user();

        return SignedDoc::with('signer:id,name')->where(fn ($q) => $q->where('signed_by', $u->id)->orWhere('employee_id', $u->employee_id))->orderByDesc('id')->limit(100)->get()
            ->map(fn ($s) => $s->only('id', 'type', 'ref_id', 'title', 'hash', 'device', 'created_at') + ['signer' => $s->signer?->name]);
    }

    private function row(Letter $l): array
    {
        return $l->only('id', 'kind', 'to', 'lang', 'no', 'title', 'body', 'includes_salary', 'issued_at', 'request_id', 'hash', 'signed_doc_id') + [
            'kind_name' => Lookup::name('letter_kinds', $l->kind), 'employee' => $l->relationLoaded('employee') ? $l->employee?->only('id', 'name', 'emp_no') : null,
            'company' => $l->relationLoaded('company') ? $l->company?->name : null, 'issued_by' => $l->relationLoaded('issuer') ? $l->issuer?->name : null, 'signed' => (bool) $l->signed_doc_id];
    }
}
