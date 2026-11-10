<?php

namespace Splicewire\Beam\Analytics\Tests\Registries;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\Contracts\TrafficSource;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Analytics\Tests\Section\SectionTestCase;
use Splicewire\Beam\Analytics\Tests\Support\FakeTrafficSource;
use Splicewire\Beam\Analytics\Traffic\TrafficSnapshot;
use Splicewire\Beam\Analytics\Traffic\TrafficSourceRegistry;

/**
 * `beam.analytics.sources` (ticket 08): read-only TrafficSource classes keyed by their own key. Empty is valid; a class that is
 * not a source, a key that is not kebab-case, and a taken key all refuse where they are registered.
 */
class TrafficSourceRegistryTest extends SectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeTrafficSource::reset();
    }

    #[Test]
    public function it_ships_empty_and_holds_a_contributed_source_under_its_own_key_with_its_registrant(): void
    {
        $sources = app(TrafficSourceRegistry::class);
        $this->assertSame([], $sources->keys());

        $sources->add(FakeTrafficSource::class, 'acme/traffic');

        $this->assertSame(FakeTrafficSource::class, $sources->resolve('fake-traffic'));
        $this->assertSame('acme/traffic', $sources->registrantOf('fake-traffic'));
    }

    #[Test]
    public function a_misdeclared_or_taken_source_refuses_where_it_is_registered(): void
    {
        $sources = app(TrafficSourceRegistry::class);

        $this->assertThrows(fn () => $sources->add(\stdClass::class, 'acme/bad'), InvalidArgumentException::class, 'A Traffic Source is the class-string of a');
        $this->assertThrows(fn () => $sources->add(BadKeySource::class, 'acme/bad'), InvalidArgumentException::class);
        $this->assertThrows(fn () => $sources->register('other', FakeTrafficSource::class, 'acme/bad'), InvalidArgumentException::class);

        $sources->add(FakeTrafficSource::class, 'acme/one');
        $this->assertThrows(fn () => $sources->add(FakeTrafficSource::class, 'acme/two'));
    }

    #[Test]
    public function the_source_for_a_scope_is_the_first_by_key_that_covers_it_and_asks_no_source_to_read(): void
    {
        $sources = app(TrafficSourceRegistry::class)->add(FakeTrafficSource::class, 'acme/traffic')->add(EveryScopeSource::class, 'acme/every');
        FakeTrafficSource::$readings['tenant:acme'] = FakeTrafficSource::snapshot(1);

        $this->assertInstanceOf(EveryScopeSource::class, $sources->sourceFor(AnalyticsScope::tenant('acme')), '`every-scope` sorts before `fake-traffic`');
        $this->assertSame([], FakeTrafficSource::$asked);

        app()->forgetInstance(TrafficSourceRegistry::class);
        $only = app(TrafficSourceRegistry::class)->add(FakeTrafficSource::class, 'acme/traffic');
        $this->assertInstanceOf(FakeTrafficSource::class, $only->sourceFor(AnalyticsScope::tenant('acme')));
        $this->assertNull($only->sourceFor(AnalyticsScope::tenant('globex')), 'a tenant no source covers has none, never the install\'s');
    }
}

final class BadKeySource implements TrafficSource
{
    public static function key(): string
    {
        return 'Not_Kebab';
    }

    public function covers(AnalyticsScope $scope): bool
    {
        return true;
    }

    public function read(AnalyticsScope $scope, AnalyticsPeriod $period): TrafficSnapshot
    {
        return new TrafficSnapshot(0, 0, 0);
    }
}

final class EveryScopeSource implements TrafficSource
{
    public static function key(): string
    {
        return 'every-scope';
    }

    public function covers(AnalyticsScope $scope): bool
    {
        return true;
    }

    public function read(AnalyticsScope $scope, AnalyticsPeriod $period): TrafficSnapshot
    {
        return new TrafficSnapshot(0, 0, 0);
    }
}
