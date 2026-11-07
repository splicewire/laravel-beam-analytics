<?php

namespace Splicewire\Beam\Analytics\Registries;

use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryKey;
use Stringable;

/**
 * The registry key of a name a host already declared in config.
 *
 * Config accepted these names before they had registries, and it accepted more than {@see Key} does: GA4 allows
 * `PlayDemo` as an event name, and a provider map key was never checked at all. Those names must keep working, so a
 * name that is a legal {@see Key} stays one, addressable under its registry's root, and any other is carried whole
 * under this foreign key type, reachable through its registry only.
 */
final class DeclaredName implements RegistryKey, Stringable
{
    private function __construct(private readonly string $name) {}

    public static function key(string $name): RegistryKey
    {
        return Key::tryParse($name) ?? new self($name);
    }

    /** @return list<string> */
    public function segments(): array
    {
        return [$this->name];
    }

    public function equals(RegistryKey $other): bool
    {
        return $other->segments() === $this->segments();
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
