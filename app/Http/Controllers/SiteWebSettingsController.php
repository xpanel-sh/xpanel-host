<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteWebSetting;
use App\Services\SiteCacheManager;
use Illuminate\View\View;

class SiteWebSettingsController extends Controller
{
    public function __invoke(Site $site, SiteCacheManager $cache): View
    {
        return view('sites.advanced.web-settings', [
            'site' => $site,
            'settings' => $site->webSettings ?? new SiteWebSetting([
                'directory_listing' => false,
                'hotlink_protection' => false,
                'hotlink_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'hotlink_allowed_referrers' => [],
            ]),
            'extensions' => HotlinkController::EXTENSIONS,
            'cacheTargets' => collect($cache->targets($site->document_root))
                ->map(fn (string $path): array => ['path' => $path, 'exists' => is_dir($path)]),
        ]);
    }
}
