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
 * A figure's `href` through a destination the host binds. Every step fails closed to null: no answer from the host's
 * {@see AnalyticsDestinations} binding, an answer with no ability, a viewer who does not pass the ability, and then, for a
 * named-route destination, a route the host does not have or parameters the route cannot be built from, or, for a
 * client-leaf destination, a href that is not site-relative.
 *
 * The viewer is the request's user, asked through the Gate. The href is relative, as the family's other links are — a
 * named route resolved relative, a client leaf drawn only when it is a site-relative path (ticket 24). The port is
 * resolved on every call rather than held, so a host's rebinding (per request or per scope) takes effect.
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

        if ($destination === null || trim($destination->ability) === '') {
            return null;
        }

        if (! $this->gate->allows($destination->ability)) {
            return null;
        }

        // A client-leaf destination (ticket 24): the host names an SPA page's own path, because that page is a client
        // leaf, not a Laravel route. Drawn only when site-relative, so it can never open off-site.
        if ($destination->leafHref !== null) {
            return $this->relativeLeaf($destination->leafHref);
        }

        if (! $this->router->has($destination->routeName)) {
            return null;
        }

        try {
            return $this->url->route($destination->routeName, $destination->parameters, false);
        } catch (UrlGenerationException) {
            return null;
        }
    }

    /**
     * A client-leaf href drawn only when it is a site-relative path: a single leading slash, no scheme and no authority,
     * as the family's other links are. Anything that could leave the site — an absolute URL, a protocol-relative `//host`
     * or `/\host`, a scheme or a host — fails closed to null.
     */
    private function relativeLeaf(string $href): ?string
    {
        // Reject any ASCII control character (C0 range \x00-\x1F and DEL \x7F) anywhere in the href, before any other
        // check. A browser's URL parser strips an internal TAB, LF or CR, so "/\t/evil.example" — whose raw bytes show no
        // "//" and whose parse_url reports no host — would navigate off-site once stripped. Fail closed on all controls.
        if (preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return null;
        }

        $href = trim($href);

        if ($href === '' || $href[0] !== '/' || str_starts_with($href, '//') || str_starts_with($href, '/\\')) {
            return null;
        }

        if (parse_url($href, PHP_URL_SCHEME) !== null || parse_url($href, PHP_URL_HOST) !== null) {
            return null;
        }

        return $href;
    }
}
