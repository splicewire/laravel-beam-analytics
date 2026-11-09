<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\AnalyticsSection;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Analytics\Tests\Support\Areas\NarrowArea;
use Splicewire\Beam\Analytics\Tests\Support\Areas\WideningArea;
use Splicewire\Beam\Dashboard\SectionOverview;
use Splicewire\Beam\Entitlements\EntitlementKeyUniverse;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The tracer through beam's frame socket on a tenancy-free install: the Measurement area's page (the list route) and its
 * card (the summary route) for a reader holding `analytics.view`, and the refusals for a reader without it.
 */
class AnalyticsSectionTest extends SectionTestCase
{
    private function measurementConfig(): void
    {
        $template = tempnam(sys_get_temp_dir(), 'root').'.blade.php';
        file_put_contents($template, '<head>@beamAnalyticsHead</head><body>@beamAnalyticsBody</body>');

        config([
            'app.env' => 'local',
            'beam.analytics.enabled_envs' => ['production', 'staging'],
            'beam.analytics.providers' => [
                'gtm' => ['adapter' => \Splicewire\Beam\Analytics\Providers\GtmProvider::class, 'id' => 'GTM-TEST1'],
                'ga4' => ['adapter' => \Splicewire\Beam\Analytics\Providers\GaProvider::class, 'id' => ''],
            ],
            'beam.analytics.consent' => ['enabled' => true, 'denied_regions' => ['EEA', 'GB', 'CH']],
            'beam.analytics.root_templates' => [$template],
        ]);
    }

    /** @return array<string, array{label: string, value: mixed}> keyed by figure id */
    private function rows(array $data): array
    {
        $out = [];
        foreach ($data as $row) {
            $out[$row['id']] = ['label' => $row['label'], 'value' => $row['value']];
        }

        return $out;
    }

    private function summaryFigures(\Illuminate\Testing\TestResponse $response): array
    {
        $figures = $response->json('figures') ?? $response->json('data.figures') ?? [];
        $out = [];
        foreach ($figures as $figure) {
            $out[$figure['key']] = ['label' => $figure['label'], 'value' => $figure['value']];
        }

        return $out;
    }

    #[Test]
    public function an_analyst_reads_measurement_on_its_page_and_its_card_and_they_agree(): void
    {
        $this->measurementConfig();
        $analyst = $this->reader('analyst@beam.test', ['os.operate', 'analytics.view']);
        $this->defineGrants(['os.operate', 'analytics.view']);

        $page = $this->actingAs($analyst)->getJson('/frame/resources/analytics-measurement');
        $page->assertOk();
        $card = $this->actingAs($analyst)->getJson('/frame/resources/analytics-measurement/summary');
        $card->assertOk();

        $rows = $this->rows($page->json('data'));
        $this->assertSame([
            'providers' => ['label' => 'Providers on', 'value' => 'gtm'],
            'environments' => ['label' => 'Allowed environments', 'value' => 'production, staging'],
            'consent' => ['label' => 'Consent regions', 'value' => 'EEA, GB, CH'],
            'placement' => ['label' => 'Slot placement', 'value' => 'Placed in 1 root template'],
        ], $rows);
        $this->assertSame($rows, $this->summaryFigures($card), 'the card and the page read one figures path');
    }

    #[Test]
    public function measurement_says_off_and_unplaced_rather_than_inventing_a_figure(): void
    {
        $template = tempnam(sys_get_temp_dir(), 'root').'.blade.php';
        file_put_contents($template, '<head></head><body></body>');
        config([
            'beam.analytics.providers' => ['gtm' => ['adapter' => \Splicewire\Beam\Analytics\Providers\GtmProvider::class, 'id' => '']],
            'beam.analytics.enabled_envs' => [],
            'beam.analytics.consent' => ['enabled' => false, 'denied_regions' => ['EEA']],
            'beam.analytics.root_templates' => [$template],
        ]);
        $analyst = $this->reader('analyst@beam.test', ['os.operate', 'analytics.view']);
        $this->defineGrants(['os.operate', 'analytics.view']);

        $rows = $this->rows($this->actingAs($analyst)->getJson('/frame/resources/analytics-measurement')->assertOk()->json('data'));

        $this->assertSame('None', $rows['providers']['value']);
        $this->assertSame('None', $rows['environments']['value']);
        $this->assertSame('Off', $rows['consent']['value']);
        $this->assertSame('Not placed in 1 root template', $rows['placement']['value']);

        config(['beam.analytics.root_templates' => []]);
        $rows = $this->rows($this->actingAs($analyst)->getJson('/frame/resources/analytics-measurement')->assertOk()->json('data'));
        $this->assertSame('Unverified: no root template found', $rows['placement']['value']);
    }

    #[Test]
    public function without_analytics_view_the_page_and_card_are_refused_and_the_manifest_omits_the_area(): void
    {
        $this->measurementConfig();
        $operator = $this->reader('operator@beam.test', ['os.operate']);
        $member = $this->reader('member@beam.test', []);
        $this->defineGrants(['os.operate', 'analytics.view']);

        foreach ([$operator, $member] as $reader) {
            $this->actingAs($reader)->getJson('/frame/resources/analytics-measurement')->assertForbidden();
            $this->actingAs($reader)->getJson('/frame/resources/analytics-measurement/summary')->assertForbidden();

            $manifest = $this->actingAs($reader)->getJson('/frame/manifest');
            $this->assertNotContains('analytics-measurement', $this->manifestResourceKeys($manifest->json()), $reader->email);
        }

        $analyst = $this->reader('analyst@beam.test', ['os.operate', 'analytics.view']);
        $this->assertContains('analytics-measurement', $this->manifestResourceKeys($this->actingAs($analyst)->getJson('/frame/manifest')->json()), 'control: the analyst sees it');
    }

    #[Test]
    public function an_area_can_narrow_its_gate(): void
    {
        $this->measurementConfig();
        app(AnalyticsAreaRegistry::class)->add(NarrowArea::class, 'acme/reports');
        $this->refreshAreaResources();
        $analyst = $this->reader('analyst@beam.test', ['os.operate', 'analytics.view']);
        $reporter = $this->reader('reporter@beam.test', ['os.operate', 'analytics.view', 'reports.read']);
        $this->defineGrants(['os.operate', 'analytics.view'], ['reports.read']);

        $this->actingAs($analyst)->getJson('/frame/resources/analytics-narrow')->assertForbidden();
        $this->actingAs($analyst)->getJson('/frame/resources/analytics-narrow/summary')->assertForbidden();
        $this->actingAs($reporter)->getJson('/frame/resources/analytics-narrow')->assertOk();
        $this->actingAs($analyst)->getJson('/frame/resources/analytics-measurement')->assertOk();
    }

    #[Test]
    public function an_area_cannot_widen_its_gate(): void
    {
        $this->measurementConfig();
        app(AnalyticsAreaRegistry::class)->add(WideningArea::class, 'acme/wide');
        $this->refreshAreaResources();
        $outsider = $this->reader('outsider@beam.test', ['os.operate', 'anyone']);
        $analyst = $this->reader('analyst@beam.test', ['os.operate', 'analytics.view', 'anyone']);
        $this->defineGrants(['os.operate', 'analytics.view'], ['anyone']);

        $this->actingAs($outsider)->getJson('/frame/resources/analytics-wide')->assertForbidden();
        $this->actingAs($outsider)->getJson('/frame/resources/analytics-wide/summary')->assertForbidden();
        $this->actingAs($analyst)->getJson('/frame/resources/analytics-wide')->assertOk();
    }

    #[Test]
    public function analytics_view_is_declared_by_the_package_so_its_ability_exists(): void
    {
        $this->assertContains(AnalyticsSection::ENTITLEMENT, app(EntitlementKeyUniverse::class)->keys());
        $this->assertTrue(Gate::has('entitlement:analytics.view'));
    }

    #[Test]
    public function the_section_is_seated_in_the_operator_realm_and_lands_on_its_overview(): void
    {
        $seats = array_values(array_filter(
            app(NavSectionRegistry::class)->for('operator'),
            fn (NavSection $seat): bool => $seat->key === AnalyticsSection::KEY,
        ));

        $this->assertCount(1, $seats);
        $this->assertTrue($seats[0]->overview);
        $this->assertSame([AnalyticsSection::ENTITLEMENT], $seats[0]->entitlement);
        $this->assertContains(AnalyticsSection::KEY, array_map(fn (NavSection $s): string => $s->key, SectionOverview::flagged('operator', app(NavSectionRegistry::class))));

        $particles = app(ParticleResourceRegistry::class);
        $this->assertSame(AnalyticsSection::KEY, $particles->resolve('analytics-measurement')->section);
        $this->assertSame(['operator'], $particles->realmsFor('analytics-measurement'));
    }

    #[Test]
    public function an_area_reads_in_install_scope_on_a_tenancy_free_install(): void
    {
        $this->assertTrue(app(AnalyticsScope::class)->isInstall());
        $this->assertTrue(app(AnalyticsReading::class)->scope->isInstall());
        $this->assertSame(28, app(AnalyticsReading::class)->period->days);
    }

    /** Area resources are registered at boot; a test that adds an area after boot registers its resource the same way. */
    private function refreshAreaResources(): void
    {
        app(\Splicewire\Beam\Analytics\Areas\AnalyticsAreaResources::class)->register();
    }

    /** @return list<string> */
    private function manifestResourceKeys(array $manifest): array
    {
        $keys = [];
        foreach ((array) ($manifest['resources'] ?? $manifest['data']['resources'] ?? []) as $key => $resource) {
            $keys[] = is_string($key) ? $key : (string) ($resource['key'] ?? '');
        }

        return $keys;
    }
}
