<?php

namespace App\Http\Controllers;

use App\Services\HostUpdateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HostUpdateController extends Controller
{
    public function index(HostUpdateManager $updates): View
    {
        try {
            $status = $updates->status();
            $statusError = null;
        } catch (\Throwable $exception) {
            $status = ['current' => $updates->currentRevision(), 'prepared' => null, 'status' => null, 'error' => null];
            $statusError = $exception->getMessage();
        }
        try {
            $commits = $updates->recent();
        } catch (\Throwable) {
            $commits = [];
        }

        return view('settings.updates', compact('status', 'statusError', 'commits'));
    }

    public function status(HostUpdateManager $updates): JsonResponse
    {
        return response()->json($updates->status());
    }

    public function start(HostUpdateManager $updates): RedirectResponse
    {
        try {
            $updates->start();
        } catch (\Throwable $exception) {
            return back()->withErrors(['update' => $exception->getMessage()]);
        }

        return back()->with('status', 'La actualización comenzó en segundo plano. Esta página mostrará el resultado.');
    }
}
