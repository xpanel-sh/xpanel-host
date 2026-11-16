<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Http\Request;

class SiteSwitchUrl
{
    public static function for(Site $target, Request $request): string
    {
        $route = $request->route();
        $name = $route?->getName();

        if ($request->isMethod('GET')
            && is_string($name)
            && str_starts_with($name, 'sites.')
            && str_starts_with($route->uri(), 'sites/{site}')
            && ! str_contains($route->uri(), '/api/')) {
            $parameters = $route->parameters();
            $allowed = ['site', 'section', 'module'];

            // Pages tied to a particular subdomain, workflow or other record
            // cannot safely be carried over to another primary site.
            if (array_diff(array_keys($parameters), $allowed) === []) {
                $parameters['site'] = $target;

                return route($name, $parameters);
            }
        }

        return route('sites.show', $target);
    }
}
