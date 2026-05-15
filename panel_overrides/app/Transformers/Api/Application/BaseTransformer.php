<?php

namespace Pterodactyl\Transformers\Api\Application;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;
use Pterodactyl\Models\ApiKey;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use League\Fractal\TransformerAbstract;
use Pterodactyl\Services\Acl\Api\AdminAcl;

/**
 * @method array transform(Model $model)
 */
abstract class BaseTransformer extends TransformerAbstract
{
    public const RESPONSE_TIMEZONE = 'UTC';

    protected Request $request;

    /**
     * BaseTransformer constructor.
     */
    public function __construct()
    {
        // Transformers allow for dependency injection on the handle method.
        if (method_exists($this, 'handle')) {
            Container::getInstance()->call([$this, 'handle']);
        }
    }

    /**
     * Return the resource name for the JSONAPI output.
     */
    abstract public function getResourceName(): string;

    /**
     * Sets the request on the instance.
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Returns a new transformer instance with the request set on the instance.
     */
    public static function fromRequest(Request $request): static
    {
        return app(static::class)->setRequest($request);
    }

    /**
     * Determine if the API key loaded onto the transformer has permission
     * to access a different resource. This is used when including other
     * models on a transformation request.
     *
     * @deprecated — prefer $user->can/cannot methods
     */
    protected function authorize(string $resource): bool
    {
        $allowed = [ApiKey::TYPE_ACCOUNT, ApiKey::TYPE_APPLICATION];

        $token = $this->request->user()?->currentAccessToken();
        if (!$token instanceof ApiKey || !in_array($token->key_type, $allowed)) {
            return false;
        }

        if ($this->isDelegatedApplicationRelationshipAllowed($resource, $token)) {
            return true;
        }

        // If this is not a deprecated application token type we can only check that
        // the user is a root admin at the moment. In a future release we'll be rolling
        // out more specific permissions for keys.
        if ($token->key_type === ApiKey::TYPE_ACCOUNT) {
            return $this->request->user()->root_admin;
        }

        return AdminAcl::check($token, $resource);
    }

    private function isDelegatedApplicationRelationshipAllowed(string $resource, ApiKey $token): bool
    {
        $user = $this->request->user();
        if (!$user || !$user->root_admin || (int) $user->id === 1 || $token->key_type !== ApiKey::TYPE_APPLICATION) {
            return false;
        }

        $path = ltrim($this->request->path(), '/');
        if (preg_match('#^api/application/(users|servers)(?:/|$)#i', $path) === 1) {
            return in_array($resource, [
                AdminAcl::RESOURCE_USERS,
                AdminAcl::RESOURCE_SERVERS,
                AdminAcl::RESOURCE_ALLOCATIONS,
                AdminAcl::RESOURCE_LOCATIONS,
                AdminAcl::RESOURCE_NODES,
                AdminAcl::RESOURCE_NESTS,
                AdminAcl::RESOURCE_EGGS,
            ], true);
        }

        if (!$this->request->isMethod('get')) {
            return false;
        }

        if (preg_match('#^api/application/nests(?:/|$)#i', $path) === 1) {
            return in_array($resource, [
                AdminAcl::RESOURCE_NESTS,
                AdminAcl::RESOURCE_EGGS,
            ], true);
        }

        if (preg_match('#^api/application/nodes(?:/|$)#i', $path) === 1) {
            return in_array($resource, [
                AdminAcl::RESOURCE_NODES,
                AdminAcl::RESOURCE_LOCATIONS,
            ], true);
        }

        if (preg_match('#^api/application/locations(?:/|$)#i', $path) === 1) {
            return in_array($resource, [
                AdminAcl::RESOURCE_LOCATIONS,
                AdminAcl::RESOURCE_NODES,
            ], true);
        }

        return false;
    }

    /**
     * Create a new instance of the transformer and pass along the currently
     * set API key.
     *
     * @template T of \Pterodactyl\Transformers\Api\Application\BaseTransformer
     *
     * @param class-string<T> $abstract
     *
     * @return T
     *
     * @throws \Pterodactyl\Exceptions\Transformer\InvalidTransformerLevelException
     *
     * @noinspection PhpDocSignatureInspection
     */
    protected function makeTransformer(string $abstract)
    {
        Assert::subclassOf($abstract, self::class); // @phpstan-ignore staticMethod.alreadyNarrowedType

        return $abstract::fromRequest($this->request);
    }

    /**
     * Return an ISO-8601 formatted timestamp to use in the API response.
     */
    protected function formatTimestamp(string $timestamp): string
    {
        return CarbonImmutable::createFromFormat(CarbonInterface::DEFAULT_TO_STRING_FORMAT, $timestamp)
            ->setTimezone(self::RESPONSE_TIMEZONE)
            ->toAtomString();
    }
}
