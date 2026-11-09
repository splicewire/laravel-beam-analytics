<?php

namespace Splicewire\Beam\Analytics\Areas;

use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;

/**
 * The summary provider for every `analytics-{key}` resource: the area's figures as its card on the section overview.
 *
 * A summary provider is handed only the resource definition, so the area is found by the resource's key, and its figures
 * are measured for the container's {@see AnalyticsReading}, the same reading the area's own page lists.
 *
 * It declines (null) for a key that names no area and for an area that measures nothing: an empty card is an honest
 * absence, and the overview drops it rather than drawing a blank card.
 */
final class AnalyticsAreaSummaryProvider implements ResourceSummaryProvider
{
    public function __construct(
        private readonly AnalyticsAreaRegistry $areas,
        private readonly AnalyticsReading $reading,
    ) {}

    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        $area = $this->areas->forResource($resource->key);

        if ($area === null) {
            return null;
        }

        $figures = $this->areas->figures($area, $this->reading);

        if ($figures === []) {
            return null;
        }

        return new SummaryResponseData(
            key: $resource->key,
            label: $resource->resolvedLabel(),
            icon: $resource->nav->icon,
            figures: array_values(array_map(fn (AnalyticsFigureData $figure) => $figure->toSummaryFigure(), $figures)),
            overview: null,
        );
    }
}
