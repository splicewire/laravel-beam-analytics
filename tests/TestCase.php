<?php

namespace Splicewire\Beam\Analytics\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider;
use Splicewire\Beam\Analytics\Providers\GtmProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BeamAnalyticsServiceProvider::class];
    }

    /**
     * Set the analytics config to a given env + providers map in one call.
     *
     * @param  array<string, array{adapter?: mixed, id?: mixed}>  $providers
     * @param  list<string>  $enabledEnvs
     */
    protected function configure(string $env, array $providers, array $enabledEnvs = ['production']): void
    {
        config([
            'app.env' => $env,
            'analytics.enabled_envs' => $enabledEnvs,
            'analytics.providers' => $providers,
        ]);
    }

    /** A GTM provider map entry with the given id. */
    protected function gtm(string $id): array
    {
        return ['adapter' => GtmProvider::class, 'id' => $id];
    }
}
