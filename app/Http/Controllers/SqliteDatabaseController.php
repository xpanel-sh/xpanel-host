<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteSqliteDatabase;
use App\Services\HostingPlanQuota;
use App\Services\HostingDatabaseScope;
use App\Services\SiteSqliteDatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class SqliteDatabaseController extends Controller
{
    public function store(Request $request, Site $site, SiteSqliteDatabaseManager $sqlite, HostingPlanQuota $quota, HostingDatabaseScope $scope): RedirectResponse
    {
        abort_if($site->parent_site_id !== null, 404);
        $data = $request->validate(['file' => ['required', 'string', 'max:270', 'regex:/^[0-9]+\|.+$/']]);
        [$siteId, $relativePath] = explode('|', $data['file'], 2);
        $target = $scope->selected($site, (int) $siteId);
        try {
            if (! $target->sqliteDatabases()->where('relative_path', $relativePath)->exists()) {
                $quota->assertCanCreateDatabase();
            }
            $sqlite->register($target, $relativePath);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['file' => $exception->getMessage()]);
        }

        return back()->with('status', 'Archivo SQLite conectado a DbGate. Los datos siguen en el sitio.');
    }

    public function destroy(Site $site, SiteSqliteDatabase $sqliteDatabase, HostingDatabaseScope $scope): RedirectResponse
    {
        abort_if($site->parent_site_id !== null, 404);
        abort_unless($scope->contains($site, $sqliteDatabase->site), 404);
        $sqliteDatabase->delete();

        return back()->with('status', 'Conexión SQLite retirada. El archivo y sus datos no se eliminaron.');
    }
}
