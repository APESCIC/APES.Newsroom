<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessGhostContentImportJob;
use App\Models\ImportRun;
use App\Services\Audit\AuditLogger;
use App\Services\Import\GhostContentImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class GhostContentImportController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GhostContentImporter $importer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeStaff();

        $runs = ImportRun::query()
            ->where('type', 'ghost_content')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ImportRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'dry_run' => $run->dry_run,
                'source_checksum' => $run->source_checksum,
                'source_available' => is_string($run->source_path) && is_file($run->source_path),
                'report' => $run->report,
                'created_at' => $run->created_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Imports/GhostContent', [
            'runs' => $runs,
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorizeStaff();

        $validated = $request->validate([
            'json' => ['required', 'file', 'extensions:json', 'max:51200'],
            'media' => ['nullable', 'file', 'extensions:zip', 'max:102400'],
        ]);

        $uploaded = $validated['json'];
        $absoluteUploaded = $uploaded->getRealPath();
        if ($absoluteUploaded === false) {
            return back()->withErrors(['json' => 'Unable to read the uploaded file.']);
        }

        $payload = json_decode((string) file_get_contents($absoluteUploaded), true);
        if (! is_array($payload) || ! $this->importer->isGhostContentExport($payload)) {
            return back()->withErrors(['json' => 'File is not a Ghost content JSON export (expected a db export).']);
        }

        $stagingKey = (string) Str::uuid();
        $relativeDir = 'imports/ghost-content/'.$stagingKey;
        Storage::makeDirectory($relativeDir);

        $jsonRelative = $relativeDir.'/content.json';
        Storage::put($jsonRelative, file_get_contents($absoluteUploaded) ?: '');
        $jsonAbsolute = Storage::path($jsonRelative);

        if (isset($validated['media'])) {
            $this->extractMediaArchive($validated['media']->getRealPath() ?: '', Storage::path($relativeDir.'/media'));
        }

        $run = ImportRun::create([
            'type' => 'ghost_content',
            'status' => 'queued',
            'dry_run' => true,
            'source_path' => $jsonAbsolute,
            'source_checksum' => hash_file('sha256', $jsonAbsolute),
            'actor_id' => $request->user()->id,
            'started_at' => null,
        ]);

        $this->audit->record($request->user(), 'import.ghost_content.dry_run', $run, [
            'checksum' => $run->source_checksum,
            'path' => $jsonRelative,
            'has_media' => isset($validated['media']),
        ], $request);

        ProcessGhostContentImportJob::dispatch($run->id, true, false);

        return back()->with('status', 'Dry-run queued.');
    }

    public function confirm(Request $request, ImportRun $run): RedirectResponse
    {
        $this->authorizeStaff();
        abort_unless($run->type === 'ghost_content', 404);

        if (! $run->dry_run || $run->status !== 'completed') {
            return back()->withErrors(['confirm' => 'Confirm requires a completed dry-run.']);
        }

        if (! is_string($run->source_path) || ! is_file($run->source_path)) {
            return back()->withErrors(['confirm' => 'Upload is no longer available. Re-upload and dry-run again.']);
        }

        $persist = ImportRun::create([
            'type' => 'ghost_content',
            'status' => 'queued',
            'dry_run' => false,
            'source_path' => $run->source_path,
            'source_checksum' => $run->source_checksum,
            'actor_id' => $request->user()->id,
            'started_at' => null,
        ]);

        $this->audit->record($request->user(), 'import.ghost_content.import', $persist, [
            'checksum' => $persist->source_checksum,
            'from_dry_run_id' => $run->id,
        ], $request);

        ProcessGhostContentImportJob::dispatch($persist->id, false, true);

        return back()->with('status', 'Import queued.');
    }

    public function report(Request $request, ImportRun $run): StreamedResponse|RedirectResponse
    {
        $this->authorizeStaff();
        abort_unless($run->type === 'ghost_content', 404);

        $payload = json_encode($run->report ?? [], JSON_PRETTY_PRINT);

        return response()->streamDownload(function () use ($payload) {
            echo $payload;
        }, "ghost-content-import-{$run->id}.json", [
            'Content-Type' => 'application/json',
        ]);
    }

    private function authorizeStaff(): void
    {
        if (! request()->user()?->role->atLeast(Role::Staff)) {
            abort(403);
        }
    }

    private function extractMediaArchive(string $zipPath, string $destination): void
    {
        if ($zipPath === '' || ! is_file($zipPath)) {
            return;
        }

        File::ensureDirectoryExists($destination);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open media zip archive.');
        }

        try {
            $zip->extractTo($destination);
        } finally {
            $zip->close();
        }
    }
}
