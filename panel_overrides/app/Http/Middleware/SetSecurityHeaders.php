<?php

namespace Pterodactyl\Http\Middleware;

use Illuminate\Http\Request;

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
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=(), interest-cohort=()',
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

        $csp = implode('; ', [
            "default-src 'self'",
            // Existing templates still use inline scripts/styles. Keep functionality while blocking third-party script injection.
            "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com https://recaptcha.net",
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com",
            "img-src 'self' data: blob: https://www.gravatar.com https://files.catbox.moe https://www.google.com https://www.gstatic.com https://recaptcha.net",
            "font-src 'self' data: https://cdnjs.cloudflare.com",
            "connect-src 'self' wss: https:",
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
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        foreach (static::$headers as $key => $value) {
            if (! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
