<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
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

        $server = $request->route('server');
        if ($server instanceof Server && (int) $server->owner_id === (int) $user->id) {
            return $next($request);
        }

        // Delegated admin accounts may use their own servers, but not runtime
        // resources that belong to other accounts.
        throw new AccessDeniedHttpException('Delegated admin cannot access server runtime resources.');
    }
}
