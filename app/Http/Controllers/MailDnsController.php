<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\MailDnsService;
use Illuminate\Http\JsonResponse;

class MailDnsController extends Controller
{
    public function __invoke(Domain $domain, MailDnsService $dns): JsonResponse
    {
        abort_unless($domain->mailAccounts()->exists(), 404);

        return response()->json([
            'domain' => $domain->domain,
            'checks' => $dns->verify($domain),
        ]);
    }
}
