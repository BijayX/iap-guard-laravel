<?php

declare(strict_types=1);

namespace BijayX\IapGuard;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Store\DatabasePurchaseStore;
use BijayX\IapGuard\Store\InMemoryPurchaseStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class IapGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/iap-guard.php', 'iap-guard');

        $this->app->singleton(PurchaseStore::class, static function (Container $app): PurchaseStore {
            $driver = $app->make('config')->get('iap-guard.store.driver', 'database');
            $connection = $app->make('config')->get('iap-guard.store.connection');

            return match (true) {
                $driver === null, $driver === 'database' => new DatabasePurchaseStore(
                    $app->make(DatabaseManager::class)->connection(is_string($connection) && $connection !== '' ? $connection : null),
                ),
                $driver === 'memory' => new InMemoryPurchaseStore,
                is_string($driver) && is_a($driver, PurchaseStore::class, true) => $app->make($driver),
                default => throw ConfigurationException::invalid('store.driver', '"database", "memory" or a PurchaseStore class name'),
            };
        });

        $this->app->singleton(IapGuard::class, static function (Container $app): IapGuard {
            $config = $app->make('config')->get('iap-guard', []);
            $cacheStore = $app->make('config')->get('iap-guard.cache.store');

            return new IapGuard(
                is_array($config) ? $config : [],
                $app->make(PurchaseStore::class),
                $app->make(HttpFactory::class),
                $app->make(CacheFactory::class)->store(is_string($cacheStore) && $cacheStore !== '' ? $cacheStore : null),
                static fn (): Dispatcher => $app->make(Dispatcher::class),
                static fn (): LoggerInterface => $app->make(LoggerInterface::class),
            );
        });

        $this->app->alias(IapGuard::class, 'iap-guard');
    }

    public function boot(): void
    {
        $config = $this->app->make('config');

        if ($config->get('iap-guard.store.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($config->get('iap-guard.webhooks.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/iap-guard.php' => $this->app->configPath('iap-guard.php'),
            ], 'iap-guard-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'iap-guard-migrations');
        }
    }
}
