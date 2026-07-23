<?php

namespace Splicewire\BeamAnalytics\Contracts;

/**
 * A single analytics provider adapter. The whole provider surface is two static
 * markup slots — the `<head>` fragment and the just-after-`<body>` fragment — so a
 * provider needs no React/hydration; the beam directives echo these server-side.
 *
 * An adapter is constructed with its resolved id (see AnalyticsManager). It must
 * return a string from each slot (possibly empty); it must never throw for a
 * merely-misconfigured id — fail-closed is the manager's job, not the adapter's.
 */
interface AnalyticsProvider
{
    /** Markup for `<head>` (e.g. the container loader `<script>`). */
    public function head(): string;

    /** Markup for immediately after `<body>` (e.g. the `<noscript>` fallback). */
    public function body(): string;
}
