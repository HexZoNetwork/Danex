<?php

namespace Pterodactyl\Http\Controllers\Admin\Servers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\ServerFormRequest;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Node;
use Pterodactyl\Repositories\Eloquent\NestRepository;
use Pterodactyl\Repositories\Eloquent\NodeRepository;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Pterodactyl\Services\Servers\ServerCreationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CreateServerController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private NestRepository $nestRepository,
        private NodeRepository $nodeRepository,
        private ServerCreationService $creationService,
        private AdminOwnershipService $ownership,
    ) {
    }

    public function index(): View|RedirectResponse
    {
        $nodes = Node::all();
        if (count($nodes) < 1) {
            $this->alert->warning(trans('admin/server.alerts.node_required'))->flash();

            return redirect()->route('admin.servers');
        }

        $nests = $this->nestRepository->getWithEggs();

        \JavaScript::put([
            'nodeData' => $this->nodeRepository->getNodesForServerCreation(),
            'nests' => $nests->map(function (Nest $item) {
                return array_merge($item->toArray(), [
                    'eggs' => $item->eggs->keyBy('id')->toArray(),
                ]);
            })->keyBy('id'),
        ]);

        return view('admin.servers.new', [
            'locations' => Location::all(),
            'nests' => $nests,
        ]);
    }

    public function store(ServerFormRequest $request): RedirectResponse
    {
        $ownerId = (int) $request->input('owner_id', 0);
        if ($ownerId <= 0) {
            throw new AccessDeniedHttpException('Invalid server owner.');
        }
        if ((int) $request->user()->id !== 1 && $ownerId === 1) {
            throw new AccessDeniedHttpException('Cannot create or modify resources owned by primary admin.');
        }
        $data = $request->except(['_token']);
        if (!empty($data['custom_image'])) {
            $data['image'] = $data['custom_image'];
            unset($data['custom_image']);
        }

        $server = $this->creationService->handle($data);
        $this->ownership->remember('servers', (int) $server->id, (int) $request->user()->id);

        $this->alert->success(trans('admin/server.alerts.server_created'))->flash();

        return new RedirectResponse('/admin/servers/view/' . $server->id);
    }
}
