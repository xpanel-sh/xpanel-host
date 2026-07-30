<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SitePhpSetting;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PhpConfigurationController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.advanced.php-configuration', ['site' => $site, 'settings' => $this->settings($site)]);
    }

    public function info(Site $site): View
    {
        return view('sites.advanced.php-info', [
            'site' => $site,
            'settings' => $this->settings($site),
            'extensions' => get_loaded_extensions(),
            'controlRuntime' => PHP_VERSION,
        ]);
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
