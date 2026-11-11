<?php

namespace Splicewire\Beam\Analytics\Areas;

use Illuminate\Container\Container;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Particle\Backing\Unpaged;

/**
 * One Analytics Area as a resource: its figures, one {@see AnalyticsFigureData} row each, measured for the request's
 * {@see AnalyticsReading}.
 *
 * An INSTANCE backing, because it carries the area's key and every area shares this class (exec's card backing has the
 * same shape for the same reason). Model-less, streams only and {@see Unpaged}: an area is a handful of figures on one
 * screen. It never writes and has no record page (`showable: false`).
 *
 * ⚠️ Not memoized. This instance lives in the particle registry for the life of the process, so a memo would serve the
 * first request's figures to every later one. Each read measures afresh.
 */
final class AnalyticsAreaBacking implements Unpaged
{
    public function __construct(
        public readonly string $area,
    ) {}

    /** Every figure, one page. `$filters` is ignored, since the backing declares no filter, and `$perPage` by {@see Unpaged}. */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $rows = $this->figures();

        return new CursorPaginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
    }

    /**
     * The area's figures for `$reading`, the request's when null. ({@see PeriodAreaBacking} passes the reading its facet asks for.)
     *
     * @return list<AnalyticsFigureData>
     */
    public function figures(?AnalyticsReading $reading = null): array
    {
        $container = Container::getInstance();
        $areas = $container->make(AnalyticsAreaRegistry::class);
        $class = $areas->tryResolve($this->area);

        if (! is_string($class)) {
            return []; // the area left the registry: an honest absence, never an invented zero
        }

        return $areas->figures(AreaDefinition::of($class), $reading ?? $container->make(AnalyticsReading::class));
    }
}
