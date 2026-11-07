<?php

namespace Splicewire\Beam\Analytics\Events;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
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
 * Contributions are held here, and the host's half is read through on every read, because a host's vocabulary is
 * refused where it stands in config and a contribution has no place in a config file. That also keeps a contribution
 * out of a cached config. A contribution is `source: package` in {@see EventVocabulary::all()}: a host cannot redeclare
 * it any more than it can a name this package ships.
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

    /** @var array<string, array{parameters: list<string>, by: string|null}> */
    private array $contributed = [];

    private ?Authorizer $authorizer = null;

    public function __construct(private readonly Repository $config) {}

    /**
     * @param  list<string>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = [], ?string $by = null, ?string $ability = null): static
    {
        $name = (string) $key;

        if (! preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException("Analytics Event [{$name}] is not a GA4 event name: a letter, then letters, digits or underscores, at most 40 characters.");
        }

        $this->assertParameters($name, $entry);

        if (array_key_exists($name, EventVocabulary::PACKAGE)) {
            throw new InvalidArgumentException("Analytics Event [{$name}] is the package's; contribute a name of your own.");
        }

        if (isset($this->contributed[$name])) {
            if ($this->contributed[$name]['parameters'] !== $entry) {
                throw new InvalidArgumentException("Analytics Event [{$name}] is already contributed with other parameters.");
            }

            return $this;
        }

        $this->contributed[$name] = ['parameters' => $entry, 'by' => $by];

        return $this;
    }

    /**
     * Every declared event in its served order. Validates the host's half first, so a package name cannot answer past a
     * misdeclared host vocabulary.
     *
     * @return array<string, array{source: 'package'|'host', parameters: list<string>}>
     */
    public function events(): array
    {
        $host = $this->hostEvents();
        $events = [];

        foreach (EventVocabulary::PACKAGE as $name => $parameters) {
            $events[$name] = ['source' => 'package', 'parameters' => $parameters];
        }
        foreach ($this->contributed as $name => $contribution) {
            $events[$name] = ['source' => 'package', 'parameters' => $contribution['parameters']];
        }
        foreach ($host as $name => $parameters) {
            $events[$name] = ['source' => 'host', 'parameters' => $parameters];
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
        $this->authorizer = $authorizer;

        return $this;
    }

    /** The three halves projected onto the kernel's store, rebuilt per read because the host's half can change. */
    private function store(): BasicRegistry
    {
        $store = BasicRegistry::for($this)->authorizeWith($this->authorizer);

        foreach ($this->events() as $name => $event) {
            $by = $event['source'] === 'host'
                ? Registrant::fromConfig('beam.analytics.events')
                : ($this->contributed[$name]['by'] ?? 'splicewire/laravel-beam-analytics');

            $store->register(DeclaredName::key($name), $event['parameters'], $by);
        }

        return $store;
    }

    private function keyOf(RegistryKey|string $key): RegistryKey
    {
        return is_string($key) ? DeclaredName::key($key) : $key;
    }

    /** @return array<string, list<string>> */
    private function hostEvents(): array
    {
        $events = [];

        foreach ((array) $this->config->get('beam.analytics.events', []) as $name => $parameters) {
            $name = (string) $name;
            if (! preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException("Analytics Event [{$name}] is not a GA4 event name: a letter, then letters, digits or underscores, at most 40 characters.");
            }
            if (array_key_exists($name, EventVocabulary::PACKAGE) || isset($this->contributed[$name])) {
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
