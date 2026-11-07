<?php

namespace Splicewire\Beam\Analytics\Tests\Topology;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * This package's own `extra.package-topology`, checked against its own runtime `require` (ADR-0242).
 *
 * `AssertsDeclaredTopology` reads only vendored manifests, and a package does not vendor itself, so it would run over zero
 * of these rules here and pass by not looking. The graph-level check runs where this package IS vendored: a host's
 * `DeclaredTopologyTest`. `require-dev` is out of scope, matching the tool: its graph is runtime `require` only.
 */
class OwnTopologyDeclarationTest extends TestCase
{
    private const FORBIDDEN = ['splicewire/laravel-beam-ux', 'splicewire/tower', 'splicewire/laravel-satellite', 'splicewire/laravel-tower-*'];

    private const REQUIRED = ['splicewire/laravel-beam', 'schemastud/laravel-frame', 'rushing/laravel-popcorn'];

    private function manifest(): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
        $this->assertIsArray($decoded, 'composer.json could not be read');

        return $decoded;
    }

    private function topology(): array
    {
        $topology = $this->manifest()['extra']['package-topology'] ?? null;

        // A missing declaration must fail. "No rules" and "no violations" are otherwise the same green.
        $this->assertIsArray($topology, 'extra.package-topology is absent from composer.json');

        return $topology;
    }

    private function forbidden(string $package): bool
    {
        foreach ($this->topology()['mustNotRequire'] as $glob) {
            if (fnmatch($glob, $package)) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function it_declares_the_fences_pinned_so_narrowing_one_is_a_failing_test(): void
    {
        $topology = $this->topology();

        $this->assertSame(self::FORBIDDEN, $topology['mustNotRequire'] ?? null);
        $this->assertSame(self::REQUIRED, $topology['mustRequire'] ?? null);
        $this->assertNotSame('', $topology['because'] ?? '');
    }

    #[Test]
    public function it_requires_every_package_it_declares_it_must(): void
    {
        $requires = array_keys($this->manifest()['require'] ?? []);

        $this->assertSame([], array_values(array_diff($this->topology()['mustRequire'], $requires)));
    }

    #[Test]
    public function it_requires_nothing_its_fences_forbid(): void
    {
        $requires = array_keys($this->manifest()['require'] ?? []);

        $this->assertNotSame([], $requires, 'No runtime requires read; the manifest probe is broken.');
        $this->assertSame([], array_values(array_filter($requires, fn (string $p): bool => $this->forbidden($p))));
    }

    #[Test]
    public function the_fences_would_catch_a_forbidden_require_and_leave_the_legal_ones(): void
    {
        foreach (['splicewire/laravel-beam-ux', 'splicewire/tower', 'splicewire/laravel-satellite', 'splicewire/laravel-tower-market'] as $planted) {
            $this->assertTrue($this->forbidden($planted), "A require on {$planted} would not be caught.");
        }

        foreach ([...self::REQUIRED, 'rushing/php-popcorn', 'splicewire/laravel-beam-tenancy', 'spatie/laravel-package-tools'] as $legal) {
            $this->assertFalse($this->forbidden($legal), "The fences wrongly forbid {$legal}.");
        }
    }

    #[Test]
    public function the_committed_manifest_supplies_no_local_paths(): void
    {
        $types = array_column($this->manifest()['repositories'] ?? [], 'type');

        $this->assertNotContains('path', $types, 'A path source belongs in the gitignored composer.local.json.');
    }

    #[Test]
    public function the_php_floor_is_what_its_requires_need(): void
    {
        $this->assertSame('^8.3', $this->manifest()['require']['php'] ?? null);
    }
}
