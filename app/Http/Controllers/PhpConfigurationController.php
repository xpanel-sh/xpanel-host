<?php

namespace App\Http\Controllers;

use App\Models\PhpProfile;
use App\Models\Site;
use App\Models\SitePhpSetting;
use App\Services\PhpExtensionCatalog;
use App\Services\ServerCommandRunner;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PhpConfigurationController extends Controller
{
    public function index(Site $site, PhpExtensionCatalog $catalog): View
    {
        $site->load('phpProfile');

        return view('sites.advanced.php-configuration', [
            'site' => $site,
            'settings' => $this->settings($site),
            'profiles' => PhpProfile::query()->where('php_version', $site->php_version)->withCount('sites')->orderBy('name')->get(),
            'extensionCatalog' => $catalog->forVersion($site->php_version),
        ]);
    }

    public function info(Site $site): RedirectResponse
    {
        return redirect()->route('sites.php.configuration', $site);
    }

    public function update(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        abort_if($site->type !== 'php', 422, 'La configuración PHP solo se aplica a sitios PHP.');
        $sizes = ['32M', '64M', '128M', '256M', '512M', '1G', '2G'];
        $data = $request->validate([
            'memory_limit' => ['required', Rule::in($sizes)],
            'upload_max_filesize' => ['required', Rule::in($sizes)],
            'post_max_size' => ['required', Rule::in($sizes)],
            'max_execution_time' => ['required', 'integer', 'min:10', 'max:900'],
            'display_errors' => ['nullable', 'boolean'],
        ]);
        $data['display_errors'] = $request->boolean('display_errors');
        if ($this->bytes($data['post_max_size']) < $this->bytes($data['upload_max_filesize'])) {
            return back()->withInput()->withErrors(['post_max_size' => 'post_max_size debe ser igual o mayor que upload_max_filesize.']);
        }

        $previous = $site->phpSettings?->getAttributes();
        $settings = $site->phpSettings()->updateOrCreate([], $data);
        $site->unsetRelation('phpSettings');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $previous === null ? $settings->delete() : $settings->forceFill($previous)->save();
            $site->unsetRelation('phpSettings');

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Configuración PHP aplicada al sitio.');
    }

    public function assignProfile(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $this->assertProfileCompatibleSite($site);
        $data = $request->validate(['php_profile_id' => ['nullable', 'integer', 'exists:php_profiles,id']]);
        $profile = empty($data['php_profile_id']) ? null : PhpProfile::findOrFail($data['php_profile_id']);
        if ($profile && $profile->php_version !== $site->php_version) {
            return back()->withErrors(['php_profile_id' => 'El perfil debe usar la misma versión PHP del sitio.']);
        }
        $previous = $site->php_profile_id;
        $site->php_profile_id = $profile?->id;
        $site->save();
        $site->setRelation('phpProfile', $profile);
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $site->forceFill(['php_profile_id' => $previous])->save();
            $site->unsetRelation('phpProfile')->load('phpProfile');
            try {
                $provisioner->provision($site);
            } catch (\Throwable) {
                // Database state is restored; a later system sync can retry the runtime.
            }

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', $profile ? 'Perfil PHP aplicado al sitio.' : 'El sitio vuelve a usar la configuración PHP global.');
    }

    public function storeProfile(Request $request, Site $site, PhpExtensionCatalog $catalog, SiteProvisioner $provisioner): RedirectResponse
    {
        $this->assertProfileCompatibleSite($site);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('php_profiles')->where('php_version', $site->php_version)],
            'extensions' => ['nullable', 'array'],
            'extensions.*' => ['string'],
        ]);
        $extensions = $catalog->validateSelection($site->php_version, $data['extensions'] ?? []);
        $profile = PhpProfile::create(['name' => trim($data['name']), 'php_version' => $site->php_version, 'extensions' => $extensions]);
        $previous = $site->php_profile_id;
        $site->update(['php_profile_id' => $profile->id]);
        $site->setRelation('phpProfile', $profile);
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $site->forceFill(['php_profile_id' => $previous])->save();
            $profile->delete();
            $site->unsetRelation('phpProfile')->load('phpProfile');
            try {
                $provisioner->provision($site);
            } catch (\Throwable) {
                // Database state is restored; a later system sync can retry the runtime.
            }

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Perfil PHP creado y aplicado al sitio.');
    }

    public function updateProfile(Request $request, Site $site, PhpProfile $phpProfile, PhpExtensionCatalog $catalog, SiteProvisioner $provisioner): RedirectResponse
    {
        $this->assertProfileVersion($site, $phpProfile);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('php_profiles')->where('php_version', $phpProfile->php_version)->ignore($phpProfile)],
            'extensions' => ['nullable', 'array'],
            'extensions.*' => ['string'],
        ]);
        $previous = $phpProfile->getAttributes();
        $phpProfile->update(['name' => trim($data['name']), 'extensions' => $catalog->validateSelection($phpProfile->php_version, $data['extensions'] ?? [])]);
        try {
            foreach ($phpProfile->sites()->get() as $assignedSite) {
                $assignedSite->setRelation('phpProfile', $phpProfile);
                $provisioner->provision($assignedSite);
            }
        } catch (\Throwable $exception) {
            $phpProfile->forceFill($previous)->save();
            foreach ($phpProfile->sites()->get() as $assignedSite) {
                try {
                    $assignedSite->setRelation('phpProfile', $phpProfile);
                    $provisioner->provision($assignedSite);
                } catch (\Throwable) {
                    // Continue restoring every site that shares the profile.
                }
            }

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Perfil PHP actualizado en todos sus sitios.');
    }

    public function destroyProfile(Site $site, PhpProfile $phpProfile, ServerCommandRunner $commands): RedirectResponse
    {
        $this->assertProfileVersion($site, $phpProfile);
        if ($phpProfile->sites()->exists()) {
            return back()->withErrors(['profile' => 'Cambia primero los sitios que todavía usan este perfil.']);
        }
        if (config('xpanel.apply_system_changes')) {
            try {
                $commands->run(['sudo', '-n', (string) config('xpanel.site_helper'), 'php-profile-remove', $phpProfile->runtimeKey()]);
            } catch (\Throwable $exception) {
                return back()->withErrors(['server' => $exception->getMessage()]);
            }
        }
        $phpProfile->delete();

        return back()->with('status', 'Perfil PHP eliminado.');
    }

    public function installExtension(Site $site, string $extension, PhpExtensionCatalog $catalog, ServerCommandRunner $commands): RedirectResponse
    {
        $this->assertProfileCompatibleSite($site);
        abort_if(config('xpanel.management_mode') === 'vps-instance', 403, 'Las extensiones disponibles en una instancia las define el administrador de XPanel VPS.');
        $catalog->package($site->php_version, $extension);
        if (config('xpanel.apply_system_changes')) {
            try {
                $commands->run(['sudo', '-n', (string) config('xpanel.site_helper'), 'php-extension-install', $site->php_version, $extension], timeout: 1800);
            } catch (\Throwable $exception) {
                return back()->withErrors(['server' => $exception->getMessage()]);
            }
        }

        return back()->with('status', 'Extensión instalada. Ya puedes activarla en un perfil.');
    }

    private function assertProfileCompatibleSite(Site $site): void
    {
        abort_if($site->type !== 'php', 422, 'Los perfiles solo se aplican a sitios PHP.');
        abort_if($site->web_server === 'openlitespeed', 422, 'Los perfiles aislados requieren Nginx o Apache con PHP-FPM.');
    }

    private function assertProfileVersion(Site $site, PhpProfile $profile): void
    {
        abort_unless($profile->php_version === $site->php_version, 404);
    }

    private function settings(Site $site): SitePhpSetting
    {
        return $site->phpSettings ?? new SitePhpSetting([
            'memory_limit' => '256M', 'upload_max_filesize' => '64M', 'post_max_size' => '64M',
            'max_execution_time' => 60, 'display_errors' => false,
        ]);
    }

    private function bytes(string $value): int
    {
        return (int) $value * (str_ends_with($value, 'G') ? 1024 : 1);
    }
}
