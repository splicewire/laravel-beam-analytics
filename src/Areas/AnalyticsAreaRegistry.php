<?php

namespace Splicewire\Beam\Analytics\Areas;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Analytics\AnalyticsSection;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use UnexpectedValueException;

/**
 * Every Analytics Area the section draws, keyed by area key, each entry an {@see AnalyticsArea} class. This package's
 * {@see MeasurementArea} is held from the start; a package contributes beside it from its own provider, naming this class
 * only as a string, so it costs no composer edge:
 *
 * ```php
 * $this->app->afterResolving('Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry', fn ($areas) => $areas
 *     ->add(AudienceArea::class, 'acme/accounts'));
 * ```
 *
 * The store is held, so a write keeps its registrant and a taken key throws naming both. A misdeclared area refuses in
 * {@see AreaDefinition::of()} before it is written, and the provider resolves this registry at boot, so a misdeclared
 * contribution refuses at boot.
 */
#[IsRegistry(
    root: 'beam.analytics.areas',
    entryType: 'class-string<'.AnalyticsArea::class.'>',
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'the Analytics Areas the Analytics section draws, keyed by area key, each entry the AnalyticsArea class drawn as the resource analytics-{key} and as its card on the section overview.',
)]
class AnalyticsAreaRegistry implements Gated, Registry
{
    /** The areas this package ships, in the order they are held. */
    public const PACKAGE = [MeasurementArea::class];

    private ?BasicRegistry $store = null;

    public function __construct(private readonly Container $container) {}

    /**
     * Add an area class under the key its {@see Attributes\Area} declares.
     *
     * @param  class-string<AnalyticsArea>  $class
     */
    public function add(string $class, ?string $by = null): static
    {
        return $this->register(AreaDefinition::of($class)->key(), $class, $by);
    }

    /**
     * @param  class-string<AnalyticsArea>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $definition = AreaDefinition::of($entry);

        if ($definition->key() !== (string) $key) {
            throw new InvalidArgumentException(sprintf(
                'The Analytics Area [%s] is registered as [%s], but its #[Area] declares [%s]. Register it with add().',
                $definition->class,
                (string) $key,
                $definition->key(),
            ));
        }

        $this->store()->register($key, $entry, $by, $ability);

        return $this;
    }

    /** @return list<AreaDefinition> every area, by its declared order and then its key */
    public function definitions(): array
    {
        $definitions = array_map(
            fn (string $key): AreaDefinition => AreaDefinition::of($this->resolve($key)),
            $this->store()->relativeKeys(),
        );

        usort($definitions, fn (AreaDefinition $a, AreaDefinition $b): int => [$a->area->order, $a->key()] <=> [$b->area->order, $b->key()]);

        return $definitions;
    }

    /** The area drawn as resource `$resourceKey`, or null when no area is. */
    public function forResource(string $resourceKey): ?AreaDefinition
    {
        if (! str_starts_with($resourceKey, AreaDefinition::RESOURCE_PREFIX)) {
            return null;
        }

        $class = $this->tryResolve(substr($resourceKey, strlen(AreaDefinition::RESOURCE_PREFIX)));

        return is_string($class) ? AreaDefinition::of($class) : null;
    }

    /**
     * An area's figures for `$reading`: what its class measures. The area's page and its card both read through here,
     * so they cannot disagree.
     *
     * @return list<AnalyticsFigureData>
     */
    public function figures(AreaDefinition $area, AnalyticsReading $reading): array
    {
        $figures = $this->container->make($area->class)->measure($reading);

        foreach ($figures as $figure) {
            if (! $figure instanceof AnalyticsFigureData) {
                throw new UnexpectedValueException(sprintf('The Analytics Area [%s] measured a %s; an area measures %s figures.', $area->class, get_debug_type($figure), AnalyticsFigureData::class));
            }
        }

        return array_values($figures);
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store()->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store()->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store()->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->store()->matches($key);
    }

    public function keys(): array
    {
        return $this->store()->keys();
    }

    /** The registrant that wrote `$key`, such as `splicewire/laravel-beam-analytics` or `acme/accounts`. */
    public function registrantOf(RegistryKey|string $key): ?string
    {
        return $this->store()->registrantOf($key);
    }

    public function unfiltered(): Registry
    {
        return $this->store()->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->store()->authorizeWith($authorizer);

        return $this;
    }

    private function store(): BasicRegistry
    {
        if ($this->store === null) {
            $this->store = BasicRegistry::for($this);

            foreach (self::PACKAGE as $class) {
                $this->store->register(AreaDefinition::of($class)->key(), $class, AnalyticsSection::REGISTRANT);
            }
        }

        return $this->store;
    }
}
