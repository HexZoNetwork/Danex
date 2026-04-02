<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PteroProtectRestrictedClientServerAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (!$user || !$user->root_admin) {
            return $next($request);
        }

        // Primary admin keeps full capability.
        if ((int) $user->id === 1) {
            return $next($request);
        }

        // Delegated admin accounts are management-only. Block direct server resource access
        // through client API (console, files, resources, network, startup, settings, etc.).
        throw new AccessDeniedHttpException('Delegated admin cannot access server runtime resources.');
    }
}

