<?php

namespace App\Console\Commands;

use App\Models\CustodyItem;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Console\Command;

/**
 * Daily (§44): cash custody not settled by its deadline (custodySettleDays working days after the purpose ends,
 * at most custodySettleMaxDays from disbursement) is marked overdue, and the employee, the direct manager and
 * finance are notified — on the first day overdue and then weekly. While overdue the employee may not receive a
 * new custody or advance (CustodyItem::hasOverdueCash).
 */
class CustodyOverdue extends Command
{
    protected $signature = 'hc:custody-overdue {--dry : list without writing or notifying}';

    protected $description = 'Mark overdue cash-custody settlements and notify employee / manager / finance';

    public function handle(): int
    {
        if (! CustodyItem::hasDeadlineColumns()) {
            $this->warn('custody deadline columns missing — run the migrations');

            return self::FAILURE;
        }
        $marked = 0;
        $notified = 0;
        $items = CustodyItem::with('type:id,is_cash', 'employee:id,name,manager_id,company_id')->whereNotNull('employee_id')
            ->whereIn('status', CustodyItem::OPEN)->whereHas('type', fn ($q) => $q->where('is_cash', true))->get();
        foreach ($items as $i) {
            $due = $i->computeSettleDue();
            $overdue = $i->isOverdue();
            if ($this->option('dry')) {
                $this->line("#{$i->id} {$i->ident} due {$due} ".($overdue ? 'OVERDUE' : 'ok'));

                continue;
            }
            $dirty = false;
            if ($i->settle_due?->toDateString() !== $due) {
                $i->settle_due = $due;
                $dirty = true;
            }
            if (! $overdue) {
                if ($i->overdue_at) {
                    $i->overdue_at = null; // a claim is now with finance, or the deadline moved
                    $dirty = true;
                }
                if ($dirty) {
                    $i->saveQuietly();
                }

                continue;
            }
            if (! $i->overdue_at) {
                $i->overdue_at = now();
                $i->log('تأخر في تسوية العهدة النقدية', 'الموعد النهائي '.$due);
                $marked++;
                $dirty = true;
            }
            if (! $i->overdue_notified_at || $i->overdue_notified_at->lte(now()->subDays(7))) {
                $e = $i->employee ?? Employee::find($i->employee_id);
                $days = (int) \Illuminate\Support\Carbon::parse($due)->diffInDays(now()->startOfDay());
                $body = $e?->name.' — '.$i->ident.' · '.number_format($i->value, 2).' ر.س · الموعد '.\Illuminate\Support\Carbon::parse($due)->format('d/m/Y')." (متأخر {$days} يوم)";
                Audit::notify(User::where('employee_id', $i->employee_id)->value('id'), 'تنبيه كتابي: تأخرت في تسوية عهدتك النقدية',
                    $body.' — سوِّها بالفواتير في المنصة؛ لا تُصرف لك عهدة أو سلفة جديدة حتى التسوية (§44)', 'bad', ['screen' => 'custody']);
                $others = array_filter([$e?->manager_id ? User::where('employee_id', $e->manager_id)->value('id') : null, ...Access::usersWithRole('fin')]);
                Audit::notify(array_values(array_unique($others)), 'عهدة نقدية متأخرة التسوية', $body, 'warn', ['screen' => 'custody']);
                $i->overdue_notified_at = now();
                $notified++;
                $dirty = true;
            }
            if ($dirty) {
                $i->saveQuietly();
            }
        }
        $this->info("checked {$items->count()} · newly overdue {$marked} · notified {$notified}");

        return self::SUCCESS;
    }
}
