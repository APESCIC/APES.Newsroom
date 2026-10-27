<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Mailing\SubscriberListService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriberController extends Controller
{
    public function __construct(private readonly SubscriberListService $subscribers) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'newsletter_id' => ['nullable', 'integer', 'exists:newsletters,id'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $search = $validated['q'] ?? null;
        $newsletterId = isset($validated['newsletter_id']) ? (int) $validated['newsletter_id'] : null;
        $source = $validated['source'] ?? null;

        return Inertia::render('Staff/Subscribers/Index', [
            'filters' => [
                'q' => $search ?? '',
                'newsletter_id' => $newsletterId,
                'source' => $source ?? '',
            ],
            'newsletters' => $this->subscribers->newsletterOptions(),
            'sources' => $this->subscribers->sourceOptions(),
            'subscribers' => $this->subscribers->paginate($search, $newsletterId, $source),
        ]);
    }

    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'newsletter_id' => ['nullable', 'integer', 'exists:newsletters,id'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $search = $validated['q'] ?? null;
        $newsletterId = isset($validated['newsletter_id']) ? (int) $validated['newsletter_id'] : null;
        $source = $validated['source'] ?? null;

        $rows = $this->subscribers->paginate($search, $newsletterId, $source, 10_000);

        $audit->record($request->user(), 'subscribers.exported', null, [
            'count' => $rows->total(),
            'filters' => [
                'q' => $search,
                'newsletter_id' => $newsletterId,
                'source' => $source,
            ],
        ], $request);

        $filename = 'mailing-only-subscribers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['email', 'name', 'signed_up_at', 'suppressed', 'source', 'consent_action', 'subscriptions']);

            foreach ($rows as $row) {
                $subs = collect($row['subscriptions'])
                    ->map(fn (array $s) => $s['newsletter'].' ('.$s['status'].($s['opted_out'] ? ', opted-out' : '').')')
                    ->implode('; ');

                fputcsv($out, [
                    $row['email'],
                    $row['name'] ?? '',
                    $row['signed_up_at'],
                    $row['suppressed'] ? 'yes' : 'no',
                    $row['source'] ?? '',
                    $row['consent_action'] ?? '',
                    $subs,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
