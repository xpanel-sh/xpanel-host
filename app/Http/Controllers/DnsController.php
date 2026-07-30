<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\View\View;

class DnsController extends Controller
{
    public function index(): View
    {
        return view('domains.dns', [
            'domains' => Domain::with('site')->orderBy('domain')->get(),
        ]);
    }
}
