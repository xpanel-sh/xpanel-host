<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.advanced.activity-log', [
            'site' => $site,
            'activities' => $site->activityLogs()->with('user')->paginate(25),
        ]);
    }
}
