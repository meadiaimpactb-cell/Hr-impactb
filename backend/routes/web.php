<?php

use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

$page = function (string $file) {
    return function () use ($file) {
        $path = public_path("static/$file");
        abort_unless(is_file($path), 404, 'Frontend not built: run `npm run build` in frontend/');

        return response()->file($path, ['Cache-Control' => 'no-cache']);
    };
};

// Public landing page (Vue entry `landing.html`: download the app, open the portal)
Route::get('/', $page('landing.html'))->name('landing');

// One-time installer for hosts without SSH: /setup/<SETUP_TOKEN from .env>
Route::get('/setup/{token}', [SetupController::class, 'show']);
Route::post('/setup/{token}', [SetupController::class, 'run']);

// Company logos (public, cacheable): uploaded from the Companies screen to storage/app/public/brand
Route::get('/brand/{file}', function (string $file) {
    abort_unless(preg_match('/^[A-Za-z0-9_.-]+\.(png|jpe?g|svg|webp)$/i', $file), 404);
    $path = storage_path('app/public/brand/'.$file);
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Cache-Control' => 'public, max-age=86400']);
})->where('file', '[A-Za-z0-9_.-]+');

// Old bookmarks
Route::redirect('/login', '/app/login');

// Vue portal (built by `npm run build` into public/static, router base /app/)
Route::get('/app/{any?}', $page('index.html'))->where('any', '.*');
