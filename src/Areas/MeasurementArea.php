<?php

namespace Splicewire\Beam\Analytics\Areas;

use Illuminate\Contracts\Config\Repository;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Contracts\AnalyticsProvider;
use Splicewire\Beam\Analytics\Contracts\MeasurementSettings;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Analytics\Doctor\AnalyticsWiringAudit;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * Measurement: how this site's tag is set up. Which providers are on (keyed, with an adapter that resolves), the
 * environments allowed to render a tag, the regions where Consent Mode denies analytics storage by default, and whether
 * the tag's two slots are placed in a root template. It shows configuration; it measures no traffic and calls no provider.
 *
 * The ids are the scope's own settings, read through {@see MeasurementSettings}: the install's config, or a tenant's Site
 * Settings where a tenancy layer rebinds the port. The environments, the consent regions and the slot placement are the
 * install's, because a hosted site renders its tag under its install's policy and root template.
 */
#[Area(key: 'measurement', label: 'Measurement', icon: 'Gauge', order: 10)]
final class MeasurementArea implements AnalyticsArea
{
    public function __construct(
        private readonly MeasurementSettings $settings,
        private readonly AnalyticsWiringAudit $audit,
        private readonly Repository $config,
    ) {}

    public function measure(AnalyticsReading $reading): array
    {
        return [
            new AnalyticsFigureData(id: 'providers', label: 'Providers on', value: $this->listed($this->activeProviders($reading->scope))),
            new AnalyticsFigureData(id: 'environments', label: 'Allowed environments', value: $this->listed($this->strings('beam.analytics.enabled_envs'))),
            new AnalyticsFigureData(id: 'consent', label: 'Consent regions', value: $this->consent()),
            new AnalyticsFigureData(id: 'placement', label: 'Slot placement', value: $this->placement()),
        ];
    }

    /**
     * The providers on for this scope's site, each with its id: keyed in the scope's own settings, and its adapter resolves.
     * The ids come from {@see MeasurementSettings}, never from the tag's id resolver, so a tenant sees its own id and never
     * the install's.
     *
     * @return list<string>
     */
    private function activeProviders(AnalyticsScope $scope): array
    {
        $ids = $this->settings->providerIds($scope);
        $on = [];

        foreach ((array) $this->config->get('beam.analytics.providers', []) as $name => $provider) {
            $adapter = $provider['adapter'] ?? null;
            $id = $ids[(string) $name] ?? '';

            if ($id !== '' && is_string($adapter) && is_subclass_of($adapter, AnalyticsProvider::class)) {
                $on[] = "{$name} ({$id})";
            }
        }

        return $on;
    }

    /** The regions where analytics storage is denied until consent, or "Off" when no consent block renders. */
    private function consent(): string
    {
        if (! (bool) $this->config->get('beam.analytics.consent.enabled', true)) {
            return 'Off';
        }

        return $this->listed($this->strings('beam.analytics.consent.denied_regions'));
    }

    private function placement(): string
    {
        [$placed, $checked] = $this->audit->placement();

        if ($checked === []) {
            return 'Unverified: no root template found';
        }

        $count = count($checked);
        $templates = $count === 1 ? '1 root template' : "{$count} root templates";

        return $placed === [] ? "Not placed in {$templates}" : 'Placed in '.(count($placed) === 1 ? '1 root template' : count($placed).' root templates');
    }

    /** @return list<string> */
    private function strings(string $key): array
    {
        return array_values(array_map('strval', (array) $this->config->get($key, [])));
    }

    /** @param  list<string>  $items */
    private function listed(array $items): string
    {
        return $items === [] ? 'None' : implode(', ', $items);
    }
}
