<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Release;
use App\Services\Engagement\ModerationCounts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const AUDIT_LIMIT = 8;

    public function __invoke(Request $request, ModerationCounts $moderation): Response
    {
        $release = Release::query()
            ->published()
            ->orderByDesc('is_current')
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();

        $audit = $request->user()->can('viewAny', AuditLog::class)
            ? AuditLog::query()
                ->with('actor:id,name')
                ->latest('id')
                ->limit(self::AUDIT_LIMIT)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actor' => $log->actor?->name,
                    'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
                ->values()
                ->all()
            : null;

        return Inertia::render('Admin/Dashboard', [
            'moderation' => $moderation->summary(),
            'release' => $release ? [
                'version' => $release->version,
                'summary' => $release->summary,
                'released_at' => $release->released_at?->toDateString(),
            ] : null,
            'audit' => $audit,
        ]);
    }
}
