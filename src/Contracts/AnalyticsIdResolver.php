<?php

namespace Splicewire\Beam\Analytics\Contracts;

/**
 * ADR-0237 decision 4 — a provider's measurement/container id is resolved PER REQUEST, through this
 * port, rather than read straight from config at construction. The default
 * {@see \Splicewire\Beam\Analytics\Resolvers\ConfigIdResolver} reads config, as before; the tenancy
 * layer rebinds this port for a Site Host (EP-33, the tenancy-rebound id), the way it rebinds the
 * sitemap's base URL — so a Hosted site measures with its own id without this package knowing about
 * tenancy. A null or empty return is "no id": analytics off for that provider (fail-closed).
 */
interface AnalyticsIdResolver
{
    /** The resolved id for the named provider, or null when it has none. */
    public function idFor(string $provider): ?string;
}
