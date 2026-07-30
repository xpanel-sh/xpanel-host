<?php

namespace App\Http\Controllers;

use App\Models\ProtectedDirectory;
use App\Models\Site;
use App\Services\ProtectedDirectoryProvisioner;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProtectedDirectoryController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.advanced.password-protect-directories', ['site' => $site, 'rules' => $site->protectedDirectories()->get()]);
    }

    public function store(Request $request, Site $site, ProtectedDirectoryProvisioner $auth, SiteProvisioner $sites): RedirectResponse
    {
        $request->merge(['path' => '/'.trim((string) $request->input('path'), '/').'/']);
        $data = $request->validate([
            'path' => [
                'required', 'string', 'max:255', 'not_in://', 'regex:#^/[A-Za-z0-9._~/%-]+/$#',
                function (string $attribute, mixed $value, \Closure $fail) use ($site): void {
                    if (str_starts_with((string) $value, '/.well-known/') || str_starts_with((string) $value, '/.xpanel-errors/')) {
                        $fail('Esa ruta está reservada por SSL o por las páginas de error.');
                    }
                    if ($site->redirects()->whereIn('source_path', [(string) $value, rtrim((string) $value, '/')])->exists()) {
                        $fail('Ya existe una redirección en esa ruta; ambas reglas producirían una configuración ambigua.');
                    }
                },
                Rule::unique('protected_directories')->where('site_id', $site->id),
            ],
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:16', 'max:255'],
            'realm' => ['required', 'string', 'max:128', 'not_regex:/[\r\n\"]/'],
        ]);
        $rule = $site->protectedDirectories()->create([
            'path' => $data['path'], 'username' => $data['username'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT), 'realm' => $data['realm'], 'enabled' => true,
        ]);
        $site->unsetRelation('protectedDirectories');
        try {
            $auth->sync($site);
            $sites->provision($site);
        } catch (\Throwable $exception) {
            $rule->delete();

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Directorio {$rule->path} protegido.");
    }

    public function destroy(Site $site, ProtectedDirectory $protectedDirectory, ProtectedDirectoryProvisioner $auth, SiteProvisioner $sites): RedirectResponse
    {
        abort_unless($protectedDirectory->site_id === $site->id, 404);
        $attributes = $protectedDirectory->getAttributes();
        $protectedDirectory->delete();
        $site->unsetRelation('protectedDirectories');
        try {
            $auth->sync($site);
            $sites->provision($site);
        } catch (\Throwable $exception) {
            $protectedDirectory->forceFill($attributes)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Protección eliminada.');
    }
}
