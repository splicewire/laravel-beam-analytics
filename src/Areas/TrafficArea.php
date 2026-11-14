<?php

namespace Splicewire\Beam\Analytics\Areas;

use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Analytics\Destinations\DestinationLinks;
use Splicewire\Beam\Analytics\Traffic\TrafficReadings;
use Splicewire\Beam\Analytics\Traffic\TrafficStatus;

/**
 * Traffic: a site's users, sessions and views over the reading's period, read from a {@see \Splicewire\Beam\Analytics\Contracts\TrafficSource}
 * in `beam.analytics.sources`, with when it was measured. The trend, the top pages and the referrers are drawn by the Traffic
 * panels beside this area ({@see \Splicewire\Beam\Analytics\Traffic\TrafficPanels}), because a trend card draws a whole summary
 * as one series.
 *
 * It reads only the cached reading ({@see TrafficReadings}), so a slow or failing source never slows or breaks the page, and
 * it says honestly which state it is in:
 *
 * - **Not connected:** no source covers this scope. In Tenant scope that is the answer for a tenant no source measures; it
 *   never shows the install's numbers. It links to the host's `traffic-sources` destination, the way forward, for a viewer
 *   who may open it; a host that names no such page (no host does yet) draws it without a link.
 * - **Not measured yet:** a source covers it and no reading has landed; a refresh is queued.
 * - **Measured:** the totals and when they were measured, marked when a refresh is due or the last one failed.
 * - **Couldn't be measured:** the source threw and there is no earlier reading.
 *
 * It {@see MeasuresOverPeriod}: its page offers 7, 28 or 90 days (ticket 09), and the overview card reads 28, labelled.
 *
 * Scaffolded by `splicewire:beam:analytics:make-area TrafficArea` (ticket 08), then filled in.
 */
#[Area(key: 'traffic', label: 'Traffic', icon: 'Activity', order: 15, ability: null)]
final class TrafficArea implements MeasuresOverPeriod
{
    public function __construct(
        private readonly TrafficReadings $readings,
        private readonly DestinationLinks $links,
    ) {}

    /** @return list<AnalyticsFigureData> */
    public function measure(AnalyticsReading $reading): array
    {
        $status = $this->readings->status($reading->scope, $reading->period);

        if (! $status->isMeasured()) {
            return [new AnalyticsFigureData(
                id: 'status',
                label: 'Traffic',
                value: $status->headline(),
                tone: $status->state === TrafficStatus::FAILED ? SummaryFigureData::TONE_WARN : null,
                href: $status->state === TrafficStatus::NOT_CONNECTED
                    ? $this->links->href(AnalyticsDestinations::TRAFFIC_SOURCES, $reading->scope)
                    : null,
            )];
        }

        return [
            new AnalyticsFigureData(id: 'users', label: 'Users', value: $status->snapshot->users),
            new AnalyticsFigureData(id: 'sessions', label: 'Sessions', value: $status->snapshot->sessions),
            new AnalyticsFigureData(id: 'views', label: 'Views', value: $status->snapshot->views),
            new AnalyticsFigureData(id: 'measured', label: 'Measured', value: self::measured($status, $reading)),
        ];
    }

    /** When the totals were measured and over what window, and whether they are due a refresh or the last refresh failed. */
    private static function measured(TrafficStatus $status, AnalyticsReading $reading): string
    {
        $note = $status->refreshNote();

        return __($note === null ? 'beam-analytics::messages.traffic.measured' : 'beam-analytics::messages.traffic.measured_note', [
            'when' => TrafficStatus::at($status->measuredAt), 'period' => $reading->period->label(), 'note' => $note ?? '',
        ]);
    }
}
