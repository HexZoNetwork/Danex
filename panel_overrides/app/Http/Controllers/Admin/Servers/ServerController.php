<?php

namespace Pterodactyl\Http\Controllers\Admin\Servers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Filters\AdminServerFilter;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ServerController extends Controller
{
    public function __construct(private AdminOwnershipService $ownership)
    {
    }

    public function index(Request $request): View
    {
        if ((int) $request->user()->id === 1) {
            $servers = QueryBuilder::for(Server::query()->with('node', 'user', 'allocation'))
                ->allowedFilters([
                    AllowedFilter::exact('owner_id'),
                    AllowedFilter::custom('*', new AdminServerFilter()),
                ])
                ->paginate(config()->get('pterodactyl.paginate.admin.servers'));

            return view('admin.servers.index', ['servers' => $servers]);
        }

        $owned = $request->attributes->get('pteroprotect_owned_server_ids');
        if (!is_array($owned)) {
            $owned = $this->ownership->ownedIdsFor('servers', (int) $request->user()->id);
        }
        $ownedUsers = $this->ownership->ownedIdsFor('users', (int) $request->user()->id);
        $ownedUsers[] = (int) $request->user()->id;
        $ownedUsers = array_values(array_unique(array_map('intval', $ownedUsers)));

        $query = Server::query()->with('node', 'user', 'allocation');
        $query->where(function ($builder) use ($owned, $ownedUsers) {
            if ($owned !== []) {
                $builder->whereIn('id', $owned);
            }

            if ($ownedUsers !== []) {
                $method = $owned !== [] ? 'orWhereIn' : 'whereIn';
                $builder->{$method}('owner_id', $ownedUsers);
            }

            if ($owned === [] && $ownedUsers === []) {
                $builder->whereRaw('1 = 0');
            }
        });

        $servers = QueryBuilder::for($query)
            ->allowedFilters([
                AllowedFilter::exact('owner_id'),
                AllowedFilter::custom('*', new AdminServerFilter()),
            ])
            ->paginate(config()->get('pterodactyl.paginate.admin.servers'));

        return view('admin.servers.index', ['servers' => $servers]);
    }
}
