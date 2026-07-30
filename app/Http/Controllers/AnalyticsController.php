<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\AccessLogAnalyzer;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function __invoke(Site $site, AccessLogAnalyzer $analyzer): View
    {
        return view('sites.analytics.index', ['site' => $site, 'analytics' => $analyzer->analyze($site)]);
    }
}
