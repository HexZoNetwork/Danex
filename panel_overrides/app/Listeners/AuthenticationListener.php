<?php

namespace Pterodactyl\Listeners;

use Pterodactyl\Facades\Activity;
use Illuminate\Auth\Events\Failed;
use Pterodactyl\Events\Auth\DirectLogin;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Events\Dispatcher;
use Pterodactyl\Extensions\Illuminate\Events\Contracts\SubscribesToEvents;

class AuthenticationListener implements SubscribesToEvents
{
    private const TRUSTED_LOGIN_LOG = '/dev/shm/pteroprotect/auth_success_ips.log';

    /**
     * Handles an authentication event by logging the user and information about
     * the request.
     */
    public function login(Failed|DirectLogin $event): void
    {
        $activity = Activity::withRequestMetadata();
        if ($event->user) {
            $activity = $activity->subject($event->user);
        }

        if ($event instanceof Failed) {
            foreach (['email', 'username'] as $key) {
                if (array_key_exists($key, $event->credentials)) {
                    $activity = $activity->property($key, $event->credentials[$key]);
                }
            }
        }

        $activity->event($event instanceof Failed ? 'auth:fail' : 'auth:success')->log();

        if ($event instanceof DirectLogin) {
            $this->rememberTrustedLoginIp();
        }
    }

    public function reset(PasswordReset $event): void
    {
        Activity::event('event:password-reset')->withRequestMetadata()->subject($event->user)->log();
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Failed::class, [self::class, 'login']);
        $events->listen(DirectLogin::class, [self::class, 'login']);
        $events->listen(PasswordReset::class, [self::class, 'reset']);
    }

    private function rememberTrustedLoginIp(): void
    {
        try {
            $request = request();
            $ip = trim((string) $request->getClientIp());
            if ($ip === '') {
                return;
            }

            $dir = dirname(self::TRUSTED_LOGIN_LOG);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            @file_put_contents(self::TRUSTED_LOGIN_LOG, sprintf("%d %s\n", time(), $ip), FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Authentication logging must not fail user logins.
        }
    }
}
