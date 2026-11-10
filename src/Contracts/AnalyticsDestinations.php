<?php

namespace Splicewire\Beam\Analytics\Contracts;

use Splicewire\Beam\Analytics\Destinations\AnalyticsDestination;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * Where a named destination opens for a scope's site: the page an area's figure leads to, such as the site editor or Site
 * settings. Only the host knows those routes, because they differ by realm and host shape, and this package keeps
 * topology and host knowledge out (ADR-0242). So the host binds this port, and this package names destinations only.
 *
 * The default ({@see \Splicewire\Beam\Analytics\Destinations\NoDestinations}) answers nothing, so a host that binds nothing
 * draws no links. An answer is a route name, its parameters and the ability that opens it, and
 * {@see \Splicewire\Beam\Analytics\Destinations\DestinationLinks} draws its href only where that route exists and the
 * viewer passes that ability. A destination adds a link to a figure the reader can already see; it never decides who sees
 * the area.
 */
interface AnalyticsDestinations
{
    /** The site's page editor, where an Analytics Event is attached to a block: Events links each row here. */
    public const SITE_EDITOR = 'site-editor';

    /** The site's settings, where its measurement ids are set: Measurement links its providers figure here. */
    public const SITE_SETTINGS = 'site-settings';

    /**
     * Where a site's traffic source is connected: Traffic's "Not connected" figure links here, its way forward. No host names
     * this page yet (connecting a real source is ticket 14), so today the figure draws with no link.
     */
    public const TRAFFIC_SOURCES = 'traffic-sources';

    /** Where `$name` opens for `$scope`'s site, or null when the host has no such page for it. */
    public function destination(string $name, AnalyticsScope $scope): ?AnalyticsDestination;
}
