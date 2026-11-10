<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Analytics\AnalyticsSection;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AreaDefinition;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Each Traffic panel ({@see TrafficPanels}) as an `analytics-traffic-*` particle resource in the section's realms, in the area
 * resources' shape: read-only, accepting no input, no record page, framed, in the Analytics section, with a summary provider.
 *
 * Its `policy:` is the TRAFFIC AREA's composed ability ({@see AreaDefinition::ability()}), which the area resources define, so
 * a panel opens for exactly the readers the Traffic area opens for. A host that drops the Traffic area draws no panels.
 * Idempotent, like the area resources: the provider runs it at boot and again on `booted()`.
 */
final class TrafficPanelResources
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        if (! $this->container->bound(ParticleResourceRegistry::class)) {
            return; // a host without beam's particle layer draws no section
        }

        $class = $this->container->make(AnalyticsAreaRegistry::class)->tryResolve('traffic');

        if (! is_string($class)) {
            return;
        }

        $ability = AreaDefinition::of($class)->ability();
        $particles = $this->container->make(ParticleResourceRegistry::class);

        foreach (TrafficPanels::PANELS as $panel => $declared) {
            if ($particles->has(TrafficPanels::resourceKey($panel))) {
                continue;
            }

            $particles->register(new ParticleResource(
                key: TrafficPanels::resourceKey($panel),
                backing: new TrafficPanelBacking($panel),
                data: $declared['data'],
                input: false,
                label: $declared['label'],
                policy: $ability,
                icon: $declared['icon'],
                section: AnalyticsSection::KEY,
                navOrder: $declared['order'],
                readOnly: true,
                editable: false,
                showable: false,
                frame: true,
                summaryProvider: TrafficPanelSummaryProvider::class,
            ), AnalyticsSection::REALMS, by: AnalyticsSection::REGISTRANT);
        }
    }
}
