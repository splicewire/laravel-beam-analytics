<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Illuminate\Container\Container;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Particle\Backing\Unpaged;

/**
 * One Traffic panel as a resource: its rows ({@see TrafficPanels::rows()}) for the request's {@see AnalyticsReading}, from the
 * cached reading only. An instance backing, because every panel shares this class, and not memoized, for the same reason as
 * the area backing: it lives for the process, so a memo would serve the first request's rows to every later one.
 */
final class TrafficPanelBacking implements Unpaged
{
    public function __construct(
        public readonly string $panel,
    ) {}

    /** Every row, one page. `$filters` is ignored, since the backing declares no filter, and `$perPage` by {@see Unpaged}. */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $container = Container::getInstance();
        $reading = $container->make(AnalyticsReading::class);
        $rows = TrafficPanels::rows($this->panel, $container->make(TrafficReadings::class)->status($reading->scope, $reading->period));

        return new CursorPaginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
    }
}
