<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseXMailSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('xmail') && ! $request->is('xmail/*')) {
            return $next($request);
        }

        $original = [
            'cookie' => config('session.cookie'),
            'path' => config('session.path'),
            'same_site' => config('session.same_site'),
        ];

        config()->set([
            'session.cookie' => 'xpanel_xmail_session',
            'session.path' => '/xmail',
            'session.same_site' => 'strict',
        ]);

        try {
            return $next($request);
        } finally {
            config()->set([
                'session.cookie' => $original['cookie'],
                'session.path' => $original['path'],
                'session.same_site' => $original['same_site'],
            ]);
        }
    }
}
