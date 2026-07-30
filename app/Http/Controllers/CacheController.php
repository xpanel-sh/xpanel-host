<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\SiteCacheManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Number;
use Illuminate\View\View;

class CacheController extends Controller
{
    public function index(Site $site, SiteCacheManager $cache): View
    {
        return view('sites.advanced.cache-manager', [
            'site' => $site,
            'targets' => collect($cache->targets($site->document_root))->map(fn ($path) => ['path' => $path, 'exists' => is_dir($path)]),
        ]);
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
