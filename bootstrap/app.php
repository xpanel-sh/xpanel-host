<?php

use App\Http\Middleware\AuditMutations;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSetupComplete;
use App\Http\Middleware\EnsureXMailAuthenticated;
use App\Http\Middleware\UseXMailSession;
use App\Support\InstanceContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            prepend: [UseXMailSession::class],
            append: [AuditMutations::class],
        );
        // sshd's forced-command gate has no browser session/CSRF token. This
        // route is instead gated by an opaque one-use token and loopback IP.
        $middleware->validateCsrfTokens(except: ['internal/terminal/consume', 'internal/terminal/runtime/start', 'xflow/hooks/*']);
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'setup.complete' => EnsureSetupComplete::class,
            'xmail.auth' => EnsureXMailAuthenticated::class,
        ]);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // No route in this app is actually prefixed with "api/" (the AJAX
        // endpoints all live under paths like sites/{site}/files/manager/api/...),
        // so `$request->is('api/*')` never matched anything — every abort()/
        // validation error on those routes rendered as an HTML error page
        // instead of JSON, even though the frontend's api() helper always
        // sends Accept: application/json. wantsJson() checks that directly.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->wantsJson(),
        );
    })->create();

if ($instanceStoragePath = InstanceContext::storagePathFromEnvironment()) {
    $application->useStoragePath($instanceStoragePath);
}

return $application;
