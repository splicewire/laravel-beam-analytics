<?php

namespace Splicewire\Beam\Analytics\Destinations;

/**
 * One answer from {@see \Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations}: the named route a destination opens,
 * the parameters it takes, and the ability a viewer must pass to be shown the link.
 */
final class AnalyticsDestination
{
    /** @param  array<string, mixed>  $parameters */
    public function __construct(
        public readonly string $routeName,
        public readonly string $ability,
        public readonly array $parameters = [],
    ) {}
}
