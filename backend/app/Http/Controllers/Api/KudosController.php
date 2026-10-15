<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Peer kudos (motivation layer): anyone can thank a colleague; monthly leaderboard per company scope. */
class KudosController extends Controller
{
    public function store(Request $request)
    {
        abort_unless(Settings::get('peerKudosEnabled', null, true), 422, 'الإشادة بين الزملاء معطّلة');
        $d = $request->validate(['employee_id' => 'required|exists:employees,id', 'text' => 'required|string|max:300']);
        $u = $request->user();
        abort_if((int) $d['employee_id'] === $u->employee_id, 422, 'لا يمكنك الإشادة بنفسك');
        $period = now()->format('Y-m');
        abort_if(DB::table('kudos')->where('from_user_id', $u->id)->where('employee_id', $d['employee_id'])->where('period', $period)->exists(), 422, 'أشدت بهذا الزميل هذا الشهر مسبقًا');
        DB::table('kudos')->insert(['from_user_id' => $u->id, 'employee_id' => $d['employee_id'], 'text' => $d['text'], 'period' => $period, 'created_at' => now(), 'updated_at' => now()]);
        Audit::notify(User::where('employee_id', $d['employee_id'])->value('id'), 'إشادة من '.$u->name, $d['text'], 'ok', ['screen' => 'evaluation']);

        return ['ok' => true];
    }

    public function forEmployee(Request $request, Employee $employee)
    {
        abort_unless($employee->id === $request->user()->employee_id || Access::for()->canSeeEmployee($employee), 403);

        return DB::table('kudos')->join('users', 'users.id', '=', 'kudos.from_user_id')->where('kudos.employee_id', $employee->id)->orderByDesc('kudos.id')->limit(50)
            ->get(['kudos.id', 'kudos.text', 'kudos.period', 'kudos.created_at', 'users.name as from']);
    }

    public function leaderboard(Request $request)
    {
        $period = $request->input('period', now()->format('Y-m'));
        $ids = Access::for()->employees()->where('status', '!=', 'terminated')->pluck('id');
        $rows = DB::table('kudos')->join('employees', 'employees.id', '=', 'kudos.employee_id')->whereIn('kudos.employee_id', $ids)->where('kudos.period', $period)
            ->selectRaw('employee_id, employees.name, count(*) as n')->groupBy('employee_id', 'employees.name')->orderByDesc('n')->limit(10)->get();

        return ['period' => $period, 'rows' => $rows];
    }
}
