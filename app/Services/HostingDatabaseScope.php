<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

class HostingDatabaseScope
{
    public function root(Site $site): Site
    {
        return $site->parent_site_id === null ? $site : $site->parent()->firstOrFail();
    }

    /** @return Collection<int, Site> */
    public function sites(Site $site): Collection
    {
        $root = $this->root($site);

        return Site::query()->whereKey($root->id)
            ->orWhere('parent_site_id', $root->id)
            ->orderBy('domain')->get();
    }

    public function selected(Site $root, int $siteId): Site
    {
        $site = $this->sites($root)->firstWhere('id', $siteId);
        abort_unless($site !== null, 404);

        return $site;
    }

    public function contains(Site $root, Site $candidate): bool
    {
        return $candidate->id === $root->id || $candidate->parent_site_id === $root->id;
    }
}
