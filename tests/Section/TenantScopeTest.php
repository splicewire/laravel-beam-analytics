<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Splicewire\Beam\Analytics\AnalyticsSection;
use Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver;
use Splicewire\Beam\Analytics\Contracts\MeasurementSettings;
use Splicewire\Beam\Analytics\Providers\GaProvider;
use Splicewire\Beam\Analytics\Providers\GtmProvider;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * Ticket 05: one set of area resources serves the operator and the tenant realm, and the scope comes from the request.
 * Inside a tenant's boundary a reading is in Tenant scope and Measurement reads the tenant's own settings through the
 * MeasurementSettings port; it never asks the tag's id resolver, and it never shows the install's ids to a tenant.
 */
class TenantScopeTest extends SectionTestCase
{
    private function installConfig(): void
    {
        config([
            'beam.analytics.providers' => [
                'gtm' => ['adapter' => GtmProvider::class, 'id' => 'GTM-INSTALL'],
                'ga4' => ['adapter' => GaProvider::class, 'id' => 'G-INSTALL1'],
            ],
            'beam.analytics.enabled_envs' => ['production'],
        ]);
    }

    /** The request is inside tenant `$id`'s boundary, as tenancy's route middleware leaves it. */
    private function insideTenant(string $id): void
    {
        $this->app->instance(TenancyContext::class, new class($id) implements TenancyContext
        {
            public function __construct(private string $id) {}

            public function currentTenant(): ?object
            {
                return (object) ['id' => $this->id];
            }

            public function currentTenantId(): int|string|null
            {
                return $this->id;
            }

            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function centralConnection(): ?string
            {
                return null;
            }
        });
        $this->app->forgetScopedInstances();
    }

    /** The tag's id resolver must not be asked: on a tenant's app host it answers with the install's id. */
    private function forbidTheIdResolver(): void
    {
        $this->app->bind(AnalyticsIdResolver::class, fn () => new class implements AnalyticsIdResolver
        {
            public function idFor(string $provider): ?string
            {
                throw new RuntimeException("Measurement asked the tag's id resolver for [{$provider}].");
            }
        });
    }

    /** @return array<string, string> figure id => value */
    private function measurement(object $reader): array
    {
        $response = $this->actingAs($reader)->getJson('/frame/resources/analytics-measurement')->assertOk();
        $this->assertStringNotContainsString('GTM-INSTALL', $response->getContent());
        $this->assertStringNotContainsString('G-INSTALL1', $response->getContent());

        return array_column($response->json('data'), 'value', 'id');
    }

    #[Test]
    public function a_reading_is_in_install_scope_outside_a_tenant_and_in_tenant_scope_inside_one(): void
    {
        $this->assertTrue(app(AnalyticsScope::class)->isInstall());

        $this->insideTenant('acme');

        $scope = app(AnalyticsScope::class);
        $this->assertTrue($scope->isTenant());
        $this->assertSame('acme', $scope->tenantId);
    }

    #[Test]
    public function a_tenant_reads_its_own_measurement_id_through_the_settings_port_and_never_the_id_resolver(): void
    {
        $this->installConfig();
        $this->forbidTheIdResolver();
        $this->app->bind(MeasurementSettings::class, fn () => new class implements MeasurementSettings
        {
            public function providerIds(AnalyticsScope $scope): array
            {
                return $scope->isTenant() && $scope->tenantId === 'acme' ? ['gtm' => '', 'ga4' => 'G-ACME1'] : ['gtm' => 'GTM-INSTALL', 'ga4' => 'G-INSTALL1'];
            }
        });
        $this->insideTenant('acme');
        $member = $this->reader('admin@acme.test', ['analytics.view']);
        $this->defineGrants(['analytics.view']);

        $this->assertSame('ga4 (G-ACME1)', $this->measurement($member)['providers']);
    }

    #[Test]
    public function without_a_tenancy_layer_a_tenant_is_shown_none_of_the_installs_ids(): void
    {
        $this->installConfig();
        $this->forbidTheIdResolver();
        $this->insideTenant('acme');
        $member = $this->reader('admin@acme.test', ['analytics.view']);
        $this->defineGrants(['analytics.view']);

        $this->assertSame('None', $this->measurement($member)['providers']);
    }

    #[Test]
    public function the_install_reads_its_own_ids_from_config_without_the_id_resolver(): void
    {
        $this->installConfig();
        $this->forbidTheIdResolver();
        $operator = $this->reader('operator@beam.test', ['os.operate', 'analytics.view']);
        $this->defineGrants(['os.operate', 'analytics.view']);

        $rows = array_column($this->actingAs($operator)->getJson('/frame/resources/analytics-measurement')->assertOk()->json('data'), 'value', 'id');

        $this->assertSame('gtm (GTM-INSTALL), ga4 (G-INSTALL1)', $rows['providers']);
    }

    #[Test]
    public function joining_the_tenant_realm_does_not_open_the_area_to_a_member_without_analytics_view(): void
    {
        $this->installConfig();
        $this->insideTenant('acme');
        $member = $this->reader('member@acme.test', []);
        $this->defineGrants(['os.operate', 'analytics.view']);

        $this->actingAs($member)->getJson('/frame/resources/analytics-measurement')->assertForbidden();
        $this->actingAs($member)->getJson('/frame/resources/analytics-measurement/summary')->assertForbidden();
    }

    #[Test]
    public function the_section_is_seated_in_both_realms_at_each_realms_own_path_and_its_areas_serve_both(): void
    {
        foreach (['operator' => '/operator/analytics', 'tenant' => '/analytics'] as $realm => $href) {
            $seats = array_values(array_filter(app(NavSectionRegistry::class)->for($realm), fn (NavSection $s): bool => $s->key === AnalyticsSection::KEY));
            $this->assertCount(1, $seats, $realm);
            $this->assertTrue($seats[0]->overview, $realm);
            $this->assertSame($href, $seats[0]->href, $realm);
            $this->assertSame([AnalyticsSection::ENTITLEMENT], $seats[0]->entitlement, $realm);
        }

        $this->assertEqualsCanonicalizing(['operator', 'tenant'], app(ParticleResourceRegistry::class)->realmsFor('analytics-measurement'));
    }
}
