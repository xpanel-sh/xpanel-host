<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteSqliteDatabase;
use App\Services\HostingPlanQuota;
use App\Services\SiteSqliteDatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class SqliteDatabaseController extends Controller
{
    public function store(Request $request, Site $site, SiteSqliteDatabaseManager $sqlite, HostingPlanQuota $quota): RedirectResponse
    {
        $data = $request->validate(['relative_path' => ['required', 'string', 'max:255']]);
        try {
            if (! $site->sqliteDatabases()->where('relative_path', $data['relative_path'])->exists()) {
                $quota->assertCanCreateDatabase();
            }
            $sqlite->register($site, $data['relative_path']);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['relative_path' => $exception->getMessage()]);
        }

        return back()->with('status', 'Archivo SQLite conectado a DbGate. Los datos siguen en el sitio.');
    }

    public function destroy(Site $site, SiteSqliteDatabase $sqliteDatabase): RedirectResponse
    {
        abort_unless($sqliteDatabase->site_id === $site->id, 404);
        $sqliteDatabase->delete();

        return back()->with('status', 'Conexión SQLite retirada. El archivo y sus datos no se eliminaron.');
    }
}
