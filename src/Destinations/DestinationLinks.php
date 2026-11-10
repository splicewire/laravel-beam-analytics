<?php

namespace Splicewire\Beam\Analytics\Destinations;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\Router;
use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * A figure's `href` through a named destination. Every step fails closed to null: no answer from the host's
 * {@see AnalyticsDestinations} binding, an answer with no ability, a route the host does not have, a viewer who does not
 * pass the ability, or parameters the route cannot be built from.
 *
 * The viewer is the request's user, asked through the Gate. The href is relative, as the family's other links are.
 * The port is resolved on every call rather than held, so a host's rebinding (per request or per scope) takes effect.
 */
final class DestinationLinks
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly UrlGenerator $url,
        private readonly Gate $gate,
    ) {}

    public function href(string $name, AnalyticsScope $scope): ?string
    {
        $destination = $this->container->make(AnalyticsDestinations::class)->destination($name, $scope);

        if ($destination === null || trim($destination->ability) === '' || ! $this->router->has($destination->routeName)) {
            return null;
        }

        if (! $this->gate->allows($destination->ability)) {
            return null;
        }

        try {
            return $this->url->route($destination->routeName, $destination->parameters, false);
        } catch (UrlGenerationException) {
            return null;
        }
    }
}
