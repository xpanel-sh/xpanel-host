<?php

namespace App\Http\Controllers;

use App\Models\BackupPolicy;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Services\SiteBackupManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.files.backups', [
            'site' => $site,
            'backups' => $site->backups()->with('user')->paginate(15),
            'policy' => $site->backupPolicy ?? new BackupPolicy([
                'enabled' => false,
                'frequency' => 'daily',
                'retention_count' => 7,
            ]),
        ]);
    }

    public function store(Request $request, Site $site, SiteBackupManager $manager): RedirectResponse
    {
        try {
            $backup = $manager->create($site, $request->user());

            return back()->with('status', 'Backup creado correctamente: '.$backup->token);
        } catch (\Throwable $exception) {
            return back()->withErrors(['backup' => $exception->getMessage()]);
        }
    }

    public function restore(Request $request, Site $site, SiteBackup $siteBackup, SiteBackupManager $manager): RedirectResponse
    {
        $this->assertBackupBelongsToSite($site, $siteBackup);
        $request->validate(['confirmation' => ['required', Rule::in([$site->domain])]]);

        try {
            $safety = $manager->restore($site, $siteBackup, $request->user());

            return back()->with('status', 'Sitio restaurado. Backup previo de seguridad: '.$safety->token);
        } catch (\Throwable $exception) {
            return back()->withErrors(['backup' => $exception->getMessage()]);
        }
    }

    public function download(Site $site, SiteBackup $siteBackup, SiteBackupManager $manager): BinaryFileResponse
    {
        $this->assertBackupBelongsToSite($site, $siteBackup);
        try {
            $path = $manager->packagePath($site, $siteBackup);
        } catch (\Throwable) {
            abort(404, 'El archivo del backup no está disponible.');
        }

        $response = response()->download($path, $site->domain.'-'.$siteBackup->created_at->format('Ymd-His').'.tar.gz', [
            'Content-Type' => 'application/gzip',
            'X-Content-Type-Options' => 'nosniff',
            'Pragma' => 'no-cache',
        ]);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    public function destroy(Site $site, SiteBackup $siteBackup, SiteBackupManager $manager): RedirectResponse
    {
        $this->assertBackupBelongsToSite($site, $siteBackup);
        try {
            $manager->delete($site, $siteBackup);

            return back()->with('status', 'Backup eliminado.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['backup' => $exception->getMessage()]);
        }
    }

    public function policy(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'retention_count' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $site->backupPolicy()->updateOrCreate([], [
            'enabled' => $request->boolean('enabled'),
            'frequency' => $data['frequency'],
            'retention_count' => $data['retention_count'],
        ]);

        return back()->with('status', 'Política de backups actualizada.');
    }

    private function assertBackupBelongsToSite(Site $site, SiteBackup $backup): void
    {
        abort_unless($backup->site_id === $site->id, 404);
    }
}
