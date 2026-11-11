<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Illuminate\Container\Container;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\Unpaged;

/**
 * One Traffic panel as a resource: its rows ({@see TrafficPanels::rows()}) for the request's {@see AnalyticsReading} over the
 * period its `period` facet asks for (ticket 09), from the cached reading only. An instance backing, because every panel shares
 * this class, and not memoized, for the same reason as the area backing: it lives for the process, so a memo would serve the
 * first request's rows to every later one.
 */
final class TrafficPanelBacking implements DeclaresFilterVocabulary, Unpaged
{
    public function __construct(
        public readonly string $panel,
    ) {}

    /** The one facet a panel page offers: its period ({@see AnalyticsPeriod::facet()}). */
    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(AnalyticsPeriod::facet());
    }

    /** Every row, one page, over the period `filter[period]` asks for (the default for an unknown one). */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $container = Container::getInstance();
        $reading = $container->make(AnalyticsReading::class)->forFilters($filters);
        $rows = TrafficPanels::rows($this->panel, $container->make(TrafficReadings::class)->status($reading->scope, $reading->period));

        return new CursorPaginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
    }
}
