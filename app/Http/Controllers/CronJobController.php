<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteCronJob;
use App\Services\CronProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CronJobController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.advanced.cron-jobs', ['site' => $site, 'jobs' => $site->cronJobs()->get()]);
    }

    public function store(Request $request, Site $site, CronProvisioner $cron): RedirectResponse
    {
        $job = $site->cronJobs()->create($this->validated($request));
        try {
            $cron->sync($site);
        } catch (\Throwable $exception) {
            $job->delete();

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Tarea cron creada y sincronizada.');
    }

    public function update(Request $request, Site $site, SiteCronJob $cronJob, CronProvisioner $cron): RedirectResponse
    {
        $this->belongsTo($site, $cronJob);
        $previous = $cronJob->getAttributes();
        $cronJob->update($this->validated($request));
        try {
            $cron->sync($site);
        } catch (\Throwable $exception) {
            $cronJob->forceFill($previous)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Tarea cron actualizada.');
    }

    public function destroy(Site $site, SiteCronJob $cronJob, CronProvisioner $cron): RedirectResponse
    {
        $this->belongsTo($site, $cronJob);
        $attributes = $cronJob->getAttributes();
        $cronJob->delete();
        try {
            $cron->sync($site);
        } catch (\Throwable $exception) {
            $cronJob->forceFill($attributes)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Tarea cron eliminada.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'expression' => ['required', 'string', 'max:64', function ($attribute, $value, $fail): void {
                $fields = preg_split('/\s+/', trim((string) $value));
                if (count($fields) !== 5 || collect($fields)->contains(fn ($field) => ! preg_match('/^[0-9*,\/-]+$/', $field))) {
                    $fail('Usa una expresión cron de cinco campos (por ejemplo: 0 2 * * *).');
                }
            }],
            'command' => ['required', 'string', 'max:500', 'not_regex:/[\r\n\0%]/'],
            'enabled' => ['nullable', 'boolean'],
        ]) + ['enabled' => $request->boolean('enabled')];
    }

    private function belongsTo(Site $site, SiteCronJob $job): void
    {
        abort_unless($job->site_id === $site->id, 404);
    }
}
