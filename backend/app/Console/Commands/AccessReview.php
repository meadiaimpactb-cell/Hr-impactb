<?php

namespace App\Console\Commands;

use App\Models\Delegation;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Console\Command;

/**
 * §116: periodic review of access rights. Lists what should no longer be there — active accounts of employees who left,
 * accounts unused for the review period, HR delegations past their end date, delegations still open after expiry — and
 * sends it to the group CEO and the HR manager. Nothing is changed automatically; the decision stays with them.
 */
class AccessReview extends Command
{
    protected $signature = 'hc:access-review {--force : run whatever the date}';

    protected $description = 'Periodic access-rights review (§116)';

    public function handle(): int
    {
        $every = max(30, (int) Settings::get('accessReviewDays', null, 90));
        if (! $this->option('force') && now()->dayOfYear % $every !== 0) {
            return self::SUCCESS;
        }
        $left = User::where('active', true)->whereIn('employee_id', Employee::where('status', 'terminated')->pluck('id'))->pluck('name')->all();
        $idle = User::where('active', true)->where(fn ($q) => $q->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays($every)))->pluck('name')->all();
        $expired = User::where('active', true)->get()->filter(fn ($u) => $u->hasRole('hr_staff') && ! empty($u->perms['valid_to']) && $u->perms['valid_to'] < now()->toDateString())->pluck('name')->all();
        $deleg = Delegation::where('status', '!=', 'revoked')->where('valid_to', '<', now()->toDateString())->count();
        $lines = array_filter([
            $left ? 'حسابات نشطة لموظفين انتهت خدمتهم: '.implode('، ', array_slice($left, 0, 15)) : null,
            $idle ? 'حسابات لم تُستخدم منذ '.$every.' يومًا: '.count($idle) : null,
            $expired ? 'تفويضات موارد بشرية منتهية ما زالت مسجلة: '.implode('، ', $expired) : null,
            $deleg ? "تفويضات اعتماد انتهت مدتها: $deleg" : null,
        ]);
        $body = $lines ? implode(' · ', $lines) : 'لا ملاحظات — الصلاحيات مطابقة';
        Audit::notify(array_unique([...Access::usersWithRole('super'), ...Access::usersWithRole('hr_mgr')]), 'المراجعة الدورية لصلاحيات الوصول (§116)', $body, $lines ? 'warn' : 'info', ['screen' => 'users']);
        Audit::log('users', 0, 'access_review', null, ['left' => count($left), 'idle' => count($idle), 'expired' => count($expired), 'delegations' => $deleg]);
        $this->info($body);

        return self::SUCCESS;
    }
}
