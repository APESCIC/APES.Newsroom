<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Analytics\MetricsDashboard;
use App\Services\Mailing\CampaignStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetricsController extends Controller
{
    public function __invoke(Request $request, MetricsDashboard $metrics, CampaignStats $campaigns): Response
    {
        return Inertia::render('Staff/Metrics/Index', [
            'metrics' => [
                'web' => $metrics->web(),
                'newsletters' => $metrics->newsletters(),
                // Membership revenue (test-mode MRR) is admin-only (#357).
                'subscriptions' => $request->user()->can('access-admin-area') ? $metrics->subscriptions() : null,
            ],
            'newsletterBreakdown' => $campaigns->byNewsletter(),
            'deliverySuppressions' => $campaigns->deliverySuppressions(),
        ]);
    }
}
