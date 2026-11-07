<?php

namespace Splicewire\Beam\Analytics\Tests\Registries;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registrant;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Analytics\AnalyticsManager;
use Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider;
use Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver;
use Splicewire\Beam\Analytics\Providers\AnalyticsProviderRegistry;
use Splicewire\Beam\Analytics\Providers\GaProvider;
use Splicewire\Beam\Analytics\Providers\GtmProvider;
use Splicewire\Beam\Analytics\Tests\Support\AcmeAdapter;
use Splicewire\Beam\Analytics\Tests\TestCase;

class AnalyticsProviderRegistryTest extends TestCase
{
    private function registry(): AnalyticsProviderRegistry
    {
        return app(AnalyticsProviderRegistry::class);
    }

    #[Test]
    public function config_is_its_source(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-1')]);

        $this->assertSame(['beam.analytics.providers.gtm'], array_map('strval', $this->registry()->keys()));
        $this->assertSame(GtmProvider::class, $this->registry()->resolve('gtm')['adapter']);
    }

    #[Test]
    public function a_registered_provider_lands_in_config_and_renders_after_the_configured_ones(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-1')]);

        $this->registry()->register('acme', ['adapter' => AcmeAdapter::class, 'id' => 'ACME-1']);

        $this->assertSame(AcmeAdapter::class, config('beam.analytics.providers.acme.adapter'));
        $this->assertSame('ACME-1', app(AnalyticsIdResolver::class)->idFor('acme'));
        $this->assertStringEndsWith('<!-- acme body ACME-1 -->', app(AnalyticsManager::class)->body());
    }

    /**
     * The tenancy layer's TenantAnalyticsIdResolver decides by `beam.analytics.providers.<name>.adapter`, read from config
     * and subclass-checked against GaProvider. It cannot be installed here, so this pins the read it makes.
     */
    #[Test]
    public function a_contributed_ga4_adapter_is_visible_where_the_tenancy_resolver_reads_it(): void
    {
        $this->configure('production', []);

        $this->registry()->register('tenant_ga4', ['adapter' => GaProvider::class]);

        $this->assertSame(GaProvider::class, app('config')->get('beam.analytics.providers.tenant_ga4.adapter'));
    }

    #[Test]
    public function register_refuses_a_misdeclared_provider(): void
    {
        foreach ([AcmeAdapter::class, ['id' => 'X'], ['adapter' => 'Nope'], ['adapter' => self::class], ['adapter' => AcmeAdapter::class, 'id' => 5]] as $entry) {
            try {
                $this->registry()->register('acme', $entry);
                $this->fail('accepted a misdeclared provider: '.json_encode($entry));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('acme', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_key_config_holds_with_another_adapter_is_refused_naming_config(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-1')]);

        try {
            $this->registry()->register('gtm', ['adapter' => AcmeAdapter::class], 'acme/one');
            $this->fail('accepted a key config holds with another adapter');
        } catch (DuplicateRegistryKey $e) {
            $this->assertSame('beam.analytics.providers.gtm', $e->key);
            $this->assertSame('acme/one', $e->incoming);
            $this->assertSame('config:beam.analytics.providers', $e->existing);
        }
    }

    /** A cached config holds the contribution, and the id it held wins over the one registered again on this boot. */
    #[Test]
    public function a_first_registration_config_already_holds_keeps_config(): void
    {
        $this->configure('production', ['acme' => ['adapter' => AcmeAdapter::class, 'id' => 'FROM-CONFIG']]);

        $this->registry()->register('acme', ['adapter' => AcmeAdapter::class, 'id' => null]);

        $this->assertSame('FROM-CONFIG', config('beam.analytics.providers.acme.id'));
    }

    #[Test]
    public function a_second_registrant_of_a_config_held_key_is_refused_naming_the_first(): void
    {
        $this->configure('production', ['acme' => ['adapter' => AcmeAdapter::class, 'id' => 'FROM-CONFIG']]);

        $this->registry()->register('acme', ['adapter' => AcmeAdapter::class], 'acme/one');

        $this->assertRefused('acme', ['adapter' => AcmeAdapter::class], 'acme/two', 'acme/one');
        $this->assertSame('FROM-CONFIG', config('beam.analytics.providers.acme.id'));
    }

    #[Test]
    public function a_second_registration_is_refused_whatever_it_carries(): void
    {
        $this->configure('production', []);

        $first = ['adapter' => AcmeAdapter::class, 'id' => 'A'];
        $this->registry()->register('acme', $first, 'acme/one');

        foreach ([$first, ['adapter' => AcmeAdapter::class, 'id' => 'B'], ['adapter' => GtmProvider::class, 'id' => 'A']] as $second) {
            $this->assertRefused('acme', $second, 'acme/two', 'acme/one');
        }

        $this->assertSame($first, config('beam.analytics.providers.acme'));
    }

    #[Test]
    public function a_repeat_by_the_same_registrant_is_refused(): void
    {
        $this->configure('production', []);

        $this->registry()->register('acme', ['adapter' => AcmeAdapter::class], 'acme/one');

        $this->assertRefused('acme', ['adapter' => AcmeAdapter::class], 'acme/one', 'acme/one');
    }

    #[Test]
    public function a_scoped_registrant_is_the_one_a_refusal_names(): void
    {
        $this->configure('production', []);

        Registrant::scoped('acme/scoped', fn () => $this->registry()->register('acme', ['adapter' => AcmeAdapter::class]));

        $this->assertRefused('acme', ['adapter' => AcmeAdapter::class], 'acme/two', 'acme/scoped');
    }

    #[Test]
    public function a_rooted_key_reaches_the_same_slot(): void
    {
        $this->configure('production', []);

        $this->registry()->register('beam.analytics.providers.acme', ['adapter' => AcmeAdapter::class], 'acme/one');

        $this->assertSame(AcmeAdapter::class, config('beam.analytics.providers.acme.adapter'));
        $this->assertRefused('acme', ['adapter' => AcmeAdapter::class], 'acme/two', 'acme/one');
    }

    private function assertRefused(string $key, array $entry, string $incoming, string $existing): void
    {
        try {
            $this->registry()->register($key, $entry, $incoming);
            $this->fail("accepted a second registration of [{$key}]");
        } catch (DuplicateRegistryKey $e) {
            $this->assertSame("beam.analytics.providers.{$key}", $e->key);
            $this->assertSame($incoming, $e->incoming);
            $this->assertSame($existing, $e->existing);
        }
    }

    #[Test]
    public function a_misdeclared_map_in_config_still_boots_and_renders_nothing(): void
    {
        $this->configure('production', ['mystery' => ['adapter' => 'Nope', 'id' => 'X'], 'odd' => 'not-an-array']);

        (new BeamAnalyticsServiceProvider($this->app))->packageBooted();

        $this->assertSame('', app(AnalyticsManager::class)->body());
        $this->assertSame('unknown', app(AnalyticsManager::class)->providerStatuses()['mystery']['status']);
    }

    #[Test]
    public function a_host_name_the_key_grammar_refuses_still_renders_and_enumerates(): void
    {
        $this->configure('production', ['Legacy_GTM' => $this->gtm('GTM-OLD')]);

        $this->assertStringContainsString('GTM-OLD', app(AnalyticsManager::class)->body());
        $this->assertSame(['Legacy_GTM'], array_map('strval', $this->registry()->keys()));
    }

    /** Registry-kernel's census: the manager folds a population and offers no way to register. */
    #[Test]
    public function the_manager_stays_the_aggregator(): void
    {
        $this->assertNull(IsRegistry::of(AnalyticsManager::class));
        $this->assertFalse(is_subclass_of(AnalyticsManager::class, Registry::class));
        $this->assertFalse(method_exists(AnalyticsManager::class, 'register'));
    }
}
