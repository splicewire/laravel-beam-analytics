<?php

namespace Splicewire\Beam\Analytics\Areas;

use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;

/**
 * One Analytics Area: a part of the Analytics section that measures a handful of figures, drawn as an `analytics-{key}`
 * resource (its page) and as that resource's card on the section's overview. The class carries an {@see Attributes\Area}
 * naming its key, label, icon, order and, optionally, a narrower ability.
 *
 * A package contributes one by registering the class in `beam.analytics.areas`, naming the registry as a string so it
 * needs no composer edge to this package:
 *
 * ```php
 * $this->app->afterResolving('Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry', fn ($areas) => $areas
 *     ->add(AudienceArea::class, 'acme/accounts'));
 * ```
 *
 * The class is resolved from the container, so it may take its dependencies in its constructor.
 */
interface AnalyticsArea
{
    /**
     * What the area measures for this reading. The page and the card both read this, through one path, so they cannot
     * disagree. An area with nothing to measure returns no figures, never an invented zero.
     *
     * @return list<AnalyticsFigureData>
     */
    public function measure(AnalyticsReading $reading): array;
}
