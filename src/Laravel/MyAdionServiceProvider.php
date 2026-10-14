<?php

declare(strict_types=1);

namespace MyAdion\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use MyAdion\Client;

/**
 * Discovered automatically by Laravel. Binds MyAdion\Client as a singleton,
 * built from `config/myadion.php` (publish it with
 * `php artisan vendor:publish --tag=myadion-config`).
 */
final class MyAdionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/myadion.php', 'myadion');

        $this->app->singleton(Client::class, static function (Container $app): Client {
            /** @var Config $config */
            $config = $app->make('config');
            /** @var array<string,mixed> $c */
            $c = (array) $config->get('myadion', []);

            $tokenStore = null;
            $store = $c['token_cache_store'] ?? null;
            if (is_string($store) && $store !== '' && $app->bound('cache')) {
                /** @var CacheFactory $cache */
                $cache = $app->make('cache');
                $tokenStore = new CacheTokenStore($cache->store($store === 'default' ? null : $store));
            }

            return new Client([
                'client_id' => self::str($c['client_id'] ?? null),
                'client_secret' => self::str($c['client_secret'] ?? null),
                'scopes' => self::str($c['scopes'] ?? null),
                'project' => self::str($c['project'] ?? null),
                'base_url' => self::str($c['base_url'] ?? null),
                'timeout' => is_numeric($c['timeout'] ?? null) ? (float) $c['timeout'] : 30.0,
                'max_retries' => is_numeric($c['max_retries'] ?? null) ? (int) $c['max_retries'] : 2,
                'token_store' => $tokenStore,
            ]);
        });
        $this->app->alias(Client::class, 'myadion');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/myadion.php' => $this->app->configPath('myadion.php'),
            ], 'myadion-config');
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [Client::class, 'myadion'];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
