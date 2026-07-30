<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\GitDeploymentManager;
use App\Services\SiteBackupManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GitDeploymentController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.advanced.git', ['site' => $site, 'repository' => $site->gitRepository]);
    }

    public function store(Request $request, Site $site, GitDeploymentManager $deployments, SiteBackupManager $backups): RedirectResponse
    {
        $data = $request->validate([
            'repository_url' => ['required', 'url:https', 'max:2048', 'regex:#^https://(?:github\.com|gitlab\.com|bitbucket\.org)/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?$#'],
            'branch' => ['required', 'string', 'max:128', 'regex:#^(?![.-])(?!.*(?:\.\.|@\{|[~^:?*\\\[\]]))[A-Za-z0-9._/-]+(?<![./])$#'],
            'confirmation' => ['required', 'in:DEPLOY'],
        ]);
        $repository = $site->gitRepository()->updateOrCreate([], [
            'repository_url' => $data['repository_url'], 'branch' => $data['branch'], 'status' => 'pending',
        ]);
        try {
            $backups->create($site, $request->user(), 'pre_deploy');
            $deployments->deploy($site, $repository);
        } catch (\Throwable $exception) {
            return back()->withInput()->withErrors(['deploy' => $exception->getMessage()]);
        }

        return back()->with('status', $repository->fresh()->status === 'staged' ? 'Repositorio preparado para el servidor Linux.' : 'Repositorio conectado y desplegado.');
    }

    public function deploy(Request $request, Site $site, GitDeploymentManager $deployments, SiteBackupManager $backups): RedirectResponse
    {
        $repository = $site->gitRepository;
        abort_if($repository === null, 404);
        try {
            $backups->create($site, $request->user(), 'pre_deploy');
            $deployments->deploy($site, $repository);
        } catch (\Throwable $exception) {
            return back()->withErrors(['deploy' => $exception->getMessage()]);
        }

        return back()->with('status', 'Último commit de la rama desplegado.');
    }

    public function destroy(Site $site, GitDeploymentManager $deployments): RedirectResponse
    {
        try {
            $deployments->disconnect($site);
        } catch (\Throwable $exception) {
            return back()->withErrors(['deploy' => $exception->getMessage()]);
        }

        return back()->with('status', 'Repositorio desconectado. Los archivos publicados se conservaron.');
    }
}
