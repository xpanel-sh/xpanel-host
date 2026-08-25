<?php

namespace App\Http\Controllers;

use App\Models\XflowWorkflow;
use App\Services\XflowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class XflowWebhookController extends Controller
{
    public function __invoke(Request $request, XflowWorkflow $workflow, string $token, XflowRunner $runner): JsonResponse
    {
        abort_unless($workflow->trigger_type === 'webhook' && $workflow->status === 'active'
            && is_string($workflow->webhook_token) && hash_equals($workflow->webhook_token, $token), 404);
        abort_if(strlen($request->getContent()) > 65536, 413, 'El payload supera 64 KiB.');
        try {
            $run = $runner->run($workflow, 'webhook', $request->all());
        } catch (\Throwable $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['ok' => $run->status === 'completed', 'run' => $run->uuid, 'status' => $run->status]);
    }
}
