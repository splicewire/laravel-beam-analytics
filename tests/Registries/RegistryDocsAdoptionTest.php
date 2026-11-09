<?php

namespace Splicewire\Beam\Analytics\Tests\Registries;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/**
 * Every root this package declares is covered under `docs/registries/`, and each page's generated facts block is
 * byte-identical to a fresh render of its `#[IsRegistry]` declaration. The count is asserted so an inactive check or an
 * empty scan cannot pass by reporting no failures.
 */
class RegistryDocsAdoptionTest extends TestCase
{
    public function test_every_declared_root_is_documented_with_its_facts_in_step(): void
    {
        $report = RegistryDocsCheck::forPackage(dirname(__DIR__, 2))->run();

        $this->assertSame([], $report->failures(), $report->summary());
        $this->assertEqualsCanonicalizing(
            ['beam.analytics.areas', 'beam.analytics.events', 'beam.analytics.providers'],
            array_keys($report->coverage),
            'the areas, events and providers roots',
        );
    }
}
