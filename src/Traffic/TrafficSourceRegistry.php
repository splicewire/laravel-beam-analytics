<?php

namespace Splicewire\Beam\Analytics\Traffic;

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
use Splicewire\Beam\Analytics\Contracts\TrafficSource;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * Every {@see TrafficSource} the Traffic area may read from, keyed by the source's own {@see TrafficSource::key()}. This package
 * ships none, and an EMPTY registry is a valid state: Traffic then reads "Not connected". A package contributes one from its
 * provider, naming this class only as a string, so it costs no composer edge:
 *
 * ```php
 * $this->app->afterResolving('Splicewire\Beam\Analytics\Traffic\TrafficSourceRegistry', fn ($sources) => $sources
 *     ->add(GoogleAnalyticsSource::class, 'acme/google-analytics'));
 * ```
 *
 * The store is held, so a write keeps its registrant and a taken key throws naming both. A class that is not a TrafficSource,
 * or whose key is not kebab-case, refuses here, where it is registered.
 */
#[IsRegistry(
    root: 'beam.analytics.sources',
    entryType: 'class-string<'.TrafficSource::class.'>',
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'the Traffic Sources the Traffic area reads a site\'s traffic from, keyed by source key, each entry a read-only TrafficSource class; empty means Traffic is not connected.',
)]
class TrafficSourceRegistry implements Gated, Registry
{
    private const KEY = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    private ?BasicRegistry $store = null;

    public function __construct(private readonly Container $container) {}

    /**
     * Add a source class under the key it declares.
     *
     * @param  class-string<TrafficSource>  $class
     */
    public function add(string $class, ?string $by = null): static
    {
        return $this->register(self::keyOf($class), $class, $by);
    }

    /**
     * @param  class-string<TrafficSource>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $declared = self::keyOf($entry);

        if ($declared !== (string) $key) {
            throw new InvalidArgumentException(sprintf('The Traffic Source [%s] is registered as [%s], but it declares the key [%s]. Register it with add().', $entry, (string) $key, $declared));
        }

        $this->store()->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * The source that measures `$scope`'s site: the first registered source, in key order, that covers it. Null when none does,
     * which is "Not connected" for that scope. Asks {@see TrafficSource::covers()} only, which makes no outbound call.
     */
    public function sourceFor(AnalyticsScope $scope): ?TrafficSource
    {
        $keys = $this->store()->relativeKeys();
        sort($keys);

        foreach ($keys as $key) {
            $source = $this->container->make($this->resolve($key));

            if ($source->covers($scope)) {
                return $source;
            }
        }

        return null;
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

    /** The registrant that wrote `$key`, such as `acme/google-analytics`. */
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

    /** A source class's declared key, refusing anything that is not a TrafficSource with a kebab-case key. */
    private static function keyOf(mixed $class): string
    {
        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, TrafficSource::class)) {
            throw new InvalidArgumentException(sprintf('A Traffic Source is the class-string of a %s; got %s.', TrafficSource::class, is_string($class) ? "[{$class}]" : get_debug_type($class)));
        }

        $key = $class::key();

        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("[{$class}] declares the source key [{$key}]; a source key is kebab-case.");
        }

        return $key;
    }

    private function store(): BasicRegistry
    {
        return $this->store ??= BasicRegistry::for($this);
    }
}
