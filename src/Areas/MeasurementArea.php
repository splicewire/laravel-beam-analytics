<?php

namespace Splicewire\Beam\Analytics\Areas;

use Illuminate\Contracts\Config\Repository;
use Splicewire\Beam\Analytics\AnalyticsManager;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Analytics\Doctor\AnalyticsWiringAudit;

/**
 * Measurement: how this site's tag is set up. Which providers are on (keyed, with an adapter that resolves), the
 * environments allowed to render a tag, the regions where Consent Mode denies analytics storage by default, and whether
 * the tag's two slots are placed in a root template. It shows configuration; it measures no traffic and calls no provider.
 *
 * Install scope reads the install's config, through the manager and the wiring audit that already answer these
 * questions. A tenant's own settings are ticket 05's.
 */
#[Area(key: 'measurement', label: 'Measurement', icon: 'Gauge', order: 10)]
final class MeasurementArea implements AnalyticsArea
{
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly AnalyticsWiringAudit $audit,
        private readonly Repository $config,
    ) {}

    public function measure(AnalyticsReading $reading): array
    {
        return [
            new AnalyticsFigureData(id: 'providers', label: 'Providers on', value: $this->listed($this->activeProviders())),
            new AnalyticsFigureData(id: 'environments', label: 'Allowed environments', value: $this->listed($this->strings('beam.analytics.enabled_envs'))),
            new AnalyticsFigureData(id: 'consent', label: 'Consent regions', value: $this->consent()),
            new AnalyticsFigureData(id: 'placement', label: 'Slot placement', value: $this->placement()),
        ];
    }

    /** @return list<string> the providers that will render where the env allows: keyed, and their adapter resolves */
    private function activeProviders(): array
    {
        return array_keys(array_filter($this->manager->providerStatuses(), fn (array $status): bool => $status['status'] === 'active'));
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
