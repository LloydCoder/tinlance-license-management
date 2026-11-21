<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireSecureSetupTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        $requiresHttps = ! in_array(config('app.env'), ['local', 'testing'], true);

        if ($requiresHttps && ! $request->isSecure()) {
            $response = in_array($request->method(), ['GET', 'HEAD'], true)
                ? redirect()->secure($request->getRequestUri(), 308)
                : response('HTTPS is required for this endpoint.', 426)->header('Upgrade', 'TLS/1.2');
        } else {
            $response = $next($request);
        }

        if ($requiresHttps && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

        return $response;
    }
}
