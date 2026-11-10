<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Splicewire\Beam\Analytics\Data\TrafficPointData;
use Splicewire\Beam\Analytics\Data\TrafficRankData;

/**
 * The three panels drawn beside the Traffic area, each its own read-only resource in the Analytics section: the trend, the top
 * pages and the referrers. They are resources of their own, not figures on the Traffic card, because frame's `trend-card`
 * draws a summary's WHOLE figure list as one series, and a card's widget is the class-level `#[Summary]` on its resource's own
 * data class. Beam has no way to keep a section resource out of the section's nav, so each is also a page there.
 *
 * Every panel reads the same cached reading as the Traffic area ({@see TrafficReadings}), so a panel and the card cannot
 * disagree. Until there is a measured reading a panel's page lists nothing and its card says the state in the Traffic
 * card's own words ({@see TrafficPanelSummaryProvider}).
 */
final class TrafficPanels
{
    public const TREND = 'traffic-trend';

    public const PAGES = 'traffic-pages';

    public const REFERRERS = 'traffic-referrers';

    /** @var array<string, array{label: string, icon: string, order: int, data: class-string}> */
    public const PANELS = [
        self::TREND => ['label' => 'Traffic trend', 'icon' => 'Activity', 'order' => 16, 'data' => TrafficPointData::class],
        self::PAGES => ['label' => 'Top pages', 'icon' => 'FileText', 'order' => 17, 'data' => TrafficRankData::class],
        self::REFERRERS => ['label' => 'Referrers', 'icon' => 'Link2', 'order' => 18, 'data' => TrafficRankData::class],
    ];

    /** The resource key a panel is drawn as. */
    public static function resourceKey(string $panel): string
    {
        return 'analytics-'.$panel;
    }

    /** The panel a resource key draws, or null when it is not a Traffic panel. */
    public static function forResource(string $resourceKey): ?string
    {
        foreach (array_keys(self::PANELS) as $panel) {
            if (self::resourceKey($panel) === $resourceKey) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * A panel's rows for a reading's status: the trend's days (oldest first), or a top list (most first). None until the status
     * is measured, so an unmeasured scope draws no panel.
     *
     * @return list<TrafficPointData|TrafficRankData>
     */
    public static function rows(string $panel, TrafficStatus $status): array
    {
        if (! $status->isMeasured() || $status->snapshot === null) {
            return [];
        }

        if ($panel === self::TREND) {
            $rows = [];
            foreach ($status->snapshot->trend as $day => $views) {
                $rows[] = new TrafficPointData(id: (string) $day, label: (string) $day, value: $views);
            }

            return $rows;
        }

        $list = $panel === self::PAGES ? $status->snapshot->pages : $status->snapshot->referrers;

        return array_values(array_map(
            fn (array $entry, int $i): TrafficRankData => new TrafficRankData(id: (string) ($i + 1), label: $entry['label'], value: $entry['value']),
            $list,
            array_keys($list),
        ));
    }
}
