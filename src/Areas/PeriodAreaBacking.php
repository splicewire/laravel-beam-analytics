<?php

namespace Splicewire\Beam\Analytics\Areas;

use Illuminate\Container\Container;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\Unpaged;

/**
 * The page of an area that {@see MeasuresOverPeriod} (ticket 09): {@see AnalyticsAreaBacking}'s figures, measured over the
 * period its `period` facet asks for (`filter[period]`; the default, the overview's window, for an unknown value). The scope
 * is always the request's, so a filter cannot name another scope, and each period reads its own cached reading.
 *
 * An instance backing, not memoized, for the same reasons as {@see AnalyticsAreaBacking}.
 */
final class PeriodAreaBacking implements DeclaresFilterVocabulary, Unpaged
{
    public function __construct(
        public readonly string $area,
    ) {}

    /** The one facet the page offers: its period ({@see AnalyticsPeriod::facet()}), and the backing applies it. */
    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(AnalyticsPeriod::facet());
    }

    /** Every figure, one page, over the requested period. `$perPage` is ignored, by {@see Unpaged}. */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $rows = $this->figures($filters);

        return new CursorPaginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
    }

    /** @return list<AnalyticsFigureData> */
    public function figures(array $filters = []): array
    {
        $reading = Container::getInstance()->make(AnalyticsReading::class)->forFilters($filters);

        return (new AnalyticsAreaBacking($this->area))->figures($reading);
    }
}
