<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Access;
use App\Services\AssistantTools;
use App\Services\Audit;
use App\Services\LeaveService;
use Illuminate\Http\Request;

/**
 * The in-platform assistant (owner's notes): answers "how do I…" from the platform guide and the policy, and answers
 * questions about data — own balances, team attendance, reports — strictly within the signed-in user's permissions.
 * With ANTHROPIC_API_KEY set it uses Claude with read-only tools; without it, it still answers the common questions
 * from the same tools and the guide.
 */
class AssistantController extends Controller
{
    private const MODEL = 'claude-opus-5-5';

    public function status()
    {
        return ['smart' => (bool) config('services.anthropic.key'), 'suggestions' => [
            'كم رصيد إجازتي؟', 'أين وصلت طلباتي؟', 'كيف أقدّم طلب سلفة؟', 'ما الطلبات التي تنتظر اعتمادي؟', 'تقرير الحضور لهذا الشهر', 'كيف أصحّح بصمة نسيتها؟',
        ]];
    }

    public function ask(Request $request)
    {
        $d = $request->validate(['message' => 'required|string|max:2000', 'history' => 'nullable|array|max:12', 'history.*.role' => 'in:user,assistant', 'history.*.text' => 'string|max:4000']);
        Audit::log('assistant', 0, 'ask', null, ['chars' => mb_strlen($d['message'])]);

        if (! config('services.anthropic.key')) {
            return $this->basic($d['message']);
        }
        try {
            return $this->claude($d['message'], $d['history'] ?? []);
        } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
            report($e);

            return $this->basic($d['message'], 'تعذر الوصول إلى المساعد الذكي الآن — هذه إجابة مختصرة من بيانات المنصة.');
        } catch (\Throwable $e) {
            report($e);

            return $this->basic($d['message'], 'تعذر الوصول إلى المساعد الذكي الآن — هذه إجابة مختصرة من بيانات المنصة.');
        }
    }

    private function systemPrompt(): string
    {
        $u = auth()->user();
        $e = $u->employee;
        $acc = Access::for($u);

        return "أنت مساعد منصة HC لإدارة رأس المال البشري في مجموعة وادي الأثر المالية. تجيب بالعربية بإيجاز ووضوح، بأرقام لاتينية، وبالتواريخ يوم/شهر/سنة، بلا رموز تعبيرية.\n"
            ."- لأسئلة «كيف أفعل كذا» أو «أين أجد كذا»: اعتمد على الدليل أدناه، واذكر اسم الشاشة ومسارها بين قوسين، والخطوات مرقّمة.\n"
            ."- لأسئلة البيانات استخدم الأدوات؛ فهي تعيد فقط ما يحق لهذا المستخدم رؤيته. لا تخمّن أرقامًا ولا تذكر بيانات لم تُرجعها أداة.\n"
            ."- إذا أعادت أداة أن الأمر خارج الصلاحية، قل ذلك بلطف ولا تحاول الالتفاف عليه.\n"
            ."- عند عرض تقرير: لخّص النتيجة، واعرض أهم الصفوف في جدول Markdown قصير، وأعطِ رابط فتح التقرير الكامل للطباعة بهوية الشركة.\n"
            ."- أعطِ الروابط بصيغة Markdown بمسار يبدأ بـ / مثل [الإجازات](/leaves).\n"
            ."- لا تنفّذ أي إجراء (لا تعتمد ولا ترسل ولا تعدّل)؛ دلّ المستخدم على مكان تنفيذه.\n\n"
            .'المستخدم: '.$u->name.' — الدور: '.(\App\Models\User::ROLES[$u->roles[0] ?? 'emp'] ?? '').($e ? ' — الشركة: '.($e->company?->name ?? '—') : '').' — نطاق الاطلاع: '.count($acc->companyIds()).' شركة. تاريخ اليوم: '.now()->format('d/m/Y').".\n\n"
            .file_get_contents(resource_path('assistant/guide.md'));
    }

    private function claude(string $message, array $history): array
    {
        $client = new \Anthropic\Client(apiKey: config('services.anthropic.key'));
        // Earlier turns are passed as plain context inside this single user turn (no assistant turns are replayed).
        $ctx = collect($history)->map(fn ($h) => ($h['role'] === 'user' ? 'المستخدم: ' : 'المساعد: ').mb_substr($h['text'], 0, 1500))->implode("\n");
        $messages = [['role' => 'user', 'content' => ($ctx ? "سياق المحادثة السابقة:\n$ctx\n\nالسؤال الحالي:\n" : '').$message]];
        $tools = AssistantTools::definitions();
        $used = [];
        for ($i = 0; $i < 6; $i++) {
            $res = $client->beta->messages->create(
                model: self::MODEL,
                maxTokens: 16000,
                system: [['type' => 'text', 'text' => $this->systemPrompt(), 'cacheControl' => ['type' => 'ephemeral']]],
                messages: $messages,
                tools: $tools,
                outputConfig: ['effort' => 'medium'],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
            if ($res->stopReason === 'refusal') {
                return ['answer' => 'لا أستطيع المساعدة في هذا الطلب.', 'smart' => true];
            }
            if ($res->stopReason !== 'tool_use') {
                $text = collect($res->content)->filter(fn ($b) => $b->type === 'text')->map(fn ($b) => $b->text)->implode("\n");

                return ['answer' => trim($text) ?: 'لم أجد إجابة مناسبة — جرّب صياغة أخرى.', 'smart' => true, 'tools' => $used];
            }
            $results = [];
            foreach ($res->content as $block) {
                if ($block->type !== 'tool_use') {
                    continue;
                }
                $used[] = $block->name;
                try {
                    $out = AssistantTools::run($block->name, is_array($block->input) ? $block->input : (array) $block->input);
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => json_encode($out, JSON_UNESCAPED_UNICODE)];
                } catch (\Throwable $e) {
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => 'تعذر جلب البيانات: '.$e->getMessage(), 'isError' => true];
                }
            }
            $messages[] = ['role' => 'assistant', 'content' => $res->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return ['answer' => 'السؤال يحتاج خطوات كثيرة — جرّب تقسيمه إلى أسئلة أصغر.', 'smart' => true];
    }

    /** Without the smart model: the common questions answered from the same tools, and "how do I" from the guide. */
    private function basic(string $q, ?string $note = null): array
    {
        $has = fn (string ...$w) => collect($w)->contains(fn ($x) => str_contains($q, $x));
        $howTo = $has('كيف', 'أين', 'وين', 'طريقة', 'خطوات');
        $answer = null;
        if (! $howTo && $has('رصيد', 'إجازت', 'اجازت')) {
            $o = AssistantTools::run('my_overview', []);
            $answer = "أرصدة إجازاتك الآن:\n".collect($o['leave_balances'] ?? [])->map(fn ($b) => "- {$b['type']}: {$b['available']} ".($b['unit'] ?? 'يوم'))->implode("\n")."\n\nالتفاصيل في [الإجازات](/leaves).";
        } elseif (! $howTo && $has('طلباتي', 'طلبي', 'وصل طلب')) {
            $o = AssistantTools::run('my_overview', []);
            $r = $o['open_requests'] ?? [];
            $answer = $r ? "طلباتك المفتوحة:\n".collect($r)->map(fn ($x) => "- {$x['type']}: {$x['summary']} — بانتظار {$x['waiting_for']}")->implode("\n")."\n\n[طلباتي](/requests)" : 'لا توجد طلبات مفتوحة لك. [طلباتي](/requests)';
        } elseif (! $howTo && $has('تنتظر اعتماد', 'بانتظار اعتماد', 'موافقاتي', 'اعتماداتي')) {
            $items = AssistantTools::run('pending_approvals', [])['items'];
            $answer = $items ? 'بانتظار اعتمادك '.count($items)." طلبًا:\n".collect($items)->take(10)->map(fn ($x) => "- {$x['type']} — {$x['employee']}: {$x['summary']} ({$x['waiting_working_days']} يوم عمل)")->implode("\n")."\n\n[صندوق الموافقات](/approvals)" : 'لا طلبات بانتظار اعتمادك. [صندوق الموافقات](/approvals)';
        } elseif (! $howTo && $has('حضوري', 'تأخير', 'غياب')) {
            $a = AssistantTools::run('my_overview', [])['attendance_this_month'] ?? [];
            $answer = 'حضورك هذا الشهر: أيام العمل '.($a['required'] ?? 0).'، حضور '.($a['present'] ?? 0).'، تأخير '.($a['late'] ?? 0).'، غياب '.($a['absent'] ?? 0).".\n\n[الحضور والانصراف](/attendance)";
        } elseif (! $howTo && $has('عهد')) {
            $c = AssistantTools::run('my_overview', [])['custody'] ?? [];
            $answer = $c ? "العهد المسجلة عليك:\n".collect($c)->map(fn ($x) => "- {$x['item']} ({$x['type']}) — {$x['status']}")->implode("\n")."\n\n[العهد](/custody)" : 'لا توجد عهد مسجلة عليك. [العهد](/custody)';
        }
        if (! $answer) { // "how do I": the guide paragraphs that share the most words with the question
            $words = collect(preg_split('/[\s،,.؟?]+/u', $q))->map(fn ($w) => preg_replace('/^(ال|و|ب|ل)/u', '', $w))->filter(fn ($w) => mb_strlen($w) >= 3);
            $lines = collect(preg_split('/\n/u', file_get_contents(resource_path('assistant/guide.md'))))->filter(fn ($l) => str_starts_with(trim($l), '-'));
            $best = $lines->map(fn ($l) => ['l' => trim($l), 's' => $words->filter(fn ($w) => str_contains($l, $w))->count()])->filter(fn ($x) => $x['s'] > 0)->sortByDesc('s')->take(3);
            $answer = $best->isNotEmpty() ? $best->pluck('l')->implode("\n")."\n\nولشرح أي حقل اضغط «؟» بجانبه، أو افتح [دليل الاستخدام](/guide)."
                : 'لم أفهم السؤال بدقة. جرّب: «كم رصيد إجازتي؟» أو «كيف أقدّم طلب سلفة؟»، أو افتح [دليل الاستخدام](/guide).';
        }

        return ['answer' => ($note ? $note."\n\n" : '').$answer, 'smart' => false];
    }
}
