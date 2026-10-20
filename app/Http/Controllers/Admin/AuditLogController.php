<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditLogQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = AuditLogQuery::filters($request);

        $entries = AuditLogQuery::build($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $log) => $this->row($log));

        return Inertia::render('Admin/AuditLog/Index', [
            'filters' => $filters,
            'entries' => $entries,
            'subjectTypes' => AuditLogQuery::subjectTypes()->values()->unique()->values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('export', AuditLog::class);

        $filters = AuditLogQuery::filters($request);
        $query = AuditLogQuery::build($filters);

        $this->audit->record($request->user(), 'audit_log.exported', null, [
            'filters' => array_filter($filters, fn (string $value) => $value !== ''),
            'rows' => (clone $query)->count(),
        ], $request);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'action', 'actor_id', 'actor_email', 'subject_type', 'subject_id', 'ip_address', 'user_agent', 'payload'], escape: '');

            foreach ($query->lazyByIdDesc(500, 'id') as $log) {
                fputcsv($out, array_map(self::csvSafe(...), [
                    $log->id,
                    $log->created_at?->toIso8601String(),
                    $log->action,
                    $log->actor_id,
                    $log->actor?->email,
                    $log->subject_type ? class_basename($log->subject_type) : null,
                    $log->subject_id,
                    $log->ip_address,
                    $log->user_agent,
                    json_encode($log->payload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]), escape: '');
            }

            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'action' => $log->action,
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'name' => $log->actor->name,
                'email' => $log->actor->email,
            ] : null,
            'subject' => $log->subject_type ? [
                'type' => class_basename($log->subject_type),
                'id' => $log->subject_id,
            ] : null,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'payload' => AuditLogQuery::payloadPairs($log->payload),
        ];
    }

    /**
     * Neutralises spreadsheet formula injection in exported cells.
     */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
