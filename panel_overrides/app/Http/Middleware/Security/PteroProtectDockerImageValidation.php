<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PteroProtectDockerImageValidation
{
    /**
     * Blocked Docker image patterns that indicate abuse or SSRF attempts.
     *
     * @var list<string>
     */
    private const BLOCKED_PATTERNS = [
        '/^https?:\/\//i',       // URLs as image names
        '/^\d+\.\d+\.\d+\.\d/', // Raw IP addresses
        '/^[a-z]+:\/\//i',       // Custom protocols
        '/\$\{/',                 // Variable interpolation
        '/`/',                    // Backtick injection
        '/\|/',                   // Pipe injection
        '/;/',                    // Semicolon injection
        '/&&/',                   // Logical AND
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $dockerImage = $request->input('docker_image')
            ?? $request->input('docker_image')
            ?? $request->input('json.docker_image');

        if (is_string($dockerImage) && $dockerImage !== '') {
            foreach (self::BLOCKED_PATTERNS as $pattern) {
                if (preg_match($pattern, $dockerImage)) {
                    if ($request->expectsJson()) {
                        return new JsonResponse([
                            'error' => 'Invalid Docker image name.',
                        ], 422);
                    }
                    abort(422, 'Invalid Docker image name.');
                }
            }

            // Enforce allowed registry prefixes
            $allowedPrefixes = ['docker.io/', 'ghcr.io/', 'quay.io/', 'registry.gitlab.com/'];
            $hasAllowedPrefix = false;
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with(strtolower($dockerImage), $prefix)) {
                    $hasAllowedPrefix = true;
                    break;
                }
            }

            // Allow short names like "node:18" or "pterodactyl/yolks:..." without explicit registry
            if (!$hasAllowedPrefix && !preg_match('/^[a-z0-9]+(?::[a-z0-9._-]+)?$/i', $dockerImage)) {
                if ($request->expectsJson()) {
                    return new JsonResponse([
                        'error' => 'Docker image must use an allowed registry or valid short name.',
                    ], 422);
                }
                abort(422, 'Docker image must use an allowed registry or valid short name.');
            }
        }

        return $next($request);
    }
}
