<?php

namespace Splicewire\Beam\Analytics\Destinations;

/**
 * One answer from {@see \Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations}: where a figure's link opens, and the
 * ability a viewer must pass to be shown it. A destination opens in one of two ways, and never widens who sees the area:
 *
 * - a **named route** the host registers ({@see self::__construct}): its name and parameters, which
 *   {@see DestinationLinks} resolves to a relative URL only where that route exists and can be built;
 * - a **client leaf** the host names directly ({@see self::leaf}): the SPA page's own site-relative path, for a host whose
 *   real pages are client leaves and whose named routes are JSON APIs, so a route would open raw JSON, not the page
 *   (ADR-0242, ticket 24). {@see DestinationLinks} draws it only as a site-relative href, never off-site.
 *
 * `$leafHref` discriminates the two: null is a named-route destination, a non-null value is a client leaf (and
 * `$routeName`/`$parameters` are then unused).
 */
final class AnalyticsDestination
{
    /** @param  array<string, mixed>  $parameters */
    public function __construct(
        public readonly string $routeName,
        public readonly string $ability,
        public readonly array $parameters = [],
        public readonly ?string $leafHref = null,
    ) {}

    /**
     * A client-leaf destination: `$href` is the SPA page's own site-relative path (for example `/site/settings`), shown to
     * a viewer who passes `$ability`. The host owns the path; {@see DestinationLinks} draws it only when it is site-relative.
     */
    public static function leaf(string $href, string $ability): self
    {
        return new self(routeName: '', ability: $ability, parameters: [], leafHref: $href);
    }
}
