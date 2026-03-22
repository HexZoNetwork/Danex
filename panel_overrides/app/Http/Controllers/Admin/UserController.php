<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Contracts\Repository\UserRepositoryInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\NewUserFormRequest;
use Pterodactyl\Http\Requests\Admin\UserFormRequest;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Pterodactyl\Services\Users\UserCreationService;
use Pterodactyl\Services\Users\UserDeletionService;
use Pterodactyl\Services\Users\UserUpdateService;
use Pterodactyl\Traits\Helpers\AvailableLanguages;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserController extends Controller
{
    use AvailableLanguages;

    public function __construct(
        protected AlertsMessageBag $alert,
        protected UserCreationService $creationService,
        protected UserDeletionService $deletionService,
        protected Translator $translator,
        protected UserUpdateService $updateService,
        protected UserRepositoryInterface $repository,
        protected ViewFactory $view,
        protected AdminOwnershipService $ownership,
    ) {
    }

    public function index(Request $request): View
    {
        if ((int) $request->user()->id === 1) {
            $users = QueryBuilder::for(
                User::query()->select('users.*')
                    ->selectRaw('COUNT(DISTINCT(subusers.id)) as subuser_of_count')
                    ->selectRaw('COUNT(DISTINCT(servers.id)) as servers_count')
                    ->leftJoin('subusers', 'subusers.user_id', '=', 'users.id')
                    ->leftJoin('servers', 'servers.owner_id', '=', 'users.id')
                    ->groupBy('users.id')
            )
                ->allowedFilters(['username', 'email', 'uuid'])
                ->defaultSort('-root_admin')
                ->allowedSorts(['id', 'uuid'])
                ->paginate(50);

            return view('admin.users.index', ['users' => $users]);
        }

        $owned = $request->attributes->get('pteroprotect_owned_user_ids');
        if (!is_array($owned)) {
            $owned = $this->ownership->ownedIdsFor('users', (int) $request->user()->id);
        }

        $query = User::query()->select('users.*')
            ->selectRaw('COUNT(DISTINCT(subusers.id)) as subuser_of_count')
            ->selectRaw('COUNT(DISTINCT(servers.id)) as servers_count')
            ->leftJoin('subusers', 'subusers.user_id', '=', 'users.id')
            ->leftJoin('servers', 'servers.owner_id', '=', 'users.id')
            ->groupBy('users.id');

        if ($owned === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('users.id', $owned);
        }

        $users = QueryBuilder::for($query)
            ->allowedFilters(['username', 'email', 'uuid'])
            ->defaultSort('-id')
            ->allowedSorts(['id', 'uuid'])
            ->paginate(50);

        return view('admin.users.index', ['users' => $users]);
    }

    public function create(): View
    {
        return view('admin.users.new', [
            'languages' => $this->getAvailableLanguages(true),
        ]);
    }

    public function view(User $user): View
    {
        $this->denyIfNotOwned(request(), $user);

        return view('admin.users.view', [
            'user' => $user,
            'languages' => $this->getAvailableLanguages(true),
        ]);
    }

    public function delete(Request $request, User $user): RedirectResponse
    {
        $this->denyIfNotOwned($request, $user);

        if ($request->user()->is($user)) {
            throw new DisplayException(__('admin/user.exceptions.delete_self'));
        }

        $this->deletionService->handle($user);
        $this->ownership->forget('users', (int) $user->id);

        return redirect()->route('admin.users');
    }

    public function store(NewUserFormRequest $request): RedirectResponse
    {
        $data = $request->normalize();
        if ((int) $request->user()->id !== 1) {
            $data['root_admin'] = false;
        }

        $user = $this->creationService->handle($data);
        $this->ownership->remember('users', (int) $user->id, (int) $request->user()->id);

        $this->alert->success($this->translator->get('admin/user.notices.account_created'))->flash();

        return redirect()->route('admin.users.view', $user->id);
    }

    public function update(UserFormRequest $request, User $user): RedirectResponse
    {
        $this->denyIfNotOwned($request, $user);

        $data = $request->normalize();
        if ((int) $user->id === 1) {
            $data['root_admin'] = true;
        } elseif ((int) $request->user()->id !== 1) {
            $data['root_admin'] = false;
        }

        $this->updateService
            ->setUserLevel(User::USER_LEVEL_ADMIN)
            ->handle($user, $data);

        $this->alert->success(trans('admin/user.notices.account_updated'))->flash();

        return redirect()->route('admin.users.view', $user->id);
    }

    public function json(Request $request): Model|Collection
    {
        $owned = $request->attributes->get('pteroprotect_owned_user_ids');
        if (!is_array($owned)) {
            $owned = $this->ownership->ownedIdsFor('users', (int) $request->user()->id);
        }

        $query = User::query();
        if ($owned === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('id', $owned);
        }

        $users = QueryBuilder::for($query)->allowedFilters(['email'])->paginate(25);

        if ($request->query('user_id')) {
            $user = $query->findOrFail($request->input('user_id'));
            // @phpstan-ignore-next-line property.notFound
            $user->md5 = md5(strtolower($user->email));

            return $user;
        }

        return $users->map(function ($item) {
            // @phpstan-ignore-next-line property.notFound
            $item->md5 = md5(strtolower($item->email));

            return $item;
        });
    }

    private function denyIfNotOwned(Request $request, User $user): void
    {
        if ((int) $request->user()->id === 1) {
            return;
        }
        if ((int) $user->id === 1) {
            throw new AccessDeniedHttpException('Primary admin account cannot be modified.');
        }
        if (!$this->ownership->isOwnedBy('users', (int) $user->id, (int) $request->user()->id)) {
            throw new AccessDeniedHttpException('You do not own this user resource.');
        }
    }
}
