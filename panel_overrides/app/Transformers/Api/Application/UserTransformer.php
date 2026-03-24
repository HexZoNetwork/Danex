<?php

namespace Pterodactyl\Transformers\Api\Application;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\NullResource;
use Pterodactyl\Services\Acl\Api\AdminAcl;

class UserTransformer extends BaseTransformer
{
    /**
     * List of resources that can be included.
     */
    protected array $availableIncludes = ['servers'];

    /**
     * Return the resource name for the JSONAPI output.
     */
    public function getResourceName(): string
    {
        return User::RESOURCE_NAME;
    }

    /**
     * Return a transformed User model that can be consumed by external services.
     */
    public function transform(User $user): array
    {
        $birthday = $user->birthday;
        if ($birthday instanceof \DateTimeInterface) {
            $birthday = $birthday->format('Y-m-d');
        } elseif (empty($birthday) && $user->created_at !== null) {
            $birthday = $user->created_at->toDateString();
        }

        return [
            'id' => $user->id,
            'external_id' => $user->external_id,
            'uuid' => $user->uuid,
            'username' => $user->username,
            'email' => $user->email,
            'first_name' => $user->name_first,
            'last_name' => $user->name_last,
            'language' => $user->language,
            'root_admin' => (bool) $user->root_admin,
            '2fa' => (bool) $user->use_totp,
            'avatar_url' => !empty($user->avatar_url)
                ? (string) $user->avatar_url
                : ('https://gravatar.com/avatar/' . md5(Str::lower($user->email))),
            'birthday' => $birthday,
            'created_at' => $this->formatTimestamp($user->created_at),
            'updated_at' => $this->formatTimestamp($user->updated_at),
        ];
    }

    /**
     * Return the servers associated with this user.
     *
     * @throws \Pterodactyl\Exceptions\Transformer\InvalidTransformerLevelException
     */
    public function includeServers(User $user): Collection|NullResource
    {
        if (!$this->authorize(AdminAcl::RESOURCE_SERVERS)) {
            return $this->null();
        }

        $user->loadMissing('servers');

        return $this->collection($user->getRelation('servers'), $this->makeTransformer(ServerTransformer::class), 'server');
    }
}
