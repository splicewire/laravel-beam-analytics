<?php

namespace Splicewire\Beam\Analytics\Areas;

use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Analytics\Destinations\DestinationLinks;
use Splicewire\Beam\Analytics\Events\EventVocabulary;

/**
 * Events: the Event Vocabulary, every Analytics Event the site may report, one row each with its parameters and whether a
 * package or the host declared it, in the order the Manifest Profile serves them as `x-events`.
 *
 * A declared event is not one that fired. Nothing in the vocabulary records an emission (ADR-0237 decision 9: no
 * server-side events), so every row says "Declared by …" and never carries a count, a time or a "sent". It reads the same
 * in tenant and install scope, because the vocabulary is the install's.
 *
 * Each row links to the site editor, where an event is attached to a block's Click Event, through the host's
 * `site-editor` destination, and only for a viewer who may open it.
 *
 * Scaffolded by `splicewire:beam:analytics:make-area EventsArea` (ticket 07), then filled in.
 */
#[Area(key: 'events', label: 'Events', icon: 'ListChecks', order: 20, ability: null)]
final class EventsArea implements AnalyticsArea
{
    public function __construct(
        private readonly EventVocabulary $vocabulary,
        private readonly DestinationLinks $links,
    ) {}

    /** @return list<AnalyticsFigureData> */
    public function measure(AnalyticsReading $reading): array
    {
        $href = $this->links->href(AnalyticsDestinations::SITE_EDITOR, $reading->scope);

        return array_map(
            fn (array $event): AnalyticsFigureData => new AnalyticsFigureData(
                id: $event['name'],
                label: $event['name'],
                value: self::declared($event['source'], $event['parameters']),
                href: $href,
            ),
            $this->vocabulary->all(),
        );
    }

    /**
     * What a row says: who declared the event and the parameters it is reported with. Worded as a declaration, never as
     * activity.
     *
     * @param  'package'|'host'  $source
     * @param  list<string>  $parameters
     */
    public static function declared(string $source, array $parameters): string
    {
        $by = $source === 'host' ? 'Declared by the host' : 'Declared by a package';

        return $by.' · '.($parameters === [] ? 'no parameters' : implode(', ', $parameters));
    }
}
