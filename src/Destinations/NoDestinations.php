<?php

namespace Splicewire\Beam\Analytics\Destinations;

use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/** The default binding: no destination anywhere, so a host that binds nothing draws no links. */
final class NoDestinations implements AnalyticsDestinations
{
    public function destination(string $name, AnalyticsScope $scope): ?AnalyticsDestination
    {
        return null;
    }
}
