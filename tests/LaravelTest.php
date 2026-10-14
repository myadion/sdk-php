<?php

declare(strict_types=1);

namespace MyAdion\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use MyAdion\Client;
use MyAdion\Laravel\Facades\MyAdion;
use MyAdion\Laravel\MyAdionServiceProvider;

final class LaravelTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_the_provider_binds_a_configured_client(): void
    {
        $app = new class extends Container
        {
            public function runningInConsole(): bool
            {
                return false;
            }
        };
        Container::setInstance($app);
        $app->instance('config', new Repository(['myadion' => [
            'client_id' => 'test_client',
            'client_secret' => 'test_secret',
            'base_url' => MYADION_FAKE_URL.'/api',
            'project' => 'project_AbCdEf123456',
            'token_cache_store' => null,
        ]]));

        $provider = new MyAdionServiceProvider($app);
        $provider->register();
        $provider->boot();

        /** @var Repository $config */
        $config = $app->make('config');
        $this->assertSame(30.0, $config->get('myadion.timeout'), 'the package defaults are merged');

        $client = $app->make(Client::class);
        $this->assertSame($client, $app->make('myadion'));

        Facade::setFacadeApplication($app);
        $result = MyAdion::request('GET', '/echo');
        $this->assertSame('project_AbCdEf123456', $result?->project ?? null);
    }
}
