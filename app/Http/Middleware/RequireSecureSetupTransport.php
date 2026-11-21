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
        if (config('app.env') === 'production' && ! $request->isSecure()) {
            $response = redirect()->secure($request->getRequestUri(), 308);
        } else {
            $response = $next($request);
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
