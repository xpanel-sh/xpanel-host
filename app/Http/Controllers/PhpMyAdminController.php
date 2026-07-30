<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\View\View;

class PhpMyAdminController extends Controller
{
    public function __invoke(Site $site): View
    {
        return view('sites.database.phpmyadmin', [
            'site' => $site,
            'databases' => $site->databases()->orderBy('name')->get(),
            'phpMyAdminUrl' => url('/phpmyadmin/'),
            'enabled' => (bool) config('xpanel.phpmyadmin_enabled'),
        ]);
    }
}
