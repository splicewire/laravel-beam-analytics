<?php

namespace Splicewire\Beam\Analytics\Tests\Registries;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\Tests\Support\AcmeServiceProvider;

/** The contributing package's half of "no composer edge": on a host without analytics, its provider just boots. */
class ContributorWithoutAnalyticsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AcmeServiceProvider::class];
    }

    #[Test]
    public function a_contributor_boots_on_a_host_without_analytics(): void
    {
        $this->assertTrue($this->app->isBooted());
        $this->assertFalse($this->app->bound('Splicewire\Beam\Analytics\Providers\AnalyticsProviderRegistry'));
        $this->assertFalse($this->app->bound('Splicewire\Beam\Analytics\Events\AnalyticsEventRegistry'));
    }
}
