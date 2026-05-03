<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PteroProtectRestrictedAdmin
{
    public function __construct(private AdminOwnershipService $ownership)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (!$user || !$user->root_admin) {
            throw new AccessDeniedHttpException('Admin session is required.');
        }
        if ((int) $user->id === 1) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        $allowed = $path === 'admin'
            || str_starts_with($path, 'admin/api')
            || str_starts_with($path, 'admin/users')
            || str_starts_with($path, 'admin/servers');

        if (!$allowed) {
            throw new AccessDeniedHttpException('This admin area is disabled for delegated admin accounts.');
        }

        if (preg_match('#^admin/users(?:/|$)#i', $path) === 1) {
            $request->merge(['root_admin' => false]);

            if ($request->isMethod('get') && preg_match('#^admin/users(?:/accounts\.json)?$#i', $path) === 1) {
                if ($request->query('scope') !== 'server_owner') {
                    $request->attributes->set('pteroprotect_owned_user_ids', $this->ownership->ownedIdsFor('users', (int) $user->id));
                }
            }

            $targetUser = $this->resolveUserTarget($request, $path);
            if ($targetUser instanceof User) {
                if ((int) $targetUser->id === 1) {
                    throw new AccessDeniedHttpException('Primary admin account cannot be modified.');
                }
                if ((int) $targetUser->id === (int) $user->id) {
                    return $next($request);
                }
                if (!$this->ownership->isOwnedBy('users', (int) $targetUser->id, (int) $user->id)) {
                    throw new AccessDeniedHttpException('You do not own this user resource.');
                }
            }
        }

        if (preg_match('#^admin/servers(?:/|$)#i', $path) === 1) {
            if ($request->isMethod('get') && $path === 'admin/servers') {
                $request->attributes->set('pteroprotect_owned_server_ids', $this->ownership->ownedIdsFor('servers', (int) $user->id));
            }

            if ($request->isMethod('post') && $path === 'admin/servers/new') {
                $ownerId = (int) $request->input('owner_id', 0);
                if ($ownerId === 1) {
                    throw new AccessDeniedHttpException('Cannot create or modify resources owned by primary admin.');
                }
                if ($ownerId <= 0) {
                    throw new AccessDeniedHttpException('Invalid server owner.');
                }
            }

            $targetServer = $this->resolveServerTarget($request, $path);
            if ($targetServer instanceof Server) {
                if ((int) $targetServer->owner_id === 1) {
                    throw new AccessDeniedHttpException('Primary admin resources cannot be modified.');
                }
                $ownsServer = $this->ownership->isOwnedBy('servers', (int) $targetServer->id, (int) $user->id);
                if (!$ownsServer) {
                    throw new AccessDeniedHttpException('You do not own this server resource.');
                }
            }
        }

        return $next($request);
    }

    private function resolveUserTarget(Request $request, string $path): ?User
    {
        $routeValue = $request->route('user');
        if ($routeValue instanceof User) {
            return $routeValue;
        }

        if (preg_match('#^admin/users/view/(\d+)$#i', $path, $matches) === 1) {
            return User::query()->find((int) $matches[1]);
        }

        return null;
    }

    private function resolveServerTarget(Request $request, string $path): ?Server
    {
        $routeValue = $request->route('server');
        if ($routeValue instanceof Server) {
            return $routeValue;
        }

        if (preg_match('#^admin/servers/view/(\d+)(?:/|$)#i', $path, $matches) === 1) {
            return Server::query()->find((int) $matches[1]);
        }

        return null;
    }
}
