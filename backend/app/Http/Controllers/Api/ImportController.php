<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\ImportService;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->user()->hasRole('super', 'hr_mgr'), 403, 'الاستيراد صلاحية الرئيس التنفيذي للمجموعة ومدير الموارد البشرية');
    }

    public function templates(Request $request)
    {
        $this->admin($request);

        return collect(ImportService::templates())->map(fn ($t, $k) => ['key' => $k, 'name' => $t['name'], 'headers' => array_values($t['headers']), 'hint' => $t['hint'] ?? null])->values();
    }

    /** Empty CSV (UTF-8 BOM) with the template headers. */
    public function template(Request $request, string $key)
    {
        $this->admin($request);
        $t = ImportService::templates()[$key] ?? abort(404);
        $csv = "\xEF\xBB\xBF".implode(',', array_map(fn ($h) => '"'.str_replace('"', '""', $h).'"', array_values($t['headers'])))."\r\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"import-$key.csv\""]);
    }

    public function preview(Request $request)
    {
        $this->admin($request);
        $d = $request->validate(['template' => 'required|string', 'file' => 'required|file|max:5120']);
        $r = ImportService::validate($d['template'], file_get_contents($request->file('file')->getRealPath()));

        return $r['ok'] ? $r : response()->json($r, 422);
    }

    public function commit(Request $request)
    {
        $this->admin($request);
        $d = $request->validate(['template' => 'required|string', 'file' => 'required|file|max:5120']);
        $b = ImportService::commit($d['template'], file_get_contents($request->file('file')->getRealPath()), $request->file('file')->getClientOriginalName(), $request->user()->id);
        \App\Services\Access::flushCache();

        return response()->json($b, 201);
    }

    public function history(Request $request)
    {
        $this->admin($request);

        return ImportBatch::with('user:id,name')->orderByDesc('id')->limit(100)->get()->map(fn ($b) => $b->only('id', 'template', 'file_name', 'total', 'valid', 'invalid', 'errors', 'status', 'created_at') + ['by' => $b->user?->name, 'created_count' => count($b->created ?? [])]);
    }

    public function undo(Request $request, ImportBatch $batch)
    {
        $this->admin($request);

        return ['undone' => ImportService::undo($batch)];
    }
}
