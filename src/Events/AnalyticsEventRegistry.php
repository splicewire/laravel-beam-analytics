<?php

namespace Splicewire\Beam\Analytics\Events;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registrant;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Analytics\Registries\DeclaredName;

/**
 * Every Analytics Event a site may report, read as three halves in a fixed order: {@see EventVocabulary::PACKAGE},
 * then what packages contributed, then what the host declares in `config('beam.analytics.events')`.
 *
 * A package contributes from its own provider, naming this class only as a string, so it costs no composer edge:
 *
 * ```php
 * $this->app->afterResolving('Splicewire\Beam\Analytics\Events\AnalyticsEventRegistry', fn ($registry) => $registry
 *     ->register('song_shared', ['song_id'], 'acme/songs'));
 * ```
 *
 * The package's names and every contribution live in a held kernel store, so a write keeps its registrant and ability
 * and a taken key throws naming both registrants, even for an identical repeat. The host's half is not copied in: it is
 * read through and added to a copy of that store on every read, because a host's vocabulary is refused where it stands
 * in config. A contribution is `source: package` in {@see EventVocabulary::all()}, and a host cannot redeclare it any
 * more than it can a name this package ships.
 */
#[IsRegistry(
    root: 'beam.analytics.events',
    entryType: 'list<string>',
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'the Analytics Events a site may report, keyed by GA4 event name, each entry its parameter names. The Manifest Profile serves them as x-events, and a block\'s Click Event must name one.',
)]
class AnalyticsEventRegistry implements Gated, Registry
{
    /** GA4's event-name rule: a letter, then letters, digits or underscores, at most 40 characters. */
    private const NAME = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

    private ?BasicRegistry $held = null;

    public function __construct(private readonly Repository $config) {}

    /**
     * @param  list<string>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = [], ?string $by = null, ?string $ability = null): static
    {
        $name = $this->nameOf($key);

        if (! preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException("Analytics Event [{$name}] is not a GA4 event name: a letter, then letters, digits or underscores, at most 40 characters.");
        }

        $this->assertParameters($name, $entry);
        $this->held()->register(DeclaredName::key($name), $entry, $by, $ability);

        return $this;
    }

    /**
     * Every event this caller may see, in its served order. Validates the host's half first, so a package name cannot
     * answer past a misdeclared host vocabulary.
     *
     * @return array<string, array{source: 'package'|'host', parameters: list<string>}>
     */
    public function events(): array
    {
        $host = $this->hostEvents();
        $store = $this->store($host);
        $events = [];

        foreach ($store->relativeKeys() as $name) {
            $events[$name] = [
                'source' => array_key_exists($name, $host) ? 'host' : 'package',
                'parameters' => $store->resolve(DeclaredName::key($name)),
            ];
        }

        return $events;
    }

    /** Refuse a misdeclared host vocabulary at boot (EP29-SLICE-PLAN contract item 3), not on its first use. */
    public function validate(): void
    {
        $this->hostEvents();
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store()->has($this->keyOf($key));
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store()->resolve($this->keyOf($key));
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store()->tryResolve($this->keyOf($key));
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->store()->matches($this->keyOf($key));
    }

    public function keys(): array
    {
        return $this->store()->keys();
    }

    /** The registrant that wrote `$key`, such as `config:beam.analytics.events` or `acme/songs`. */
    public function registrantOf(RegistryKey|string $key): ?string
    {
        return $this->store()->registrantOf($this->keyOf($key));
    }

    public function unfiltered(): Registry
    {
        return $this->store()->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->held()->authorizeWith($authorizer);

        return $this;
    }

    /** The package's names and the contributions, in the one store a write reaches. */
    private function held(): BasicRegistry
    {
        if ($this->held === null) {
            $this->held = BasicRegistry::for($this);

            foreach (EventVocabulary::PACKAGE as $name => $parameters) {
                $this->held->register(DeclaredName::key($name), $parameters, 'splicewire/laravel-beam-analytics');
            }
        }

        return $this->held;
    }

    /**
     * A copy of the held store with the host's half added, so config can change between reads and nothing of it stays.
     *
     * @param  array<string, list<string>>|null  $host
     */
    private function store(?array $host = null): BasicRegistry
    {
        $host ??= $this->hostEvents();
        $store = clone $this->held();

        foreach ($host as $name => $parameters) {
            $store->register(DeclaredName::key($name), $parameters, Registrant::fromConfig('beam.analytics.events'));
        }

        return $store;
    }

    /** An event name, with this registry's root stripped by segment when the key is rooted. Anything else comes back whole. */
    private function nameOf(RegistryKey|string $key): string
    {
        $parsed = is_string($key) ? Key::tryParse($key) : $key;

        if ($parsed === null) {
            return (string) $key;
        }

        $root = IsRegistry::of($this)->rootKey()->segments();
        $segments = $parsed->segments();

        if (array_slice($segments, 0, count($root)) === $root) {
            $segments = array_slice($segments, count($root));
        }

        return implode(Key::SEPARATOR, $segments);
    }

    private function keyOf(RegistryKey|string $key): RegistryKey
    {
        return is_string($key) ? DeclaredName::key($key) : $key;
    }

    /** @return array<string, list<string>> */
    private function hostEvents(): array
    {
        $taken = $this->held()->unfiltered();
        $events = [];

        foreach ((array) $this->config->get('beam.analytics.events', []) as $name => $parameters) {
            $name = (string) $name;
            if (! preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException("Analytics Event [{$name}] is not a GA4 event name: a letter, then letters, digits or underscores, at most 40 characters.");
            }
            if ($taken->has(DeclaredName::key($name))) {
                throw new InvalidArgumentException("Analytics Event [{$name}] is the package's; a host declares its own names beside it, not over it.");
            }
            $this->assertParameters($name, $parameters);
            $events[$name] = $parameters;
        }

        return $events;
    }

    private function assertParameters(string $name, mixed $parameters): void
    {
        if (! is_array($parameters) || ! array_is_list($parameters) || array_filter($parameters, fn ($p) => ! is_string($p)) !== []) {
            throw new InvalidArgumentException("Analytics Event [{$name}] declares its parameters as a list of names.");
        }
    }
}
