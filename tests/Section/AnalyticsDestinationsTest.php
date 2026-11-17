<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Destinations\AnalyticsDestination;
use Splicewire\Beam\Analytics\Destinations\NoDestinations;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * The destinations port (charter ruling for ticket 07): a figure links to a host page only through a destination the host
 * binds, and only where the route exists and the viewer passes the destination's ability. Proven here on Measurement's
 * providers figure, which links through `site-settings`, with a test binding and a test route.
 */
class AnalyticsDestinationsTest extends SectionTestCase
{
    private const ROUTE = 'aa07.site-settings';

    private const ABILITY = 'site.settings.open';

    protected function setUp(): void
    {
        parent::setUp();

        config(['beam.analytics.providers' => ['gtm' => ['adapter' => \Splicewire\Beam\Analytics\Providers\GtmProvider::class, 'id' => 'GTM-TEST1']]]);
    }

    /** Bind the port to answer `$destinations` by name, and nothing else. */
    private function bindDestinations(array $destinations): void
    {
        $this->app->bind(AnalyticsDestinations::class, fn () => new class($destinations) implements AnalyticsDestinations
        {
            public function __construct(private readonly array $destinations) {}

            public function destination(string $name, AnalyticsScope $scope): ?AnalyticsDestination
            {
                return $this->destinations[$name] ?? null;
            }
        });
    }

    private function siteSettingsRoute(): void
    {
        Route::get('/aa07/site-settings/{site}', fn () => 'settings')->name(self::ROUTE);
        Route::getRoutes()->refreshNameLookups();
    }

    private function settings(array $parameters = ['site' => 'acme']): AnalyticsDestination
    {
        return new AnalyticsDestination(routeName: self::ROUTE, ability: self::ABILITY, parameters: $parameters);
    }

    /** @return array<string, ?string> each Measurement figure's href, keyed by figure id, as the area page serves it */
    private function hrefs(SectionUser $reader): array
    {
        $out = [];
        foreach ($this->actingAs($reader)->getJson('/frame/resources/analytics-measurement')->assertOk()->json('data') as $row) {
            $out[$row['id']] = $row['href'] ?? null;
        }

        return $out;
    }

    private function readers(): array
    {
        $analyst = $this->reader('analyst@beam.test', ['os.operate', 'analytics.view']);
        $settler = $this->reader('settler@beam.test', ['os.operate', 'analytics.view', self::ABILITY]);
        $this->defineGrants(['os.operate', 'analytics.view'], [self::ABILITY]);

        return [$analyst, $settler];
    }

    #[Test]
    public function with_no_binding_the_default_names_no_destination_and_no_figure_links(): void
    {
        [, $settler] = $this->readers();
        $this->siteSettingsRoute();

        $this->assertInstanceOf(NoDestinations::class, app(AnalyticsDestinations::class));
        $this->assertSame(['providers' => null, 'environments' => null, 'consent' => null, 'placement' => null], $this->hrefs($settler));
    }

    #[Test]
    public function a_destination_whose_route_does_not_exist_draws_no_link(): void
    {
        [, $settler] = $this->readers();
        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => $this->settings()]);

        $this->assertNull($this->hrefs($settler)['providers'], 'no route named '.self::ROUTE);
    }

    #[Test]
    public function a_viewer_without_the_destinations_ability_gets_no_link(): void
    {
        [$analyst] = $this->readers();
        $this->siteSettingsRoute();
        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => $this->settings()]);

        $this->assertNull($this->hrefs($analyst)['providers'], 'the analyst reads Measurement but cannot open Site settings');
    }

    #[Test]
    public function a_viewer_with_the_ability_gets_the_relative_link_on_the_providers_figure_only(): void
    {
        [$analyst, $settler] = $this->readers();
        $this->siteSettingsRoute();
        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => $this->settings()]);

        $this->assertSame(
            ['providers' => '/aa07/site-settings/acme', 'environments' => null, 'consent' => null, 'placement' => null],
            $this->hrefs($settler),
        );
        $this->assertNull($this->hrefs($analyst)['providers'], 'control: the same binding, a viewer without the ability');
    }

    #[Test]
    public function a_destination_its_route_cannot_be_built_for_and_one_with_no_ability_draw_no_link(): void
    {
        [, $settler] = $this->readers();
        $this->siteSettingsRoute();

        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => $this->settings(parameters: [])]);
        $this->assertNull($this->hrefs($settler)['providers'], 'the route needs {site}');

        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => new AnalyticsDestination(routeName: self::ROUTE, ability: '', parameters: ['site' => 'acme'])]);
        $this->assertNull($this->hrefs($settler)['providers'], 'a destination that names no ability opens for no one');
    }

    #[Test]
    public function a_destination_cannot_widen_the_areas_gate(): void
    {
        $outsider = $this->reader('outsider@beam.test', ['os.operate', self::ABILITY]);
        $this->defineGrants(['os.operate', 'analytics.view'], [self::ABILITY]);
        $this->siteSettingsRoute();
        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => $this->settings()]);

        $this->actingAs($outsider)->getJson('/frame/resources/analytics-measurement')->assertForbidden();
        $this->actingAs($outsider)->getJson('/frame/resources/analytics-measurement/summary')->assertForbidden();
    }

    /**
     * Ticket 24: on an SPA host the real page is a client leaf, not a Laravel route, so the host names the leaf's own
     * path directly. The link draws for a viewer who passes the ability, with no route registered, and not for one who
     * does not — the ability still gates it, and it is still a relative link.
     */
    #[Test]
    public function a_client_leaf_destination_draws_its_relative_href_only_for_a_viewer_with_the_ability(): void
    {
        [$analyst, $settler] = $this->readers();
        // No siteSettingsRoute(): the leaf is a client page, not a named route.
        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => AnalyticsDestination::leaf('/site/settings', self::ABILITY)]);

        $this->assertSame(
            ['providers' => '/site/settings', 'environments' => null, 'consent' => null, 'placement' => null],
            $this->hrefs($settler),
        );
        $this->assertNull($this->hrefs($analyst)['providers'], 'control: the same leaf binding, a viewer without the ability');
    }

    /** A client leaf is drawn only when it is a site-relative path; anything that could leave the site fails closed. */
    #[Test]
    public function a_client_leaf_destination_that_is_not_site_relative_draws_no_link(): void
    {
        [, $settler] = $this->readers();

        foreach ([
            'https://evil.example/site/settings' => 'an absolute URL',
            '//evil.example/site/settings' => 'a protocol-relative host',
            '/\\evil.example/site' => 'a backslash authority',
            'site/settings' => 'no leading slash',
            '' => 'empty',
        ] as $href => $why) {
            $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => AnalyticsDestination::leaf($href, self::ABILITY)]);
            $this->assertNull($this->hrefs($settler)['providers'], 'a leaf that is '.$why.' opens off-site, so it draws no link');
        }
    }

    /**
     * A browser's URL parser strips an internal TAB/LF/CR, so a href like `/<TAB>/evil.example` — whose raw bytes show no
     * `//` and whose parse_url reports no host — would navigate off-site once stripped. Any ASCII control (C0 or DEL)
     * fails closed (review-r1 / build-qa HIGH, ticket 24). Proven through the PUBLIC drawn figure for a viewer who passes
     * the ability — `hrefs()` is the Measurement resource endpoint — so no off-site href is ever emitted; and a
     * control-free relative path still draws, so the gate does not over-reject.
     */
    #[Test]
    public function a_client_leaf_destination_with_an_ascii_control_character_draws_no_link(): void
    {
        [, $settler] = $this->readers();

        foreach ([
            "/\t/evil.example/site" => 'a TAB between the slashes',
            "/\n/evil.example/site" => 'an LF between the slashes',
            "/\r/evil.example/site" => 'a CR between the slashes',
            "/\x00/evil.example/site" => 'a NUL between the slashes',
            "/\r\\evil.example/site" => 'a CR before a backslash authority',
            "\t//evil.example/site" => 'a leading TAB around the slashes',
            "/site/\x07settings" => 'a control inside an otherwise-relative path',
        ] as $href => $why) {
            $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => AnalyticsDestination::leaf($href, self::ABILITY)]);
            $this->assertNull($this->hrefs($settler)['providers'], 'a leaf with '.$why.' is stripped to an off-site href by a browser, so the figure draws no link');
        }

        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => AnalyticsDestination::leaf('/site/settings/acme', self::ABILITY)]);
        $this->assertSame('/site/settings/acme', $this->hrefs($settler)['providers'], 'a control-free relative path is unaffected by the control gate');
    }

    /** A client-leaf destination that names no ability opens for no one, as a named-route one does not. */
    #[Test]
    public function a_client_leaf_destination_with_no_ability_opens_for_no_one(): void
    {
        [, $settler] = $this->readers();
        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => AnalyticsDestination::leaf('/site/settings', '')]);

        $this->assertNull($this->hrefs($settler)['providers'], 'a leaf destination that names no ability opens for no one');
    }
}
