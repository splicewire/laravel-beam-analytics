<?php

namespace Splicewire\Beam\Analytics\Contracts;

use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * The measurement ids a scope's site is set up with, per configured provider: what Measurement shows. It is a read of
 * settings, not of the tag's render path, so Measurement never asks the {@see AnalyticsIdResolver}: on a tenant's app host
 * that port answers with the install's id, which a tenant must never see.
 *
 * The default ({@see \Splicewire\Beam\Analytics\Settings\ConfigMeasurementSettings}) answers the install from config and
 * gives a tenant nothing. A tenancy layer rebinds it so a tenant reads its own Site Settings.
 */
interface MeasurementSettings
{
    /**
     * Every configured provider's id for `$scope`, keyed by provider name; an empty string when the scope's site has none.
     *
     * @return array<string, string>
     */
    public function providerIds(AnalyticsScope $scope): array;
}
