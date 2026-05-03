<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Models\ApiKey;
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
        $token = $user->currentAccessToken();
        if ($token instanceof ApiKey && $token->key_type === ApiKey::TYPE_ACCOUNT) {
            if ($server instanceof Server && (int) $server->owner_id === (int) $user->id) {
                return $next($request);
            }

            throw new AccessDeniedHttpException('Client API keys can only access servers owned by the token owner.');
        }

        if ($server instanceof Server && (int) $server->owner_id === (int) $user->id) {
            return $next($request);
        }

        // Delegated admin accounts may use their own servers, but not runtime
        // resources that belong to other accounts.
        throw new AccessDeniedHttpException('Delegated admin cannot access server runtime resources.');
    }
}
