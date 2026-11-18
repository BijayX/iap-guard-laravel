<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Tests;

use BijayX\IapGuard\Facades\IapGuard;
use BijayX\IapGuard\IapGuardServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const NOW = '2026-11-18T12:00:00Z';

    public const FIXTURES = __DIR__.'/fixtures';

    /**
     * Config applied before the providers boot (see rebootWith()).
     *
     * @var array<string, mixed>
     */
    private array $bootConfig = [];

    /**
     * Rebuild the application with config that must be set before the service provider boots
     * (routes, migrations).
     *
     * @param  array<string, mixed>  $config
     */
    public function rebootWith(array $config): void
    {
        $this->bootConfig = $config;
        $this->refreshApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [IapGuardServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['IapGuard' => IapGuard::class];
    }

    protected function defineEnvironment($app): void
    {
        $oidc = json_decode((string) file_get_contents(self::FIXTURES.'/google/oidc.json'), true);
        $tokens = json_decode((string) file_get_contents(self::FIXTURES.'/account-tokens.json'), true);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');

        $app['config']->set('iap-guard.apple.bundle_id', 'com.example.app');
        $app['config']->set('iap-guard.apple.app_apple_id', 1234567890);
        $app['config']->set('iap-guard.apple.root_certificates', [self::FIXTURES.'/apple/certs/root.pem']);

        $app['config']->set('iap-guard.google.package_name', 'com.example.app');
        $app['config']->set('iap-guard.google.service_account', self::FIXTURES.'/google/service-account.json');
        $app['config']->set('iap-guard.google.consumable_product_ids', ['coins_100']);
        $app['config']->set('iap-guard.google.pubsub.audience', $oidc['audience']);
        $app['config']->set('iap-guard.google.pubsub.service_account_email', $oidc['serviceAccountEmail']);

        $app['config']->set('iap-guard.ownership.account_token_secret', $tokens['secret']);

        foreach ($this->bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
