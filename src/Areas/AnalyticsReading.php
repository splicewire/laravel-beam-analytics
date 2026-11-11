<?php

namespace Splicewire\Beam\Analytics\Areas;

use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * What an area measures for: whose figures (the scope) over which window (the period). Bound scoped, once per request, from
 * the request's {@see AnalyticsScope} and the overview's fixed period. An area page reads its own period from its `period`
 * facet ({@see forFilters()}); the scope is always the request's, so a filter can never name another scope.
 */
final class AnalyticsReading
{
    public function __construct(
        public readonly AnalyticsScope $scope,
        public readonly AnalyticsPeriod $period,
    ) {}

    /** This reading over the period an area page's filter bag asks for, or the default for an unknown one. */
    public function forFilters(array $filters): self
    {
        return new self($this->scope, AnalyticsPeriod::fromFilter($filters[AnalyticsPeriod::FACET] ?? null));
    }
}
