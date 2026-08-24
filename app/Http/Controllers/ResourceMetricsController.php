<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\LiveResourceMetricsService;
use Illuminate\Http\JsonResponse;

class ResourceMetricsController extends Controller
{
    public function account(LiveResourceMetricsService $metrics): JsonResponse
    {
        return response()->json($metrics->account());
    }

    public function site(Site $site, LiveResourceMetricsService $metrics): JsonResponse
    {
        return response()->json($metrics->site($site));
    }
}
