<?php

namespace Splicewire\Beam\Analytics\Tests\Areas;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\EventsArea;
use Splicewire\Beam\Analytics\Areas\MeasurementArea;
use Splicewire\Beam\Analytics\Areas\TrafficArea;
use Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider;
use Splicewire\Beam\Analytics\Tests\Support\Areas\BadKeyArea;
use Splicewire\Beam\Analytics\Tests\Support\Areas\NarrowArea;
use Splicewire\Beam\Analytics\Tests\Support\Areas\NotAnArea;
use Splicewire\Beam\Analytics\Tests\Support\Areas\SecondMeasurementArea;
use Splicewire\Beam\Analytics\Tests\Support\Areas\UndeclaredArea;
use Splicewire\Beam\Analytics\Tests\TestCase;

/**
 * `beam.analytics.areas`: one AnalyticsArea class per key, the package's Measurement first, contributions beside it.
 * A taken key is rejected and a misdeclared area refuses where it is registered, which for a package contribution is boot.
 */
class AnalyticsAreaRegistryTest extends TestCase
{
    #[Test]
    public function measurement_traffic_and_events_ship_as_the_packages_areas(): void
    {
        $areas = app(AnalyticsAreaRegistry::class);

        $this->assertSame(MeasurementArea::class, $areas->resolve('measurement'));
        $this->assertSame(TrafficArea::class, $areas->resolve('traffic'));
        $this->assertSame('splicewire/laravel-beam-analytics', $areas->registrantOf('traffic'));
        $this->assertSame(EventsArea::class, $areas->resolve('events'));
        $this->assertSame('splicewire/laravel-beam-analytics', $areas->registrantOf('measurement'));
        $this->assertSame('splicewire/laravel-beam-analytics', $areas->registrantOf('events'));
        $this->assertSame(['analytics-measurement', 'analytics-traffic', 'analytics-events'], array_map(fn ($area) => $area->resourceKey(), $areas->definitions()));
    }

    #[Test]
    public function a_contributed_area_is_drawn_under_the_key_its_attribute_declares(): void
    {
        $areas = app(AnalyticsAreaRegistry::class)->add(NarrowArea::class, 'acme/reports');

        $this->assertSame(NarrowArea::class, $areas->resolve('narrow'));
        $this->assertSame('acme/reports', $areas->registrantOf('narrow'));
        $this->assertSame('narrow', $areas->forResource('analytics-narrow')?->key());
        $this->assertNull($areas->forResource('analytics-nothing'));
        $this->assertNull($areas->forResource('exec-revenue'));
    }

    #[Test]
    public function a_taken_key_is_rejected_naming_both_registrants(): void
    {
        $this->expectException(DuplicateRegistryKey::class);

        app(AnalyticsAreaRegistry::class)->add(SecondMeasurementArea::class, 'acme/measurement');
    }

    #[Test]
    public function an_area_without_its_attribute_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Area]');

        app(AnalyticsAreaRegistry::class)->add(UndeclaredArea::class);
    }

    #[Test]
    public function a_class_that_is_not_an_area_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AnalyticsArea');

        app(AnalyticsAreaRegistry::class)->add(NotAnArea::class);
    }

    #[Test]
    public function a_key_that_is_not_kebab_case_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bad Key');

        app(AnalyticsAreaRegistry::class)->add(BadKeyArea::class);
    }

    #[Test]
    public function a_key_that_differs_from_the_attribute_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('narrow');

        app(AnalyticsAreaRegistry::class)->register('reports', NarrowArea::class);
    }

    #[Test]
    public function an_entry_that_is_not_a_class_string_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AnalyticsAreaRegistry::class)->register('measurement-two', ['not' => 'a class']);
    }

    #[Test]
    public function a_misdeclared_contributed_area_is_refused_at_boot(): void
    {
        $this->app->forgetInstance(AnalyticsAreaRegistry::class);
        $this->app->afterResolving(AnalyticsAreaRegistry::class, fn ($areas) => $areas->add(UndeclaredArea::class, 'acme/broken'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(UndeclaredArea::class);

        (new BeamAnalyticsServiceProvider($this->app))->packageBooted();
    }
}
