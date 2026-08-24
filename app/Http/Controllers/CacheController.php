<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\SiteCacheManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Number;

class CacheController extends Controller
{
    public function index(Site $site): RedirectResponse
    {
        return to_route('sites.web-settings.index', $site);
    }

    public function purge(Site $site, SiteCacheManager $cache): RedirectResponse
    {
        try {
            $result = $cache->purge($site);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Caché purgada: {$result['files']} archivos y ".Number::fileSize($result['bytes']).' liberados.');
    }
}
