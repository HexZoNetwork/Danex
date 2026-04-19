<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Users;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\User;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Pterodactyl\Services\Users\UserCreationService;
use Pterodactyl\Services\Users\UserDeletionService;
use Pterodactyl\Services\Users\UserUpdateService;
use Pterodactyl\Transformers\Api\Application\UserTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Pterodactyl\Http\Requests\Api\Application\Users\DeleteUserRequest;
use Pterodactyl\Http\Requests\Api\Application\Users\GetUsersRequest;
use Pterodactyl\Http\Requests\Api\Application\Users\StoreUserRequest;
use Pterodactyl\Http\Requests\Api\Application\Users\UpdateUserRequest;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserController extends ApplicationApiController
{
    public function __construct(
        private UserCreationService $creationService,
        private UserDeletionService $deletionService,
        private UserUpdateService $updateService,
        private AdminOwnershipService $ownership,
    ) {
        parent::__construct();
    }

    public function index(GetUsersRequest $request): array
    {
        if ((int) $request->user()->id === 1) {
            $users = QueryBuilder::for(User::query())
                ->allowedFilters(['email', 'uuid', 'username', 'external_id'])
                ->allowedSorts(['id', 'uuid'])
                ->paginate($request->query('per_page') ?? 50);

            return $this->fractal->collection($users)
                ->transformWith($this->getTransformer(UserTransformer::class))
                ->toArray();
        }

        $owned = $request->attributes->get('pteroprotect_owned_user_ids');
        if (!is_array($owned)) {
            $owned = $this->ownership->ownedIdsFor('users', (int) $request->user()->id, $this->tokenIdentifier($request));
        }

        $query = User::query();
        if ($owned === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('id', $owned);
        }

        $users = QueryBuilder::for($query)
            ->allowedFilters(['email', 'uuid', 'username', 'external_id'])
            ->allowedSorts(['id', 'uuid'])
            ->paginate($request->query('per_page') ?? 50);

        return $this->fractal->collection($users)
            ->transformWith($this->getTransformer(UserTransformer::class))
            ->toArray();
    }

    public function view(GetUsersRequest $request, User $user): array
    {
        $this->denyIfNotOwned($request, $user);

        return $this->fractal->item($user)
            ->transformWith($this->getTransformer(UserTransformer::class))
            ->toArray();
    }

    public function update(UpdateUserRequest $request, User $user): array
    {
        $this->denyIfNotOwned($request, $user);

        $data = $request->validated();
        if ((int) $user->id === 1) {
            $data['root_admin'] = true;
        } elseif ((int) $request->user()->id !== 1) {
            $data['root_admin'] = false;
        }

        $this->updateService->setUserLevel(User::USER_LEVEL_ADMIN);
        $user = $this->updateService->handle($user, $data);

        $response = $this->fractal->item($user)
            ->transformWith($this->getTransformer(UserTransformer::class));

        return $response->toArray();
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ((int) $request->user()->id !== 1) {
            $data['root_admin'] = false;
        }

        $user = $this->creationService->handle($data);
        $this->ownership->remember('users', (int) $user->id, (int) $request->user()->id, $this->tokenIdentifier($request));

        return $this->fractal->item($user)
            ->transformWith($this->getTransformer(UserTransformer::class))
            ->addMeta([
                'resource' => route('api.application.users.view', [
                    'user' => $user->id,
                ]),
            ])
            ->respond(201);
    }

    public function delete(DeleteUserRequest $request, User $user): JsonResponse
    {
        $this->denyIfNotOwned($request, $user);
        if (Schema::hasColumn('users', 'name_last') && strtolower(trim((string) $user->name_last)) === 'madeinweb') {
            return new JsonResponse([
                'error' => 'User madeinweb diproteksi dan tidak bisa dihapus.',
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        $this->deletionService->handle($user);
        $this->ownership->forget('users', (int) $user->id);

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    private function tokenIdentifier(GetUsersRequest|StoreUserRequest|UpdateUserRequest|DeleteUserRequest $request): ?string
    {
        $token = $request->user()?->currentAccessToken();
        if (!is_object($token) || !property_exists($token, 'identifier')) {
            return null;
        }

        $identifier = trim((string) $token->identifier);

        return $identifier === '' ? null : $identifier;
    }

    private function denyIfNotOwned(GetUsersRequest|UpdateUserRequest|DeleteUserRequest $request, User $user): void
    {
        if ((int) $request->user()->id === 1) {
            return;
        }
        if ((int) $user->id === 1) {
            throw new AccessDeniedHttpException('Primary admin account cannot be modified.');
        }
        if ((int) $request->user()->id === (int) $user->id) {
            return;
        }
        if (!$this->ownership->isOwnedBy('users', (int) $user->id, (int) $request->user()->id, $this->tokenIdentifier($request))) {
            throw new AccessDeniedHttpException('You do not own this user resource.');
        }
    }
}
