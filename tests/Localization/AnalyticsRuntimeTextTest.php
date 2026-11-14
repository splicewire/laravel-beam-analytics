<?php

namespace Splicewire\Beam\Analytics\Tests\Localization;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\AreaDefinition;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Areas\EventsArea;
use Splicewire\Beam\Analytics\Contracts\MeasurementSettings;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Analytics\Providers\GtmProvider;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Analytics\Tests\Section\SectionTestCase;
use Splicewire\Beam\Analytics\Tests\Support\FakeTrafficSource;
use Splicewire\Beam\Analytics\Traffic\TrafficNeedsQueueWorker;
use Splicewire\Beam\Analytics\Traffic\TrafficReadings;
use Splicewire\Beam\Analytics\Traffic\TrafficSourceRegistry;
use Splicewire\Beam\Analytics\Traffic\TrafficStatus;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

class AnalyticsRuntimeTextTest extends SectionTestCase
{
    private array $templates = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        FakeTrafficSource::reset();
        config(['app.debug' => false, 'beam.localization.pseudo' => true, 'beam.localization.http_profile' => true,
            'beam.localization.supported' => ['en', 'en-XA'], 'queue.default' => 'sync', 'queue.connections.sync.driver' => 'sync']);
    }

    protected function tearDown(): void
    {
        foreach ($this->templates as $file) {
            unlink($file);
        }
        FakeTrafficSource::reset();
        parent::tearDown();
    }

    public function test_measurement_operator_and_tenant_pages_and_cards_have_exact_population_and_runtime_text(): void
    {
        $reader = $this->reader('labels@example.test', ['os.operate', 'analytics.view']);
        $this->defineGrants(['os.operate', 'analytics.view']);
        $file = tempnam(sys_get_temp_dir(), 'analytics26-');
        $this->templates[] = $file;
        file_put_contents($file, '@beamAnalyticsHead @beamAnalyticsBody');
        config(['beam.analytics.providers' => ['custom-provider' => ['adapter' => GtmProvider::class, 'id' => 'RAW-ID']],
            'beam.analytics.enabled_envs' => ['host-env'], 'beam.analytics.consent.enabled' => false,
            'beam.analytics.root_templates' => [$file]]);
        app()->instance(MeasurementSettings::class, new class implements MeasurementSettings
        {
            public function providerIds(AnalyticsScope $scope): array
            {
                return config('beam.analytics.providers') === [] ? [] : ['custom-provider' => $scope->isTenant() ? 'RAW-TENANT' : 'RAW-ID'];
            }
        });
        foreach ([null, 'tenant-localization'] as $tenant) {
            $this->scope($tenant);
            foreach (['en', 'en-XA'] as $locale) {
                $rows = $this->population($reader, 'analytics-measurement', ['providers', 'environments', 'consent', 'placement'], $locale, $tenant);
                self::assertSame('custom-provider ('.($tenant === null ? 'RAW-ID' : 'RAW-TENANT').')', $rows['providers']['value']);
                self::assertSame('host-env', $rows['environments']['value']);
                if ($locale === 'en') {
                    self::assertSame('Off', $rows['consent']['value']);
                    self::assertSame('Placed in 1 root template', $rows['placement']['value']);
                    self::assertSame(['Providers on', 'Allowed environments', 'Consent regions', 'Slot placement'], array_column($rows, 'label'));
                } else {
                    foreach ($rows as $row) {
                        self::assertStringContainsString('⟦', $row['label']);
                    }
                    self::assertStringContainsString('⟦', $rows['consent']['value']);
                    self::assertStringContainsString('⟦', $rows['placement']['value']);
                }
            }
        }
        foreach ([
            [[], '', 'Unverified: no root template found'],
            [[$file], '<head></head>', 'Not placed in 1 root template'],
            [[$file, $file], '<head></head>', 'Not placed in 2 root templates'],
            [[$file, $file], '@beamAnalyticsHead @beamAnalyticsBody', 'Placed in 2 root templates'],
        ] as [$files, $contents, $expected]) {
            file_put_contents($file, $contents);
            config(['beam.analytics.root_templates' => $files, 'beam.analytics.providers' => [], 'beam.analytics.enabled_envs' => [],
                'beam.analytics.consent.enabled' => true, 'beam.analytics.consent.denied_regions' => ['raw-region']]);
            foreach (['en', 'en-XA'] as $locale) {
                $rows = $this->population($reader, 'analytics-measurement', ['providers', 'environments', 'consent', 'placement'], $locale, 'tenant-localization');
                $this->text($rows['placement']['value'], $expected, $locale);
                $this->text($rows['providers']['value'], 'None', $locale);
                $this->text($rows['environments']['value'], 'None', $locale);
                self::assertSame('raw-region', $rows['consent']['value']);
            }
        }
    }

    public function test_traffic_pages_and_cards_cover_cached_states_and_never_call_a_paid_source(): void
    {
        $reader = $this->reader('traffic@example.test', ['os.operate', 'analytics.view']);
        $this->defineGrants(['os.operate', 'analytics.view']);
        foreach ([null, 'tenant-localization'] as $tenant) {
            $this->scope($tenant);
            foreach (['en', 'en-XA'] as $locale) {
                $rows = $this->population($reader, 'analytics-traffic', ['status'], $locale, $tenant);
                $this->text($rows['status']['value'], 'Not connected', $locale);
            }
        }
        app(TrafficSourceRegistry::class)->add(FakeTrafficSource::class, 'acme/fake');
        foreach ([null, 'tenant-localization'] as $tenant) {
            $this->scope($tenant);
            $scope = $tenant === null ? AnalyticsScope::install() : AnalyticsScope::tenant($tenant);
            $scopeKey = FakeTrafficSource::scopeKey($scope);
            FakeTrafficSource::$readings[$scopeKey] = FakeTrafficSource::snapshot(7);
            $key = app(TrafficReadings::class)->key($scope, AnalyticsPeriod::overview());
            foreach (['en', 'en-XA'] as $locale) {
                Cache::forget($key);
                $rows = $this->population($reader, 'analytics-traffic', ['status'], $locale, $tenant);
                $expected = $tenant === null ? 'Not measured yet' : 'Not measured yet ('.TrafficNeedsQueueWorker::REASON.')';
                $this->text($rows['status']['value'], $expected, $locale);
                Cache::forever($key, ['failed_at' => '2026-11-01T01:02:00Z']);
                $rows = $this->population($reader, 'analytics-traffic', ['status'], $locale, $tenant);
                $this->text($rows['status']['value'], "Couldn't be measured (2026-11-01 01:02 UTC)", $locale);
                self::assertSame(SummaryFigureData::TONE_WARN, $rows['status']['tone']);
                foreach ([null, false, true] as $failed) {
                    Cache::forever($key, ['snapshot' => FakeTrafficSource::snapshot(7)->toArray(), 'source' => 'raw-source',
                        'measured_at' => $failed === null ? '2099-01-01T01:02:00Z' : '2000-01-01T01:02:00Z', ...($failed ? ['failed_at' => '2026-11-01T01:02:00Z'] : [])]);
                    $before = Cache::get($key);
                    $rows = $this->population($reader, 'analytics-traffic', ['users', 'sessions', 'views', 'measured'], $locale, $tenant);
                    self::assertSame([7, 14, 21], [$rows['users']['value'], $rows['sessions']['value'], $rows['views']['value']]);
                    self::assertStringContainsString($failed === null ? '2099-01-01 01:02 UTC' : '2000-01-01 01:02 UTC', $rows['measured']['value']);
                    $note = $failed ? 'last refresh failed' : ($tenant === null ? 'refreshing' : "Can't refresh (".TrafficNeedsQueueWorker::REASON.')');
                    $this->text($rows['measured']['value'], $failed === null ? '2099-01-01 01:02 UTC · Last 28 days' : '2000-01-01 01:02 UTC · Last 28 days · '.$note, $locale);
                    self::assertSame($before, Cache::get($key));
                }
            }
        }
        self::assertSame([], FakeTrafficSource::$asked);
    }

    public function test_period_native_options_and_search_are_nonempty_and_use_rendered_labels(): void
    {
        $reader = $this->reader('period@example.test', ['os.operate', 'analytics.view']);
        $this->defineGrants(['os.operate', 'analytics.view']);
        $uri = '/frame/resources/analytics-traffic/filters/options/'.AnalyticsPeriod::OPTIONS_SOURCE;
        foreach (['en', 'en-XA'] as $locale) {
            $options = $this->actingAs($reader)->withHeaders(['Accept-Language' => $locale])->getJson($uri)->assertOk()->json('data');
            self::assertIsArray($options);
            self::assertCount(3, $options);
            self::assertSame(['7', '28', '90'], array_column($options, 'value'));
            foreach ($options as $row) {
                $this->text($row['label'], 'Last '.$row['value'].' days', $locale);
            }
            $search = $this->actingAs($reader)->getJson($uri.'?search=90')->assertOk()->json('data');
            self::assertCount(1, $search);
            self::assertSame('90', $search[0]['value']);
            self::assertSame([], $this->actingAs($reader)->getJson($uri.'?search=nonexistent-token')->assertOk()->json('data'));
        }
    }

    public function test_event_and_source_labels_remain_raw_through_real_endpoints(): void
    {
        $reader = $this->reader('raw@example.test', ['os.operate', 'analytics.view']);
        $this->defineGrants(['os.operate', 'analytics.view']);
        config(['beam.analytics.events' => ['host_custom_event' => ['host_parameter']]]);
        app(TrafficSourceRegistry::class)->add(FakeTrafficSource::class, 'acme/fake');
        FakeTrafficSource::$readings['install'] = FakeTrafficSource::snapshot(1);
        $key = app(TrafficReadings::class)->key(AnalyticsScope::install(), AnalyticsPeriod::overview());
        $entry = ['snapshot' => FakeTrafficSource::snapshot(1)->toArray(), 'source' => 'raw-provider', 'measured_at' => '2099-01-01T00:00:00Z'];
        Cache::forever($key, $entry);
        foreach (['en', 'en-XA'] as $locale) {
            $events = $this->actingAs($reader)->withHeaders(['Accept-Language' => $locale])->getJson('/frame/resources/analytics-events')->assertOk()->json('data');
            self::assertIsArray($events);
            self::assertNotEmpty($events);
            $byId = array_column($events, null, 'id');
            self::assertArrayHasKey('host_custom_event', $byId);
            self::assertSame('host_custom_event', $byId['host_custom_event']['label']);
            self::assertStringContainsString('host_parameter', $byId['host_custom_event']['value']);
            foreach (['pages' => ['/pricing', '/'], 'referrers' => ['google', 'newsletter'], 'trend' => ['2026-11-08', '2026-11-09', '2026-11-10']] as $panel => $labels) {
                $rows = $this->actingAs($reader)->getJson('/frame/resources/analytics-traffic-'.$panel)->assertOk()->json('data');
                self::assertIsArray($rows);
                self::assertCount(count($labels), $rows);
                self::assertSame($labels, array_column($rows, 'label'));
            }
            self::assertSame($entry, Cache::get($key));
        }
        self::assertSame([], FakeTrafficSource::$asked);
    }

    public function test_nullable_dates_custom_reasons_and_host_values_are_preserved_without_literal_inference(): void
    {
        foreach (['en', 'en-XA'] as $locale) {
            app()->setLocale($locale);
            $this->text(TrafficStatus::at(null), 'unknown', $locale);
            self::assertSame('2026-11-01 01:02 UTC', TrafficStatus::at(CarbonImmutable::parse('2026-11-01T01:02:00Z')));
            $raw = '<host custom reason / untranslated>';
            self::assertStringContainsString($raw, TrafficStatus::notMeasured($raw)->headline());
            self::assertStringContainsString($raw, TrafficStatus::measured(FakeTrafficSource::snapshot(1), CarbonImmutable::now(), true, reason: $raw)->refreshNote());
            self::assertNull(TrafficStatus::measured(FakeTrafficSource::snapshot(1), CarbonImmutable::now(), false)->refreshNote());
            foreach (['host', 'package'] as $source) {
                $by = $source === 'host' ? 'the host' : 'a package';
                $this->text(EventsArea::declared($source, []), 'Declared by '.$by.' · no parameters', $locale);
                self::assertStringContainsString('raw_parameter', EventsArea::declared($source, ['raw_parameter']));
            }
            $figures = app(AnalyticsAreaRegistry::class)->figures(AreaDefinition::of(AuthoredFigureArea::class), app(AnalyticsReading::class));
            self::assertCount(1, $figures);
            self::assertSame('authored-label', $figures[0]->label);
            self::assertSame('authored-value', $figures[0]->value);
        }
        self::assertSame('needs a queue worker to measure a tenant', TrafficNeedsQueueWorker::REASON);
        self::assertSame('beam-analytics::', app(TextNamespaces::class)->ownerOfClass(EventsArea::class)->keyPrefix());
    }

    private function population($reader, string $resource, array $ids, string $locale, ?string $tenant): array
    {
        $response = $this->actingAs($reader)->withHeaders(['Accept-Language' => $locale])->getJson('/frame/resources/'.$resource)->assertOk();
        $summary = $this->actingAs($reader)->getJson('/frame/resources/'.$resource.'/summary')->assertOk();
        $rows = $response->json('data');
        $figures = $summary->json('figures');
        if ($path = getenv('ANALYTICS_LOCALIZATION_SNAPSHOT')) {
            file_put_contents($path, json_encode(['resource' => $resource, 'locale' => $locale, 'tenant' => $tenant,
                'status' => $response->status(), 'ids' => is_array($rows) ? array_column($rows, 'id') : null,
                'summaryIds' => is_array($figures) ? array_column($figures, 'key') : null, 'body' => $response->json(), 'summary' => $summary->json()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
        self::assertIsArray($rows);
        self::assertNotEmpty($rows);
        self::assertSame($ids, array_column($rows, 'id'));
        self::assertIsArray($figures);
        self::assertNotEmpty($figures);
        self::assertSame($ids, array_column($figures, 'key'));
        if ($locale === 'en-XA') {
            self::assertStringContainsString('⟦', $summary->json('label'));
        }
        $labels = $resource === 'analytics-measurement'
            ? ['providers' => 'Providers on', 'environments' => 'Allowed environments', 'consent' => 'Consent regions', 'placement' => 'Slot placement']
            : ['status' => 'Traffic', 'users' => 'Users', 'sessions' => 'Sessions', 'views' => 'Views', 'measured' => 'Measured'];
        foreach ($rows as $i => $row) {
            $this->text($row['label'], $labels[$row['id']], $locale);
            self::assertSame($row['label'], $figures[$i]['label']);
            self::assertSame($row['value'], $figures[$i]['value']);
        }

        return array_column($rows, null, 'id');
    }

    private function text(string $actual, string $english, string $locale): void
    {
        if ($locale === 'en') {
            self::assertSame($english, $actual);
        } else {
            self::assertStringContainsString('⟦', $actual);
            self::assertStringNotContainsString('beam-analytics::', $actual);
        }
    }

    private function scope(?string $id): void
    {
        if ($id === null) {
            app()->forgetInstance(TenancyContext::class);
            app()->offsetUnset(TenancyContext::class);
        } else {
            app()->instance(TenancyContext::class, new class($id) implements TenancyContext
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
        }
        app()->forgetScopedInstances();
    }
}

#[Area(key: 'authored-localization-control', label: 'authored-area', icon: 'Gauge')]
final class AuthoredFigureArea implements AnalyticsArea
{
    public function measure(AnalyticsReading $reading): array
    {
        return [new AnalyticsFigureData('authored-id', 'authored-label', 'authored-value')];
    }
}
