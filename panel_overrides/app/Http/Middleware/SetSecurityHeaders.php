<?php

namespace Pterodactyl\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SetSecurityHeaders
{
    /**
     * Ideally we move away from X-Frame-Options/X-XSS-Protection and implement a
     * proper standard CSP, but I can guarantee that will break for a lot of folks
     * using custom plugins and who knows what image embeds.
     *
     * We'll circle back to that at a later date when it can be more fully controlled
     * by the admin to support those cases without too much trouble.
     */
    private static array $headers = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(self), payment=(), usb=(), interest-cohort=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-site',
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ];

    /**
     * Enforces some basic security headers on all responses returned by the software.
     * If a header has already been set in another location within the code it will be
     * skipped over here.
     *
     * @param (\Closure(mixed): \Illuminate\Http\Response) $next
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        $response = $next($request);
        $imageSources = [
            "'self'",
            'data:',
            'blob:',
            'https://www.gravatar.com',
            'https://gravatar.com',
            'https://files.catbox.moe',
            'https://www.google.com',
            'https://www.gstatic.com',
            'https://recaptcha.net',
        ];
        $connectSources = [
            "'self'",
            'wss://nodes.el7.web.id:8080',
            'https://www.google.com',
            'https://www.gstatic.com',
            'https://recaptcha.net',
        ];

        foreach (array_filter([config('app.url'), $request->getSchemeAndHttpHost()]) as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
            if ($host) {
                $imageSources[] = sprintf('%s://%s', $scheme, $host);
                $connectSources[] = sprintf('%s://%s', $scheme, $host);
                if ($scheme !== 'http') {
                    $connectSources[] = sprintf('wss://%s', $host);
                }
            }
        }

        // Keep legacy absolute avatar URLs renderable after a panel domain migration.
        foreach (['hecker.apcb.biz.id', 'hecker.el7.web.id'] as $host) {
            $imageSources[] = 'https://' . $host;
        }

        $imageSources = array_values(array_unique(array_filter($imageSources, static fn ($source) => Str::of($source)->trim()->isNotEmpty())));
        $connectSources = array_values(array_unique(array_filter($connectSources, static fn ($source) => Str::of($source)->trim()->isNotEmpty())));

        $csp = implode('; ', [
            "default-src 'self'",
            // Existing templates still use inline scripts/styles. Keep functionality while blocking eval and broad third-party injection.
            "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com https://recaptcha.net",
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com",
            'img-src ' . implode(' ', $imageSources),
            "font-src 'self' data: https://cdnjs.cloudflare.com",
            'connect-src ' . implode(' ', $connectSources),
            "frame-src 'self' https://www.google.com https://www.gstatic.com https://recaptcha.net",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        // Legacy fallback for older browsers.
        if (! $response->headers->has('X-Content-Security-Policy')) {
            $response->headers->set('X-Content-Security-Policy', $csp);
        }

        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        foreach (static::$headers as $key => $value) {
            if (! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
