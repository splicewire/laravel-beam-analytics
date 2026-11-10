<?php

namespace Splicewire\Beam\Analytics\Contracts;

use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Analytics\Traffic\TrafficSnapshot;

/**
 * Where a site's traffic is read from: a READ-ONLY port onto a measurement provider (Google Analytics is ticket 14; this
 * package ships no source). A source is registered in `beam.analytics.sources` by its class, and resolved from the container,
 * so it may take its dependencies in its constructor.
 *
 * It is never asked on a reader's request: {@see \Splicewire\Beam\Analytics\Traffic\TrafficReadings} serves the last reading
 * from the cache and asks the source only from the queued refresh job, inside the scope's own tenant. So {@see read()} may
 * make outbound calls and take its time; {@see covers()} must not, because a request asks it to decide which state to show.
 */
interface TrafficSource
{
    /** The source's stable key in `beam.analytics.sources`, kebab-case, such as `google-analytics`. */
    public static function key(): string;

    /**
     * Whether this source measures `$scope`'s site at all: configuration only, never an outbound call. A tenant this source
     * has no property for is not covered, so its Traffic reads "Not connected", never the install's numbers.
     */
    public function covers(AnalyticsScope $scope): bool;

    /**
     * `$scope`'s traffic over `$period`, as one provider-neutral snapshot. Called only by the refresh job, inside the scope's
     * tenant. It may throw: the job reports the throw and the reader keeps an honest state.
     */
    public function read(AnalyticsScope $scope, AnalyticsPeriod $period): TrafficSnapshot;
}
