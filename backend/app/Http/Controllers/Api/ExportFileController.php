<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExportFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Download center: files the user exported, kept for KEEP_DAYS so they can be downloaded again at any time. */
class ExportFileController extends Controller
{
    public const KEEP_DAYS = 90;

    public const CATEGORIES = ['reports' => 'التقارير', 'finance' => 'التقارير المالية', 'people' => 'الموظفون والحسابات', 'forms' => 'النماذج'];

    public function index(Request $request)
    {
        $uid = $request->user()->id;
        // housekeeping: files past the retention period are removed
        ExportFile::where('user_id', $uid)->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->get()->each(function ($f) {
            Storage::delete($f->path);
            $f->delete();
        });

        return ['files' => ExportFile::where('user_id', $uid)->latest('id')->limit(500)->get(), 'categories' => self::CATEGORIES, 'keep_days' => self::KEEP_DAYS];
    }

    public function store(Request $request)
    {
        $d = $request->validate(['file' => 'required|file|max:20480|mimes:xlsx,pdf,csv,zip', 'name' => 'required|string|max:200', 'kind' => 'nullable|string|max:120',
            'category' => 'nullable|in:'.implode(',', array_keys(self::CATEGORIES))]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $path = $file->storeAs('exports/'.$request->user()->id, now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.'.$ext);

        return ExportFile::create(['user_id' => $request->user()->id, 'name' => $d['name'], 'kind' => $d['kind'] ?: 'تصدير Excel', 'category' => $d['category'] ?? 'reports',
            'format' => $ext, 'path' => $path, 'size' => $file->getSize()]);
    }

    public function download(Request $request, ExportFile $file)
    {
        abort_unless($file->user_id === $request->user()->id, 403);
        abort_unless(Storage::exists($file->path), 404, 'الملف لم يعد متاحًا');

        return Storage::download($file->path, $file->name.'.'.$file->format);
    }

    public function destroy(Request $request, ExportFile $file)
    {
        abort_unless($file->user_id === $request->user()->id, 403);
        Storage::delete($file->path);
        $file->delete();

        return ['ok' => true];
    }
}
