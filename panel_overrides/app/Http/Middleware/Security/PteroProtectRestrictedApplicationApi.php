<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\Response;

class PteroProtectRestrictedApplicationApi
{
    public function __construct(private AdminOwnershipService $ownership)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (!$user || !$user->root_admin) {
            throw new AccessDeniedHttpException('Admin API access is required.');
        }
        if ((int) $user->id === 1) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        if (preg_match('#^api/application/(users|servers)(?:/|$)#i', $path) !== 1) {
            // delegated admin: read-only infra endpoints for create-server workflow.
            $isReadOnlyInfraRequest = $request->isMethod('get') && (
                preg_match('#^api/application/nodes(?:$|/|/deployable)#i', $path) === 1
                || preg_match('#^api/application/locations(?:$|/)#i', $path) === 1
                || preg_match('#^api/application/nests(?:$|/)#i', $path) === 1
            );

            if ($isReadOnlyInfraRequest) {
                return $this->sanitizeInfraResponse($path, $next($request));
            }

            throw new AccessDeniedHttpException('This API scope is disabled for delegated admin accounts.');
        }

        $tokenIdentifier = $this->tokenIdentifier($request);
        $adminUserId = (int) $user->id;

        if (preg_match('#^api/application/users(?:/|$)#i', $path) === 1) {
            $request->merge(['root_admin' => false]);

            if ($request->isMethod('get') && preg_match('#^api/application/users/?$#i', $path) === 1) {
                $request->attributes->set(
                    'pteroprotect_owned_user_ids',
                    $this->ownership->ownedIdsFor('users', $adminUserId, $tokenIdentifier)
                );
            }

            if ($request->isMethod('post') && preg_match('#^api/application/users/?$#i', $path) === 1) {
                return $next($request);
            }

            $targetUser = $this->resolveUserTarget($request, $path);
            if ($targetUser instanceof User) {
                if ((int) $targetUser->id === 1) {
                    throw new AccessDeniedHttpException('Primary admin account cannot be modified.');
                }
                if ((int) $targetUser->id === $adminUserId) {
                    return $next($request);
                }
                if (!$this->ownership->isOwnedBy('users', (int) $targetUser->id, $adminUserId, $tokenIdentifier)) {
                    throw new AccessDeniedHttpException('You do not own this user resource.');
                }
            }

            return $next($request);
        }

        // server scope
        if ($request->isMethod('get') && preg_match('#^api/application/servers/?$#i', $path) === 1) {
            $request->attributes->set(
                'pteroprotect_owned_server_ids',
                $this->ownership->ownedIdsFor('servers', $adminUserId, $tokenIdentifier)
            );
        }

        if ($request->isMethod('post') && preg_match('#^api/application/servers/?$#i', $path) === 1) {
            $ownerId = (int) $request->input('user', 0);
            if ($ownerId === 1) {
                throw new AccessDeniedHttpException('Cannot create or modify resources owned by primary admin.');
            }
            if ($ownerId <= 0) {
                throw new AccessDeniedHttpException('Invalid server owner.');
            }
            $allowedOwnerIds = $this->ownership->ownedIdsFor('users', $adminUserId, $tokenIdentifier);
            $allowedOwnerIds[] = $adminUserId;
            $allowedOwnerIds = array_values(array_unique(array_map('intval', $allowedOwnerIds)));
            if (!in_array($ownerId, $allowedOwnerIds, true)) {
                throw new AccessDeniedHttpException('You can only assign server owner to your own managed users.');
            }

            return $next($request);
        }

        $targetServer = $this->resolveServerTarget($request, $path);
        if ($targetServer instanceof Server) {
            if ((int) $targetServer->owner_id === 1) {
                throw new AccessDeniedHttpException('Primary admin resources cannot be modified.');
            }
            if (!$this->ownership->isOwnedBy('servers', (int) $targetServer->id, $adminUserId, $tokenIdentifier)) {
                throw new AccessDeniedHttpException('You do not own this server resource.');
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

        if (is_numeric($routeValue)) {
            return User::query()->find((int) $routeValue);
        }

        if (preg_match('#^api/application/users/external/([^/]+)$#i', $path, $matches) === 1) {
            return User::query()->where('external_id', $matches[1])->first();
        }

        if (preg_match('#^api/application/users/(\d+)(?:/|$)#i', $path, $matches) === 1) {
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

        if (is_numeric($routeValue)) {
            return Server::query()->find((int) $routeValue);
        }

        if (preg_match('#^api/application/servers/external/([^/]+)$#i', $path, $matches) === 1) {
            return Server::query()->where('external_id', $matches[1])->first();
        }

        if (preg_match('#^api/application/servers/(\d+)(?:/|$)#i', $path, $matches) === 1) {
            return Server::query()->find((int) $matches[1]);
        }

        return null;
    }

    private function tokenIdentifier(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();
        if (!is_object($token) || !property_exists($token, 'identifier')) {
            return null;
        }

        $identifier = trim((string) $token->identifier);

        return $identifier === '' ? null : $identifier;
    }

    private function sanitizeInfraResponse(string $path, mixed $response): mixed
    {
        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (!is_array($payload) || !array_key_exists('data', $payload)) {
            return $response;
        }

        $resource = $this->infraResourceName($path);
        if ($resource === null) {
            return $response;
        }

        $payload['data'] = $this->mapInfraDataToIdOnly($payload['data'], $resource);

        return new JsonResponse(
            $payload,
            $response->getStatusCode(),
            $response->headers->all(),
            $response->hasEncodingOption(JSON_PRETTY_PRINT)
        );
    }

    private function infraResourceName(string $path): ?string
    {
        if (preg_match('#^api/application/nodes(?:/|$)#i', $path) === 1) {
            return 'nodes';
        }
        if (preg_match('#^api/application/locations(?:/|$)#i', $path) === 1) {
            return 'locations';
        }
        if (preg_match('#^api/application/nests(?:/|$)#i', $path) === 1) {
            return preg_match('#/eggs(?:/|$)#i', $path) === 1 ? 'eggs' : 'nests';
        }

        return null;
    }

    private function mapInfraDataToIdOnly(mixed $data, string $resource): mixed
    {
        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): ?array => $this->toIdOnlyItem($item, $resource),
                $data
            )));
        }

        return $this->toIdOnlyItem($data, $resource) ?? $data;
    }

    private function toIdOnlyItem(mixed $item, string $resource): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $id = null;
        if (array_key_exists('attributes', $item) && is_array($item['attributes'])) {
            $id = $item['attributes']['id'] ?? null;
        }
        if ($id === null && array_key_exists('id', $item)) {
            $id = $item['id'];
        }

        if (!is_numeric($id)) {
            return null;
        }

        return [
            'object' => $resource,
            'attributes' => [
                'id' => (int) $id,
            ],
        ];
    }
}
