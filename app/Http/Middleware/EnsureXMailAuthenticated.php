<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureXMailAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('xpanel.xmail_enabled'), 404);

        if (! $request->session()->has('xmail.account_id') || ! $request->session()->has('xmail.credential')) {
            if ($request->expectsJson() || $request->is('xmail/api/*')) {
                return response()->json(['message' => 'La sesión de XMail expiró.'], 401);
            }

            return redirect()->route('xmail.login');
        }

        return $next($request);
    }
}
