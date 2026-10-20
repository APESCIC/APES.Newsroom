<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGhostMembersImportJob;
use App\Models\ImportRun;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GhostMembersImportController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $runs = ImportRun::query()
            ->where('type', 'ghost_members_csv')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ImportRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'dry_run' => $run->dry_run,
                'source_checksum' => $run->source_checksum,
                'report' => $run->report,
                'created_at' => $run->created_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Imports/GhostMembers', [
            'runs' => $runs,
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'mode' => ['required', 'in:dry-run,import'],
        ]);

        $path = $validated['csv']->store('imports/ghost-members');
        $absolute = Storage::path($path);
        $dryRun = $validated['mode'] === 'dry-run';

        $run = ImportRun::create([
            'type' => 'ghost_members_csv',
            'status' => 'queued',
            'dry_run' => $dryRun,
            'source_path' => $absolute,
            'source_checksum' => hash_file('sha256', $absolute),
            'actor_id' => $request->user()->id,
            'started_at' => null,
        ]);

        $this->audit->record($request->user(), $dryRun ? 'import.ghost_members.dry_run' : 'import.ghost_members.import', $run, [
            'checksum' => $run->source_checksum,
            'path' => $path,
        ], $request);

        ProcessGhostMembersImportJob::dispatch($run->id, $dryRun);

        return back()->with('status', $dryRun ? 'Dry-run queued.' : 'Import queued.');
    }

    public function report(Request $request, ImportRun $run): StreamedResponse|RedirectResponse
    {
        $this->authorizeAdmin();
        abort_unless($run->type === 'ghost_members_csv', 404);

        $payload = json_encode($run->report ?? [], JSON_PRETTY_PRINT);

        return response()->streamDownload(function () use ($payload) {
            echo $payload;
        }, "ghost-members-import-{$run->id}.json", [
            'Content-Type' => 'application/json',
        ]);
    }

    private function authorizeAdmin(): void
    {
        Gate::authorize('access-admin-area');
    }
}
