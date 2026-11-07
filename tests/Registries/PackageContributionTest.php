<?php

namespace Splicewire\Beam\Analytics\Tests\Registries;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\AnalyticsManager;
use Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider;
use Splicewire\Beam\Analytics\Events\AnalyticsEventRegistry;
use Splicewire\Beam\Analytics\Events\EventVocabulary;
use Splicewire\Beam\Analytics\Providers\AnalyticsProviderRegistry;
use Splicewire\Beam\Analytics\Tests\Support\AcmeServiceProvider;
use Splicewire\Beam\Analytics\Tests\TestCase;

/** A real boot with {@see AcmeServiceProvider} listed after analytics, so its boot() runs after analytics' own. */
class PackageContributionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BeamAnalyticsServiceProvider::class, AcmeServiceProvider::class];
    }

    #[Test]
    public function a_provider_contributed_from_register_reaches_the_manager(): void
    {
        config(['app.env' => 'production', 'beam.analytics.enabled_envs' => ['production']]);

        $this->assertSame('active', app(AnalyticsManager::class)->providerStatuses()['acme']['status']);
        $this->assertStringContainsString('<!-- acme head ACME-1 -->', app(AnalyticsManager::class)->head());
    }

    #[Test]
    public function an_event_contributed_from_a_later_boot_reaches_the_vocabulary(): void
    {
        $this->assertTrue(app(EventVocabulary::class)->has('acme_clicked'));
        $this->assertSame('acme/analytics', app(AnalyticsEventRegistry::class)->registrantOf('acme_clicked'));
    }

    #[Test]
    public function a_misdeclared_contributed_event_is_refused_at_boot(): void
    {
        $this->app->forgetInstance(AnalyticsEventRegistry::class);
        $this->app->afterResolving(AnalyticsEventRegistry::class, fn ($registry) => $registry->register('Not A Name', []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not A Name');

        (new BeamAnalyticsServiceProvider($this->app))->packageBooted();
    }

    #[Test]
    public function a_misdeclared_contributed_provider_is_refused_at_boot(): void
    {
        $this->app->forgetInstance(AnalyticsProviderRegistry::class);
        $this->app->afterResolving(AnalyticsProviderRegistry::class, fn ($registry) => $registry->register('broken', ['adapter' => 'Nope']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('broken');

        (new BeamAnalyticsServiceProvider($this->app))->packageBooted();
    }

    #[Test]
    public function a_second_resolve_of_the_providers_registry_does_not_refuse_its_own_contribution(): void
    {
        $this->app->forgetInstance(AnalyticsProviderRegistry::class);

        $this->assertTrue(app(AnalyticsProviderRegistry::class)->has('acme'));
    }
}
