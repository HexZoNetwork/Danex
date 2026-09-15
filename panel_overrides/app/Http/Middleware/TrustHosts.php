<?php

namespace Pterodactyl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustHosts
{
    /**
     * Allowed hostnames for this panel instance.
     * Populated from TRUSTED_HOSTS env var (comma-separated) or falls back
     * to the panel app URL domain.
     *
     * @var list<string>
     */
    private array $allowedHosts;

    public function __construct()
    {
        $envHosts = env('TRUSTED_HOSTS', '');
        if ($envHosts !== '') {
            $this->allowedHosts = array_map('trim', explode(',', $envHosts));
        } else {
            $this->allowedHosts = [parse_url(config('app.url'), PHP_URL_HOST) ?? ''];
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if (!in_array($host, $this->allowedHosts, true)) {
            abort(400, 'Invalid host header.');
        }

        return $next($request);
    }
}
