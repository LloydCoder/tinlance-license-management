<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status !== 'active') {
            abort(403);
        }

        $isSuperAdmin = $user->vendorMemberships()
            ->where('status', 'active')
            ->whereHas('vendorAccount', static fn ($query) => $query->where('status', 'active'))
            ->whereHas('roles', static fn ($query) => $query
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin'))
            ->exists();

        abort_unless($isSuperAdmin, 403);

        return $next($request);
    }
}
