<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Pterodactyl\Models\ActivityLog;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Transformers\Api\Client\ActivityLogTransformer;

class ActivityLogController extends ClientApiController
{
    /**
     * Returns a paginated set of the user's activity logs.
     */
    public function __invoke(ClientApiRequest $request): array
    {
        $activity = QueryBuilder::for($request->user()->activity())
            ->with('actor')
            ->allowedFilters([AllowedFilter::partial('event')])
            ->allowedSorts(['timestamp'])
            ->whereNotIn('activity_logs.event', ActivityLog::DISABLED_EVENTS)
            ->where(function (Builder $query) {
                $query->whereNull('activity_logs.actor_id')
                    ->orWhere('activity_logs.actor_id', '!=', 1);
            })
            ->where(function (Builder $query) {
                $query->where('activity_logs.event', '!=', 'server:file.read')
                    ->orWhere('activity_logs.properties', 'not like', '%admins.json%');
            })
            ->paginate(min($request->query('per_page', 25), 100))
            ->appends($request->query());

        return $this->fractal->collection($activity)
            ->transformWith($this->getTransformer(ActivityLogTransformer::class))
            ->toArray();
    }
}
