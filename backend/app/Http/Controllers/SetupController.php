<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Browser-based installer for shared hosting (no SSH): runs migrations, seeds reference data and creates the first
 * administrator. Enabled only while SETUP_TOKEN is set in .env; remove the token after installing.
 */
class SetupController extends Controller
{
    private function guard(string $token): void
    {
        $expected = (string) env('SETUP_TOKEN', '');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    }

    public function show(string $token)
    {
        $this->guard($token);

        return view('setup', ['state' => $this->state(), 'token' => $token, 'result' => null]);
    }

    public function run(Request $request, string $token)
    {
        $this->guard($token);
        $log = [];
        $password = null; // temporary passwords of the leadership accounts (fresh install only)
        try {
            DB::connection()->getPdo();
            Artisan::call('migrate', ['--force' => true]);
            $log[] = trim(Artisan::output()) ?: 'migrate: nothing to do';

            if (! Schema::hasTable('users') || User::count() === 0) {
                Artisan::call('db:seed', ['--class' => $request->boolean('demo') ? 'DatabaseSeeder' : 'ProductionSeeder', '--force' => true]);
                $log[] = trim(Artisan::output()) ?: 'seed: done';
                if (! $request->boolean('demo')) {
                    $password = [];
                    foreach (\Database\Seeders\AccountsSeeder::ACCOUNTS as $a) {
                        $u = User::where('login', $a['login'])->first();
                        if ($u) {
                            $u->forceFill(['password' => $a['password'], 'must_change_password' => true])->save(); // model cast hashes it
                            $password[] = ['name' => $u->name, 'login' => $u->login, 'role' => User::ROLES[$u->roles[0] ?? 'emp'] ?? '', 'password' => $a['password']];
                        }
                    }
                }
            } else {
                Artisan::call('db:seed', ['--class' => 'ConfigSeeder', '--force' => true]);
                $log[] = 'reference data refreshed';
            }
            try {
                Artisan::call('storage:link');
            } catch (\Throwable) {
            }
            foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear'] as $c) {
                try {
                    Artisan::call($c);
                } catch (\Throwable) {
                }
            }
            $ok = true;
        } catch (\Throwable $e) {
            $ok = false;
            $log[] = get_class($e).': '.$e->getMessage();
        }

        return view('setup', ['state' => $this->state(), 'token' => $token, 'result' => ['ok' => $ok, 'log' => $log, 'password' => $password]]);
    }

    private function state(): array
    {
        $db = null;
        try {
            DB::connection()->getPdo();
            $db = true;
        } catch (\Throwable $e) {
            $db = $e->getMessage();
        }

        return [
            'php' => PHP_VERSION,
            'php_ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'ext' => collect(['pdo_mysql', 'mbstring', 'openssl', 'ctype', 'fileinfo', 'tokenizer', 'xml', 'bcmath', 'gd', 'curl', 'zip'])->mapWithKeys(fn ($x) => [$x => extension_loaded($x)])->all(),
            'writable' => collect(['storage', 'storage/logs', 'storage/framework', 'bootstrap/cache'])->mapWithKeys(fn ($d) => [$d => is_writable(base_path($d))])->all(),
            'db' => $db,
            'installed' => $db === true && Schema::hasTable('users') && User::count() > 0,
            'users' => $db === true && Schema::hasTable('users') ? User::count() : 0,
            'env' => config('app.env'),
            'url' => config('app.url'),
            'debug' => config('app.debug'),
            'key' => (bool) config('app.key'),
        ];
    }
}
