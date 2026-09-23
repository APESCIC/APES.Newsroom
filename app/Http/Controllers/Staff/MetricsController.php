<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Analytics\MetricsDashboard;
use Inertia\Inertia;
use Inertia\Response;

class MetricsController extends Controller
{
    public function __invoke(MetricsDashboard $metrics): Response
    {
        return Inertia::render('Staff/Metrics/Index', [
            'metrics' => $metrics->summary(),
        ]);
    }
}
