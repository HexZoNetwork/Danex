<?php

namespace Pterodactyl\Http;

use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Middleware\TrustProxies;
use Pterodactyl\Http\Middleware\TrimStrings;
use Illuminate\Session\Middleware\StartSession;
use Pterodactyl\Http\Middleware\EncryptCookies;
use Pterodactyl\Http\Middleware\Api\IsValidJson;
use Pterodactyl\Http\Middleware\VerifyCsrfToken;
use Pterodactyl\Http\Middleware\VerifyReCaptcha;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Pterodactyl\Http\Middleware\LanguageMiddleware;
use Pterodactyl\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Pterodactyl\Http\Middleware\Activity\TrackAPIKey;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Pterodactyl\Http\Middleware\MaintenanceMiddleware;
use Pterodactyl\Http\Middleware\EnsureStatefulRequests;
use Pterodactyl\Http\Middleware\RedirectIfAuthenticated;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Pterodactyl\Http\Middleware\Api\AuthenticateIPAccess;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Pterodactyl\Http\Middleware\Api\Daemon\DaemonAuthenticate;
use Pterodactyl\Http\Middleware\Api\Client\RequireClientApiKey;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Pterodactyl\Http\Middleware\Api\Client\SubstituteClientBindings;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Pterodactyl\Http\Middleware\Api\Application\AuthenticateApplicationUser;
use Pterodactyl\Http\Middleware\Security\PteroProtectWaf;
use Pterodactyl\Http\Middleware\Security\PteroProtectRestrictedApplicationApi;
use Pterodactyl\Http\Middleware\Security\PteroProtectNodeFileShield;
use Pterodactyl\Http\Middleware\Security\PteroProtectAvatarUploadRateLimit;
use Pterodactyl\Http\Middleware\Security\PteroProtectSessionBinding;

class Kernel extends HttpKernel
{
    protected $middleware = [
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        PteroProtectWaf::class,
        SetSecurityHeaders::class,
    ];

    protected $middlewarePriority = [
        SubstituteClientBindings::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PteroProtectSessionBinding::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            LanguageMiddleware::class,
        ],
        'api' => [
            EnsureStatefulRequests::class,
            'auth:sanctum',
            PteroProtectSessionBinding::class,
            IsValidJson::class,
            TrackAPIKey::class,
            RequireTwoFactorAuthentication::class,
            AuthenticateIPAccess::class,
        ],
        'application-api' => [
            SubstituteBindings::class,
            AuthenticateApplicationUser::class,
            PteroProtectRestrictedApplicationApi::class,
        ],
        'client-api' => [
            SubstituteClientBindings::class,
            RequireClientApiKey::class,
        ],
        'daemon' => [
            SubstituteBindings::class,
            DaemonAuthenticate::class,
        ],
    ];

    protected $middlewareAliases = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'auth.session' => AuthenticateSession::class,
        'guest' => RedirectIfAuthenticated::class,
        'csrf' => VerifyCsrfToken::class,
        'throttle' => ThrottleRequests::class,
        'can' => Authorize::class,
        'bindings' => SubstituteBindings::class,
        'recaptcha' => VerifyReCaptcha::class,
        'node.maintenance' => MaintenanceMiddleware::class,
        'pteroprotect.nodefs' => PteroProtectNodeFileShield::class,
        'pteroprotect.avatar-upload' => PteroProtectAvatarUploadRateLimit::class,
    ];
}
