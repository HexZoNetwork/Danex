<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Carbon\Carbon;
use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Transformers\Api\Client\StatsTransformer;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\GetServerRequest;

class ResourceUtilizationController extends ClientApiController
{
    /**
     * ResourceUtilizationController constructor.
     */
    public function __construct(private Repository $cache, private DaemonServerRepository $repository)
    {
        parent::__construct();
    }

    /**
     * Return the current resource utilization for a server. This value is cached for up to
     * 20 seconds at a time to ensure that repeated requests to this endpoint do not cause
     * a flood of unnecessary API calls.
     */
    public function __invoke(GetServerRequest $request, Server $server): array
    {
        $key = "resources:$server->uuid";
        try {
            $stats = $this->cache->remember($key, Carbon::now()->addSeconds(20), function () use ($server) {
                return $this->repository->setServer($server)->getDetails();
            });
        } catch (Throwable $exception) {
            Log::warning('Failed to fetch daemon stats, returning offline fallback.', [
                'server_id' => $server->id,
                'server_uuid' => $server->uuid,
                'error' => $exception->getMessage(),
            ]);

            $stats = [
                'state' => 'offline',
                'is_suspended' => (string) $server->status === 'suspended',
                'utilization' => [
                    'memory_bytes' => 0,
                    'cpu_absolute' => 0,
                    'disk_bytes' => 0,
                    'network' => [
                        'rx_bytes' => 0,
                        'tx_bytes' => 0,
                    ],
                    'uptime' => 0,
                ],
            ];

            $this->cache->put($key, $stats, Carbon::now()->addSeconds(5));
        }

        return $this->fractal->item($stats)
            ->transformWith($this->getTransformer(StatsTransformer::class))
            ->toArray();
    }
}
