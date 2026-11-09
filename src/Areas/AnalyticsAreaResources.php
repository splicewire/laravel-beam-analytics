<?php

namespace Splicewire\Beam\Analytics\Areas;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Analytics\AnalyticsSection;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Each registered area as an `analytics-{key}` particle resource in the section's realms, in exec's card shape: read-only,
 * accepting no input, no record page, framed, in the Analytics section, with a summary provider for its card.
 *
 * Its `policy:` is the area's composed ability ({@see AreaDefinition::ability()}): `analytics.view`, and then the area's
 * own narrower ability if it declares one. The ability is defined here for every area, each time, so it is always this
 * composition and an area's declaration cannot widen it.
 *
 * Idempotent: the provider runs it at boot and again on `booted()`, so route files see every area and an area contributed
 * by a provider that boots later is drawn too.
 */
final class AnalyticsAreaResources
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        if (! $this->container->bound(ParticleResourceRegistry::class)) {
            return; // a host without beam's particle layer draws no section
        }

        $particles = $this->container->make(ParticleResourceRegistry::class);

        foreach ($this->container->make(AnalyticsAreaRegistry::class)->definitions() as $area) {
            $this->defineAbility($area);

            if ($particles->has($area->resourceKey())) {
                continue;
            }

            $particles->register($this->resource($area), AnalyticsSection::REALMS, by: AnalyticsSection::REGISTRANT);
        }
    }

    public function resource(AreaDefinition $area): ParticleResource
    {
        return new ParticleResource(
            key: $area->resourceKey(),
            backing: new AnalyticsAreaBacking($area->key()),
            data: AnalyticsFigureData::class,
            input: false,
            label: $area->area->label,
            policy: $area->ability(),
            icon: $area->area->icon,
            section: AnalyticsSection::KEY,
            navOrder: $area->area->order,
            readOnly: true,
            editable: false,
            showable: false,
            frame: true,
            summaryProvider: AnalyticsAreaSummaryProvider::class,
        );
    }

    private function defineAbility(AreaDefinition $area): void
    {
        $requires = $area->requires();

        $this->container->make(Gate::class)->define(
            $area->ability(),
            function (?Authenticatable $user) use ($requires): bool {
                $gate = $this->container->make(Gate::class)->forUser($user);

                foreach ($requires as $ability) {
                    if (! $gate->allows($ability)) {
                        return false;
                    }
                }

                return true;
            },
        );
    }
}
