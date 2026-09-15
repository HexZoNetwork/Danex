<?php

namespace Pterodactyl\Providers;

use Throwable;
use Pterodactyl\Models;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Extensions\Themes\Theme;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $version = $this->versionData();
        View::share('appVersion', $version['version'] ?? 'undefined');
        View::share('appIsGit', $version['is_git'] ?? false);

        Paginator::useBootstrap();

        // Force HTTPS scheme when the request arrived over TLS (e.g. via a
        // reverse-proxy or direct TLS termination) or when APP_URL explicitly
        // declares https.  Without this, any APP_URL set to http:// causes
        // the Vue SPA to emit Mixed-Content XHR/fetch requests that modern
        // browsers block outright.
        //
        // @see https://github.com/pterodactyl/panel/issues/3623
        if (
            Str::startsWith(config('app.url') ?? '', 'https://')
            || Request::secure()
            || (strtolower((string) Request::header('X-Forwarded-Proto', '')) === 'https')
        ) {
            URL::forceScheme('https');
        }

        Relation::enforceMorphMap([
            'allocation' => Models\Allocation::class,
            'api_key' => Models\ApiKey::class,
            'backup' => Models\Backup::class,
            'database' => Models\Database::class,
            'egg' => Models\Egg::class,
            'egg_variable' => Models\EggVariable::class,
            'schedule' => Models\Schedule::class,
            'server' => Models\Server::class,
            'ssh_key' => Models\UserSSHKey::class,
            'task' => Models\Task::class,
            'user' => Models\User::class,
        ]);
    }

    /**
     * Register application service providers.
     */
    public function register(): void
    {
        // Only load the settings service provider if the environment
        // is configured to allow it.
        if (!config('pterodactyl.load_environment_only', false) && $this->app->environment() !== 'testing') {
            $this->app->register(SettingsServiceProvider::class);
        }

        $this->app->singleton('extensions.themes', function () {
            return new Theme();
        });
    }

    /**
     * Return version information for the footer.
     */
    protected function versionData(): array
    {
        try {
            return Cache::remember('git-version', 5, fn () => $this->uncachedVersionData());
        } catch (Throwable) {
            return $this->uncachedVersionData();
        }
    }

    private function uncachedVersionData(): array
    {
        if (file_exists(base_path('.git/HEAD'))) {
            $head = explode(' ', (string) file_get_contents(base_path('.git/HEAD')));

            if (array_key_exists(1, $head)) {
                $path = base_path('.git/' . trim($head[1]));
            }
        }

        if (isset($path) && file_exists($path)) {
            return [
                'version' => substr((string) file_get_contents($path), 0, 8),
                'is_git' => true,
            ];
        }

        return [
            'version' => config('app.version'),
            'is_git' => false,
        ];
    }
}
