<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Analytics\MetricsDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetricsController extends Controller
{
    public function __invoke(Request $request, MetricsDashboard $metrics): Response
    {
        return Inertia::render('Staff/Metrics/Index', [
            'metrics' => [
                'web' => $metrics->web(),
                'newsletters' => $metrics->newsletters(),
                // Membership revenue (test-mode MRR) is admin-only (#357).
                'subscriptions' => $request->user()->can('access-admin-area') ? $metrics->subscriptions() : null,
            ],
        ]);
    }
}
