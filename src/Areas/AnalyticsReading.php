<?php

namespace Splicewire\Beam\Analytics\Areas;

use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * What an area measures for: whose figures (the scope) over which window (the period). Bound scoped, once per request, from
 * the request's {@see AnalyticsScope} and the overview's fixed period.
 */
final class AnalyticsReading
{
    public function __construct(
        public readonly AnalyticsScope $scope,
        public readonly AnalyticsPeriod $period,
    ) {}
}
