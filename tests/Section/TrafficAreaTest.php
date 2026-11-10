<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Schemastud\Frame\Attributes\Summary;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Data\TrafficPointData;
use Splicewire\Beam\Analytics\Data\TrafficRankData;
use Splicewire\Beam\Analytics\Destinations\AnalyticsDestination;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Analytics\Tests\Support\FakeTrafficSource;
use Splicewire\Beam\Analytics\Traffic\RefreshTrafficReading;
use Splicewire\Beam\Analytics\Traffic\TrafficReadings;
use Splicewire\Beam\Analytics\Traffic\TrafficScopeMismatch;
use Splicewire\Beam\Analytics\Traffic\TrafficSourceRegistry;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * Traffic (ticket 08): a source behind a read-only port, read from a cache a queued job refreshes, never from the source on a
 * reader's request. The only source here is {@see FakeTrafficSource}.
 */
class TrafficAreaTest extends SectionTestCase
{
    private const SOURCES_ROUTE = 'aa08.traffic-sources';

    private const SOURCES_ABILITY = 'traffic.sources.manage';

    protected function setUp(): void
    {
        parent::setUp();
        FakeTrafficSource::reset();
    }

    protected function tearDown(): void
    {
        FakeTrafficSource::reset();
        parent::tearDown();
    }

    private function analyst(array $extra = []): SectionUser
    {
        $analyst = $this->reader('analyst'.count($this->grants).'@beam.test', ['os.operate', 'analytics.view', ...$extra]);
        $this->defineGrants(['os.operate', 'analytics.view'], [self::SOURCES_ABILITY]);

        return $analyst;
    }

    private function connect(): void
    {
        app(TrafficSourceRegistry::class)->add(FakeTrafficSource::class, 'acme/traffic');
    }

    /** @return list<array{key: string, label: string, value: mixed, tone: ?string}> */
    private function card(SectionUser $reader, string $resource = 'analytics-traffic'): array
    {
        return array_map(
            fn (array $figure): array => ['key' => $figure['key'], 'label' => $figure['label'], 'value' => $figure['value'], 'tone' => $figure['tone'] ?? null],
            $this->actingAs($reader)->getJson("/frame/resources/{$resource}/summary")->assertOk()->json('figures'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function page(SectionUser $reader, string $resource = 'analytics-traffic'): array
    {
        return $this->actingAs($reader)->getJson("/frame/resources/{$resource}")->assertOk()->json('data');
    }

    /** Run the refresh job as a worker would, in whatever tenancy the test has set up. */
    private function refresh(AnalyticsScope $scope): void
    {
        RefreshTrafficReading::for($scope, AnalyticsPeriod::overview())->handle(app(TrafficReadings::class));
    }

    private function insideTenant(?string $id): void
    {
        if ($id === null) {
            $this->app->forgetInstance(TenancyContext::class);
            $this->app->offsetUnset(TenancyContext::class);
        } else {
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
        }
        $this->app->forgetScopedInstances();
    }

    #[Test]
    public function an_empty_source_registry_is_valid_and_traffic_reads_not_connected_with_no_panel(): void
    {
        Bus::fake();
        $analyst = $this->analyst();

        $this->assertSame([], app(TrafficSourceRegistry::class)->keys(), 'this package ships no source');
        $this->assertSame([['key' => 'status', 'label' => 'Traffic', 'value' => 'Not connected', 'tone' => null]], $this->card($analyst));
        $this->assertNull($this->page($analyst)[0]['href'] ?? null, 'no host names a traffic-sources page, so no link is drawn');

        foreach (['analytics-traffic-trend' => 'Traffic trend', 'analytics-traffic-pages' => 'Top pages', 'analytics-traffic-referrers' => 'Referrers'] as $panel => $label) {
            $this->assertSame([['key' => 'status', 'label' => $label, 'value' => 'Not connected', 'tone' => null]], $this->card($analyst, $panel), 'a panel answers with the Traffic card\'s own words, never an invented zero');
            $this->assertSame([], $this->page($analyst, $panel), $panel);
        }
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function not_connected_links_to_the_traffic_sources_destination_only_where_the_route_exists_and_the_viewer_may_open_it(): void
    {
        $this->app->bind(AnalyticsDestinations::class, fn () => new class(self::SOURCES_ROUTE, self::SOURCES_ABILITY) implements AnalyticsDestinations
        {
            public function __construct(private string $route, private string $ability) {}

            public function destination(string $name, AnalyticsScope $scope): ?AnalyticsDestination
            {
                return $name === AnalyticsDestinations::TRAFFIC_SOURCES ? new AnalyticsDestination(routeName: $this->route, ability: $this->ability) : null;
            }
        });
        $plain = $this->analyst();
        $manager = $this->analyst([self::SOURCES_ABILITY]);
        $href = fn (SectionUser $reader): ?string => $this->page($reader)[0]['href'] ?? null;

        $this->assertNull($href($manager), 'the host has no such route yet: fails closed');

        Route::get('/settings/traffic-sources', fn () => 'ok')->name(self::SOURCES_ROUTE);
        Route::getRoutes()->refreshNameLookups();
        $this->assertSame('/settings/traffic-sources', $href($manager), 'the way forward, for a viewer who may open it');
        $this->assertNull($href($plain), 'a viewer without the ability gets no link');
    }

    #[Test]
    public function a_cold_read_says_not_measured_yet_asks_no_source_and_queues_one_refresh(): void
    {
        Bus::fake();
        $this->connect();
        FakeTrafficSource::$readings['install'] = FakeTrafficSource::snapshot(10);
        $analyst = $this->analyst();

        $this->assertSame([['key' => 'status', 'label' => 'Traffic', 'value' => 'Not measured yet', 'tone' => null]], $this->card($analyst));
        $this->card($analyst);
        $this->page($analyst);

        $this->assertSame('Not measured yet', $this->card($analyst, 'analytics-traffic-pages')[0]['value']);
        $this->assertSame([], FakeTrafficSource::$asked, 'a reader\'s request never asks the source');
        Bus::assertDispatchedAfterResponseTimes(RefreshTrafficReading::class, 1);
        Bus::assertDispatchedAfterResponse(RefreshTrafficReading::class, fn (RefreshTrafficReading $job) => $job->tenantId === null && $job->days === AnalyticsPeriod::OVERVIEW_DAYS);
    }

    #[Test]
    public function a_measured_reading_shows_its_totals_and_when_it_was_measured_and_its_panels_draw_through_existing_widgets(): void
    {
        Bus::fake();
        $this->connect();
        FakeTrafficSource::$readings['install'] = FakeTrafficSource::snapshot(10);
        $analyst = $this->analyst();

        $this->travelTo('2026-11-10 12:00:00');
        $this->refresh(AnalyticsScope::install());
        $this->assertSame(['install'], FakeTrafficSource::$asked);

        $this->assertSame([
            ['key' => 'users', 'label' => 'Users', 'value' => 10, 'tone' => null],
            ['key' => 'sessions', 'label' => 'Sessions', 'value' => 20, 'tone' => null],
            ['key' => 'views', 'label' => 'Views', 'value' => 30, 'tone' => null],
            ['key' => 'measured', 'label' => 'Measured', 'value' => '2026-11-10 12:00 UTC · Last 28 days', 'tone' => null],
        ], $this->card($analyst));

        $trend = $this->card($analyst, 'analytics-traffic-trend');
        $this->assertSame(['2026-11-08', '2026-11-09', '2026-11-10'], array_column($trend, 'key'), 'the trend is the days, oldest first');
        $this->assertSame([10, 11, 12], array_column($trend, 'value'), 'plain numbers, which trend-card plots');
        $this->assertSame([['key' => '1', 'label' => '/pricing', 'value' => 20, 'tone' => null], ['key' => '2', 'label' => '/', 'value' => 10, 'tone' => null]], $this->card($analyst, 'analytics-traffic-pages'));
        $this->assertSame(['google', 'newsletter'], array_column($this->card($analyst, 'analytics-traffic-referrers'), 'label'));
        $this->assertCount(3, $this->page($analyst, 'analytics-traffic-trend'));

        $widget = fn (string $class): string|bool|null => (new ReflectionClass($class))->getAttributes(Summary::class)[0]->newInstance()->bind;
        $this->assertSame('trend-card', $widget(TrafficPointData::class));
        $this->assertSame('stat-row', $widget(TrafficRankData::class));
        Bus::assertNothingDispatched();
        $this->assertSame(['install'], FakeTrafficSource::$asked, 'reading the card and the panels asked the source nothing more');
    }

    #[Test]
    public function a_stale_reading_is_still_shown_with_its_time_and_queues_a_refresh(): void
    {
        Bus::fake();
        $this->connect();
        FakeTrafficSource::$readings['install'] = FakeTrafficSource::snapshot(10);
        $analyst = $this->analyst();
        $this->travelTo('2026-11-10 12:00:00');
        $this->refresh(AnalyticsScope::install());

        $this->travelTo('2026-11-10 14:00:00');
        $card = $this->card($analyst);

        $this->assertSame(10, $card[0]['value']);
        $this->assertSame('2026-11-10 12:00 UTC · Last 28 days · refreshing', $card[3]['value']);
        Bus::assertDispatchedAfterResponseTimes(RefreshTrafficReading::class, 1);
        $this->assertSame(['install'], FakeTrafficSource::$asked);
    }

    #[Test]
    public function a_tenant_no_source_covers_reads_not_connected_and_never_the_installs_numbers(): void
    {
        Bus::fake();
        $this->connect();
        FakeTrafficSource::$readings['install'] = FakeTrafficSource::snapshot(10);
        $this->refresh(AnalyticsScope::install());
        $analyst = $this->analyst();

        $this->insideTenant('acme');

        $this->assertSame([['key' => 'status', 'label' => 'Traffic', 'value' => 'Not connected', 'tone' => null]], $this->card($analyst));
        $this->assertSame('Not connected', $this->card($analyst, 'analytics-traffic-trend')[0]['value'], 'nor does its trend show the install\'s');
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function each_tenants_refresh_runs_in_its_own_tenant_and_one_tenants_reading_is_never_served_to_another(): void
    {
        Bus::fake();
        $this->connect();
        FakeTrafficSource::$readings['tenant:acme'] = FakeTrafficSource::snapshot(10);
        FakeTrafficSource::$readings['tenant:globex'] = FakeTrafficSource::snapshot(70);
        $analyst = $this->analyst();

        $this->insideTenant('acme');
        $this->refresh(AnalyticsScope::tenant('acme'));
        $this->assertSame(10, $this->card($analyst)[0]['value'], 'acme reads its own reading');

        $this->insideTenant('globex');
        $this->assertSame('Not measured yet', $this->card($analyst)[0]['value'], 'globex is never served acme\'s cached reading');
        $this->refresh(AnalyticsScope::tenant('globex'));
        $this->assertSame(70, $this->card($analyst)[0]['value']);

        $this->insideTenant('acme');
        $this->assertSame(10, $this->card($analyst)[0]['value'], 'acme still reads its own');
        $this->assertSame(['tenant:acme', 'tenant:globex'], FakeTrafficSource::$asked, 'each source read was for the tenant it ran in');

        $readings = app(TrafficReadings::class);
        $period = AnalyticsPeriod::overview();
        $this->assertNotSame($readings->key(AnalyticsScope::tenant('acme'), $period), $readings->key(AnalyticsScope::tenant('globex'), $period));
        $this->assertNotSame($readings->key(AnalyticsScope::tenant('acme'), $period), $readings->key(AnalyticsScope::install(), $period));
        $this->assertSame('beam-analytics:traffic:tenant:a%3Ab:28d', $readings->key(AnalyticsScope::tenant('a:b'), $period), 'a tenant id cannot forge another key');
    }

    #[Test]
    public function a_refresh_running_outside_its_tenant_asks_no_source_is_reported_and_the_reader_keeps_its_honest_state(): void
    {
        Bus::fake();
        Exceptions::fake();
        $this->connect();
        FakeTrafficSource::$readings['tenant:acme'] = FakeTrafficSource::snapshot(10);
        FakeTrafficSource::$readings['install'] = FakeTrafficSource::snapshot(99);
        $analyst = $this->analyst();

        // A host whose queue does not carry the tenant into the job: acme's refresh runs centrally.
        $this->insideTenant(null);
        $this->refresh(AnalyticsScope::tenant('acme'));

        $this->assertSame([], FakeTrafficSource::$asked, 'it asked no source, so it could not store another scope\'s traffic under acme');
        Exceptions::assertReported(fn (TrafficScopeMismatch $e) => $e->queuedFor->tenantId === 'acme' && $e->runningIn->isInstall());

        $this->insideTenant('acme');
        $this->assertSame('Not measured yet', $this->card($analyst)[0]['value'], 'acme keeps its honest state');
    }

    #[Test]
    public function a_throwing_source_is_reported_drops_to_an_honest_state_and_the_section_stays_up(): void
    {
        Bus::fake();
        Exceptions::fake();
        $this->connect();
        $analyst = $this->analyst();
        FakeTrafficSource::$readings['install'] = new RuntimeException('the provider is down');
        $this->travelTo('2026-11-10 12:00:00');

        $this->refresh(AnalyticsScope::install());

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'the provider is down');
        $this->assertSame([['key' => 'status', 'label' => 'Traffic', 'value' => "Couldn't be measured (2026-11-10 12:00 UTC)", 'tone' => SummaryFigureData::TONE_WARN]], $this->card($analyst));
        $this->actingAs($analyst)->getJson('/frame/resources/analytics-measurement/summary')->assertOk();

        // With an earlier good reading, a failed refresh keeps it and says the refresh failed.
        FakeTrafficSource::$readings['install'] = FakeTrafficSource::snapshot(10);
        $this->travelTo('2026-11-10 14:00:00');
        $this->refresh(AnalyticsScope::install());
        FakeTrafficSource::$readings['install'] = new RuntimeException('down again');
        $this->travelTo('2026-11-10 16:00:00');
        $this->refresh(AnalyticsScope::install());

        $card = $this->card($analyst);
        $this->assertSame(10, $card[0]['value'], 'the last good reading stays');
        $this->assertStringEndsWith('· last refresh failed', $card[3]['value']);
    }

    #[Test]
    public function a_refresh_queued_twice_asks_the_source_once_while_the_reading_is_fresh(): void
    {
        $this->connect();
        FakeTrafficSource::$readings['install'] = FakeTrafficSource::snapshot(10);

        $this->refresh(AnalyticsScope::install());
        $this->refresh(AnalyticsScope::install());

        $this->assertSame(['install'], FakeTrafficSource::$asked);
    }
}
