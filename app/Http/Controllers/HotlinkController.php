<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HotlinkController extends Controller
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'mp3', 'pdf', 'zip'];

    public function index(Site $site): RedirectResponse
    {
        return to_route('sites.web-settings.index', $site);
    }

    public function update(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $request->merge(['allowed_referrers' => array_values(array_filter(array_map(
            fn ($value) => strtolower(trim($value)),
            preg_split('/\R/', (string) $request->input('allowed_referrers')) ?: [],
        )))]);
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'extensions' => ['required', 'array', 'min:1'],
            'extensions.*' => [Rule::in(self::EXTENSIONS)],
            'allowed_referrers' => ['array', 'max:50'],
            'allowed_referrers.*' => ['string', 'max:255', 'regex:/^(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
        ]);
        $values = [
            'hotlink_protection' => $request->boolean('enabled'),
            'hotlink_extensions' => array_values(array_unique($data['extensions'])),
            'hotlink_allowed_referrers' => array_values(array_unique($data['allowed_referrers'])),
        ];
        $previous = $site->webSettings?->getAttributes();
        $settings = $site->webSettings()->updateOrCreate([], $values);
        $site->unsetRelation('webSettings');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $previous === null ? $settings->delete() : $settings->forceFill($previous)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Protección Hotlink actualizada.');
    }
}
