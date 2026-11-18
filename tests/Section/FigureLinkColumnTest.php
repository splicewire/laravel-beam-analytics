<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Destinations\AnalyticsDestination;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * Analytics-area 31: a figure's link draws in a host's frame list with no host code. An area's page is frame's generic
 * list, and a cell receives only its own field, so the label column is declared as frame's `link` list-column kind
 * (frame-foundation 77, CONFORM 77-1), taking its href from the row's `href`. The client half (the `link` kind drawing
 * a router link, or text for a null href) is frame's and is tested there; this pins what this package sends it: the
 * declaration on every area, and a row `href` that is the figure's link only for a viewer whose destination opens.
 */
class FigureLinkColumnTest extends SectionTestCase
{
    private const ABILITY = 'site.settings.open';

    protected function setUp(): void
    {
        parent::setUp();

        config(['beam.analytics.providers' => ['gtm' => ['adapter' => \Splicewire\Beam\Analytics\Providers\GtmProvider::class, 'id' => 'GTM-TEST1']]]);
    }

    /** @return array{0: SectionUser, 1: SectionUser} an analyst who reads the areas, and one who also opens Site settings */
    private function readers(): array
    {
        $analyst = $this->reader('analyst@beam.test', ['os.operate', 'analytics.view']);
        $settler = $this->reader('settler@beam.test', ['os.operate', 'analytics.view', self::ABILITY]);
        $this->defineGrants(['os.operate', 'analytics.view'], [self::ABILITY]);

        return [$analyst, $settler];
    }

    /** @return array<string, array<string, mixed>> each node's `list-column` entry in `$resource`'s manifest context block */
    private function columns(SectionUser $reader, string $resource): array
    {
        $manifest = $this->actingAs($reader)->getJson('/frame/manifest')->assertOk()->json();
        $byNode = ($manifest['contexts'] ?? $manifest['data']['contexts'] ?? [])[$resource]['byNode'] ?? null;
        $this->assertIsArray($byNode, "the manifest carries {$resource}'s context block");

        return array_map(fn (array $contexts) => $contexts['list-column'] ?? [], $byNode);
    }

    #[Test]
    public function every_areas_figure_label_is_declared_as_a_link_column_over_the_rows_href(): void
    {
        [$analyst] = $this->readers();
        $keys = array_map(fn ($area) => $area->resourceKey(), app(AnalyticsAreaRegistry::class)->definitions());
        $this->assertNotEmpty($keys);

        foreach ($keys as $resource) {
            $columns = $this->columns($analyst, $resource);

            $this->assertTrue($columns['label']['participates'] ?? false, $resource);
            $this->assertSame('link', $columns['label']['widget'] ?? null, $resource);
            $this->assertSame(['hrefKey' => 'href'], $columns['label']['options'] ?? null, $resource);
            $this->assertSame('Figure', $columns['label']['label'] ?? null, $resource);
            $this->assertSame(0, $columns['label']['sort'] ?? null, $resource);

            // Only the label links: the value keeps the default cell, and the href is never a column of its own.
            $this->assertArrayNotHasKey('widget', $columns['value'], $resource);
            $this->assertTrue($columns['value']['participates'] ?? false, $resource);
            $this->assertFalse($columns['href']['participates'] ?? false, $resource);
        }
    }

    /**
     * The field `hrefKey` names is on every row, and it is the figure's link only where the destination opens: the frame
     * cell trusts it, so it must be null for every other viewer, and with no binding.
     */
    #[Test]
    public function the_row_field_the_link_column_names_carries_the_leaf_only_for_a_viewer_whose_destination_opens(): void
    {
        [$analyst, $settler] = $this->readers();
        $hrefKey = $this->columns($settler, 'analytics-measurement')['label']['options']['hrefKey'];

        $rows = fn (SectionUser $reader) => $this->actingAs($reader)->getJson('/frame/resources/analytics-measurement')->assertOk()->json('data');
        $links = function (SectionUser $reader) use ($rows, $hrefKey): array {
            $out = [];
            foreach ($rows($reader) as $row) {
                $this->assertArrayHasKey($hrefKey, $row, "row {$row['id']} carries the field the link column names");
                $out[$row['id']] = $row[$hrefKey];
            }

            return $out;
        };

        $none = ['providers' => null, 'environments' => null, 'consent' => null, 'placement' => null];
        $this->assertSame($none, $links($settler), 'no binding: every label draws as text');

        $this->app->bind(AnalyticsDestinations::class, fn () => new class implements AnalyticsDestinations
        {
            public function destination(string $name, AnalyticsScope $scope): ?AnalyticsDestination
            {
                return $name === AnalyticsDestinations::SITE_SETTINGS ? AnalyticsDestination::leaf('/site/settings', 'site.settings.open') : null;
            }
        });

        $this->assertSame(['providers' => '/site/settings'] + $none, $links($settler));
        $this->assertSame($none, $links($analyst), 'the same binding, a viewer who cannot open Site settings');
    }
}
