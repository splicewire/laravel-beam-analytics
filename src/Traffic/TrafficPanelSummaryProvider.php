<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\OverviewData;
use Schemastud\Frame\Data\SummaryFigureData;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;

/**
 * A Traffic panel's card on the section overview: its rows as figures, which its data class's `#[Summary]` draws (the trend
 * as `trend-card`, the top lists as `stat-row`).
 *
 * It ALWAYS answers for a panel. The panels declare a summary widget, so they are on the dashboard, and beam's
 * DashboardTierAudit flags a dashboard card whose provider declines (the card would silently vanish). Until there is a
 * measured reading with rows, a panel shows the Traffic card's own words for the state ({@see TrafficStatus::headline()}:
 * "Not connected", "Not measured yet", "Couldn't be measured"), or "None measured" when a reading has no rows for it. A
 * `trend-card` draws that one text figure as a gap with its text, never as an invented zero.
 */
final class TrafficPanelSummaryProvider implements ResourceSummaryProvider
{
    public function __construct(
        private readonly TrafficReadings $readings,
        private readonly AnalyticsReading $reading,
    ) {}

    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        $panel = TrafficPanels::forResource($resource->key);

        if ($panel === null) {
            return null;
        }

        $status = $this->readings->status($this->reading->scope, $this->reading->period);
        $rows = TrafficPanels::rows($panel, $status);

        $figures = $rows !== []
            ? array_map(fn ($row): SummaryFigureData => $row->toSummaryFigure(), $rows)
            : [new SummaryFigureData(
                key: 'status',
                label: $resource->resolvedLabel(),
                value: $status->headline() ?? 'None measured',
                tone: $status->state === TrafficStatus::FAILED ? SummaryFigureData::TONE_WARN : null,
            )];

        return new SummaryResponseData(
            key: $resource->key,
            label: $resource->resolvedLabel(),
            icon: $resource->nav->icon,
            figures: $figures,
            overview: new OverviewData(period: $this->reading->period->label()), // the overview card says its window (ticket 09)
        );
    }
}
