<?php

namespace Splicewire\Beam\Analytics\Providers;

use InvalidArgumentException;
use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Analytics\Contracts\AnalyticsProvider;
use Splicewire\Beam\Analytics\Registries\DeclaredName;

/**
 * The tag providers a site renders. `config('beam.analytics.providers')` is the storage: a registration writes there,
 * so the id resolvers, which read `beam.analytics.providers.<name>.*`, see a contributed provider without an edit.
 *
 * A package contributes from its own provider, naming this class only as a string, so it costs no composer edge:
 *
 * ```php
 * $this->app->afterResolving('Splicewire\Beam\Analytics\Providers\AnalyticsProviderRegistry', fn ($registry) => $registry
 *     ->register('plausible', ['adapter' => PlausibleProvider::class, 'id' => config('plausible.site')]));
 * ```
 *
 * {@see \Splicewire\Beam\Analytics\AnalyticsManager} still reads config and folds the whole map. It holds no population
 * and offers no way in, and this package's provider resolves this registry at boot, so a contribution is already in config
 * when it first reads.
 *
 * The two ways in differ on purpose. A map in config is not validated here: a keyless provider or an unknown adapter
 * renders nothing and the doctor reports it (ADR-0237), so it never refuses a boot. {@see register()} is new code
 * calling new code, so it refuses a misdeclared entry, and a key that names a different adapter. A key that names the
 * same adapter keeps what is stored, which lets a host set a contributed provider's id in its config. It is also what
 * keeps a cached config working, because `config:cache` stores contributions and they register again on every boot.
 */
#[IsRegistry(
    root: 'beam.analytics.providers',
    entryType: 'array{adapter: class-string<'.AnalyticsProvider::class.'>, id?: ?string}',
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'the tag providers a site renders, keyed by name; every keyed one renders, concatenated, in config order. Config is the storage, so the AnalyticsIdResolver implementations read a contributed provider where they read a configured one.',
)]
class AnalyticsProviderRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'beam.analytics.providers';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return DeclaredName::key((string) $index);
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $slot = $this->slotFor(Key::of($key));
        $adapter = is_array($entry) ? ($entry['adapter'] ?? null) : null;

        if (! is_string($adapter) || ! is_subclass_of($adapter, AnalyticsProvider::class)) {
            throw new InvalidArgumentException(sprintf(
                'The analytics provider `%s` must be an array whose `adapter` is the class-string of a %s.',
                $slot,
                AnalyticsProvider::class,
            ));
        }

        if (isset($entry['id']) && ! is_string($entry['id'])) {
            throw new InvalidArgumentException("The analytics provider `{$slot}` must have a string `id`, or none.");
        }

        $existing = $this->entries()[$slot] ?? null;

        if ($existing === null) {
            return parent::register($slot, $entry, $by, $ability);
        }

        if (! is_array($existing) || ($existing['adapter'] ?? null) !== $adapter) {
            throw new InvalidArgumentException("The analytics provider `{$slot}` is already declared with another adapter.");
        }

        return $this;
    }
}
