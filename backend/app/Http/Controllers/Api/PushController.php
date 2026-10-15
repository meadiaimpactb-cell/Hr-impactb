<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PushController extends Controller
{
    public function register(Request $request)
    {
        $d = $request->validate(['token' => 'required|string|max:300', 'platform' => 'nullable|in:android,ios,web', 'device' => 'nullable|string|max:120']);
        DB::table('device_tokens')->updateOrInsert(['token' => $d['token']], ['user_id' => $request->user()->id, 'platform' => $d['platform'] ?? 'android', 'device' => $d['device'] ?? null, 'last_seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]);

        return ['ok' => true];
    }

    public function unregister(Request $request)
    {
        $d = $request->validate(['token' => 'required|string|max:300']);
        DB::table('device_tokens')->where('token', $d['token'])->where('user_id', $request->user()->id)->delete();

        return ['ok' => true];
    }
}
