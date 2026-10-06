<?php

namespace App\Http\Controllers;

use App\Services\HostUpdateManager;
use Illuminate\Support\Carbon;
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
            $status = ['current' => $updates->currentRevision(), 'prepared' => null, 'status' => null, 'stage' => null, 'message' => null, 'error' => null];
            $statusError = $exception->getMessage();
        }
        try {
            $commits = $updates->recent();
        } catch (\Throwable) {
            $commits = [];
        }

        $commitsByYear = collect($commits)->map(function (array $commit): array {
            $date = filled($commit['date'] ?? null)
                ? Carbon::parse($commit['date'])->timezone(config('app.timezone'))
                : null;

            return [...$commit, 'local_year' => $date?->year, 'local_date' => $date?->locale('es')->translatedFormat('d \d\e F \d\e Y'), 'local_time' => $date?->format('H:i')];
        })->groupBy(fn (array $commit) => $commit['local_year'] ?? 'Sin fecha');

        $latestCommit = isset($commits[0]) ? substr($commits[0]['sha'], 0, 12) : null;

        return view('settings.updates', compact('status', 'statusError', 'commitsByYear', 'latestCommit'));
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
