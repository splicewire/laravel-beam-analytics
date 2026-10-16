<?php

namespace Splicewire\Beam\Analytics\Resolvers;

use Illuminate\Contracts\Config\Repository;
use Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver;

/**
 * ADR-0237 decision 4 — the default id port: a provider's id is whatever its config entry declares,
 * exactly as it was read before this port existed. This is the central site's and a Connected site's
 * resolver; the tenancy layer binds a different one for a Site Host (EP-33). Bound as the default
 * {@see AnalyticsIdResolver} in {@see \Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider}.
 */
class ConfigIdResolver implements AnalyticsIdResolver
{
    public function __construct(private Repository $config) {}

    public function idFor(string $provider): ?string
    {
        $id = $this->config->get("beam.analytics.providers.{$provider}.id");

        return is_string($id) ? $id : null;
    }
}
