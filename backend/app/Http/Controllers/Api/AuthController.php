<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:120', 'password' => 'required|string', 'device' => 'nullable|string|max:120']);
        $key = 'login:'.mb_strtolower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages(['login' => 'محاولات كثيرة — حاول بعد '.RateLimiter::availableIn($key).' ثانية']);
        }
        $login = mb_strtolower(trim($data['login']));
        $user = User::where('active', true)->where(fn ($q) => $q->whereRaw('LOWER(login)=?', [$login])->orWhereRaw('LOWER(email)=?', [$login])
            ->orWhereHas('employee', fn ($e) => $e->whereRaw('LOWER(emp_no)=?', [$login])))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['login' => 'بيانات الدخول غير صحيحة']);
        }
        RateLimiter::clear($key);
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($data['device'] ?? ($request->header('X-Channel') === 'mobile' ? 'mobile' : 'web'))->plainTextToken;
        auth()->setUser($user);
        Audit::log('users', $user->id, 'login');

        return ['token' => $token, 'user' => $this->profile($user)];
    }

    public function me(Request $request)
    {
        return $this->profile($request->user());
    }

    // ---- invitations / password reset links (public)
    public function inviteCheck(Request $request)
    {
        $u = \App\Services\InviteService::find($request->validate(['token' => 'required|string|max:80'])['token']);
        abort_unless($u, 410, 'الرابط غير صالح أو منتهي');

        return ['name' => $u->name, 'login' => $u->login, 'email' => $u->email, 'expires_at' => $u->invite_expires_at];
    }

    public function inviteAccept(Request $request)
    {
        $d = $request->validate(['token' => 'required|string|max:80', 'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()->symbols()], 'device' => 'nullable|string|max:120']);
        $u = \App\Services\InviteService::find($d['token']);
        abort_unless($u, 410, 'الرابط غير صالح أو منتهي');
        \App\Services\InviteService::accept($u, $d['password']);
        $u->forceFill(['last_login_at' => now()])->save();
        $token = $u->createToken($d['device'] ?? 'web')->plainTextToken;
        auth()->setUser($u);

        return ['token' => $token, 'user' => $this->profile($u)];
    }

    /** Always answers 200 so account existence is never revealed. */
    public function forgot(Request $request)
    {
        $login = mb_strtolower(trim($request->validate(['login' => 'required|string|max:120'])['login']));
        $key = 'forgot:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'محاولات كثيرة — حاول بعد '.RateLimiter::availableIn($key).' ثانية']);
        }
        RateLimiter::hit($key, 600);
        $user = User::where('active', true)->whereNotNull('email')->where(fn ($q) => $q->whereRaw('LOWER(login)=?', [$login])->orWhereRaw('LOWER(email)=?', [$login])
            ->orWhereHas('employee', fn ($e) => $e->whereRaw('LOWER(emp_no)=?', [$login])))->first();
        if ($user) {
            \App\Services\InviteService::issue($user, true);
        }

        return ['ok' => true];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ['ok' => true];
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current' => 'nullable|string', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()->symbols()]]);
        $u = $request->user();
        if (! empty($data['current']) && ! Hash::check($data['current'], $u->password)) {
            throw ValidationException::withMessages(['current' => 'كلمة المرور الحالية غير صحيحة']);
        }
        $u->update(['password' => $data['password'], 'must_change_password' => false]);
        Audit::log('users', $u->id, 'changePassword');

        return ['ok' => true];
    }

    public function locale(Request $request)
    {
        $request->validate(['locale' => 'required|in:ar,en']);
        $request->user()->update(['locale' => $request->locale]);

        return ['ok' => true];
    }

    private function profile(User $u): array
    {
        $u->loadMissing('employee.company:id,name,name_en,code,logo_path,color,color2,tier_id,updated_at', 'employee.company.tier:id,code,name,color', 'employee.jobTitle:id,name,name_en');
        $acc = Access::for($u);
        $e = $u->employee;

        return [
            'id' => $u->id, 'name' => $u->name, 'login' => $u->login, 'email' => $u->email, 'roles' => $u->roles, 'photo_url' => $u->photo_url,
            'role_label' => User::ROLES[$u->roles[0] ?? 'emp'] ?? '', 'locale' => $u->locale, 'must_change_password' => $u->must_change_password,
            'company_ids' => $acc->companyIds(),
            'has_reports' => $e ? $e->reports()->exists() : false,
            'employee' => $e ? [
                'id' => $e->id, 'emp_no' => $e->emp_no, 'name' => $e->name, 'name_en' => $e->name_en,
                'company' => $e->company, 'job_title' => $e->jobTitle, 'punch_required' => $e->punch_required,
                'daily_note_required' => $e->daily_note_required, 'advances_enabled' => $e->advances_enabled, 'company_id' => $e->company_id,
                'manager' => $e->manager_id ? ['id' => $e->manager_id, 'name' => $e->manager?->name, 'user_id' => User::where('employee_id', $e->manager_id)->where('active', true)->value('id')] : null,
            ] : null,
        ];
    }
}
