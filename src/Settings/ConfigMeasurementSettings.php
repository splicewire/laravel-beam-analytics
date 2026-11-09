<?php

namespace Splicewire\Beam\Analytics\Settings;

use Illuminate\Contracts\Config\Repository;
use Splicewire\Beam\Analytics\Contracts\MeasurementSettings;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * The default {@see MeasurementSettings}: the install's provider ids, from config. A tenant gets every provider with no id,
 * because the install's ids are not a tenant's, and only a tenancy layer knows where a tenant keeps its own.
 */
final class ConfigMeasurementSettings implements MeasurementSettings
{
    public function __construct(private readonly Repository $config) {}

    public function providerIds(AnalyticsScope $scope): array
    {
        $ids = [];

        foreach ((array) $this->config->get('beam.analytics.providers', []) as $name => $provider) {
            $ids[(string) $name] = $scope->isInstall() ? trim((string) ($provider['id'] ?? '')) : '';
        }

        return $ids;
    }
}
