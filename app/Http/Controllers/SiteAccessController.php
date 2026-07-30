<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteSshKey;
use App\Services\SiteAccessProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteAccessController extends Controller
{
    public function files(Site $site): View
    {
        return $this->view($site, 'sites.files.ftp');
    }

    public function ssh(Site $site): View
    {
        return $this->view($site, 'sites.advanced.ssh-access');
    }

    public function update(Request $request, Site $site, SiteAccessProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'sftp_enabled' => ['nullable', 'boolean'],
            'ftp_enabled' => ['nullable', 'boolean'],
            'ssh_enabled' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
        ]);
        $settings = $site->accessSettings()->firstOrCreate();
        $enablingPasswordProtocol = ($request->boolean('sftp_enabled') && ! $settings->sftp_enabled)
            || ($request->boolean('ftp_enabled') && ! $settings->ftp_enabled);
        if ($enablingPasswordProtocol && blank($data['password'] ?? null)) {
            return back()->withErrors(['password' => 'Define una contraseña al activar SFTP o FTPS.']);
        }
        if ($request->boolean('ssh_enabled') && ! $site->sshKeys()->exists()) {
            return back()->withErrors(['ssh_enabled' => 'Añade al menos una llave antes de activar SSH.']);
        }
        $original = $settings->getAttributes();
        $settings->fill([
            'sftp_enabled' => $request->boolean('sftp_enabled'),
            'ftp_enabled' => $request->boolean('ftp_enabled'),
            'ssh_enabled' => $request->boolean('ssh_enabled'),
        ]);
        if (filled($data['password'] ?? null)) {
            $settings->password_rotated_at = now();
        }
        $settings->save();
        try {
            $provisioner->sync($site, $settings, $data['password'] ?? null);
        } catch (\Throwable $exception) {
            $settings->forceFill($original)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Accesos del sitio actualizados.');
    }

    public function storeKey(Request $request, Site $site, SiteAccessProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9 ._-]+$/'],
            'public_key' => ['required', 'string', 'max:16384', 'regex:/^(ssh-ed25519|ssh-rsa) [A-Za-z0-9+\/]+={0,3}(?: [^\r\n]{1,200})?$/'],
        ]);
        $blob = base64_decode(explode(' ', trim($data['public_key']))[1], true);
        if ($blob === false) {
            return back()->withErrors(['public_key' => 'La llave pública no contiene Base64 válido.']);
        }
        $type = explode(' ', trim($data['public_key']))[0];
        $length = unpack('N', substr($blob, 0, 4))[1] ?? 0;
        if ($length < 1 || substr($blob, 4, $length) !== $type) {
            return back()->withErrors(['public_key' => 'La llave pública no coincide con su tipo declarado.']);
        }
        $key = $site->sshKeys()->create([
            'name' => $data['name'], 'public_key' => trim($data['public_key']),
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='),
        ]);
        try {
            $provisioner->sync($site, $site->accessSettings()->firstOrCreate());
        } catch (\Throwable $exception) {
            $key->delete();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Llave SSH añadida.');
    }

    public function destroyKey(Site $site, SiteSshKey $sshKey, SiteAccessProvisioner $provisioner): RedirectResponse
    {
        abort_unless($sshKey->site_id === $site->id, 404);
        $attributes = $sshKey->getAttributes();
        $sshKey->delete();
        try {
            $provisioner->sync($site, $site->accessSettings()->firstOrCreate());
        } catch (\Throwable $exception) {
            SiteSshKey::create($attributes);

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Llave SSH retirada.');
    }

    private function view(Site $site, string $view): View
    {
        return view($view, [
            'site' => $site,
            'settings' => $site->accessSettings()->firstOrNew(),
            'sshKeys' => $site->sshKeys()->get(),
        ]);
    }
}
