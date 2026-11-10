<?php

namespace Splicewire\Beam\Analytics\Areas;

use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;

/**
 * An Analytics Area, drawn as the `analytics-events` resource: a page listing its figures, and a card on the Analytics
 * section's overview. Register it in `beam.analytics.areas` from your provider, naming the registry as a string so it costs
 * no composer edge:
 *
 *     $this->app->afterResolving('Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry', fn ($areas) => $areas
 *         ->add(EventsArea::class, 'vendor/package'));
 *
 * Measure only what you can read for `$reading->scope` over `$reading->period`. An area with nothing to measure returns no
 * figures, never an invented zero.
 */
#[Area(key: 'events', label: 'Events', icon: 'ListChecks', order: 20, ability: null)]
final class EventsArea implements AnalyticsArea
{
    /** @return list<AnalyticsFigureData> */
    public function measure(AnalyticsReading $reading): array
    {
        return [];
    }
}
