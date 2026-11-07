<?php

namespace Splicewire\Beam\Analytics\Tests\Registries;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider;
use Splicewire\Beam\Analytics\Events\AnalyticsEventRegistry;
use Splicewire\Beam\Analytics\Events\EventVocabulary;
use Splicewire\Beam\Analytics\Tests\TestCase;

class AnalyticsEventRegistryTest extends TestCase
{
    private function registry(): AnalyticsEventRegistry
    {
        return app(AnalyticsEventRegistry::class);
    }

    #[Test]
    public function a_contribution_is_served_after_the_packages_and_before_the_hosts(): void
    {
        config()->set('beam.analytics.events', ['play_demo' => ['song_id']]);

        $this->registry()->register('song_shared', ['song_id'], 'acme/songs');

        $all = app(EventVocabulary::class)->all();

        $this->assertSame(
            [['name' => 'song_shared', 'source' => 'package', 'parameters' => ['song_id']], ['name' => 'play_demo', 'source' => 'host', 'parameters' => ['song_id']]],
            array_slice($all, 7),
        );
        $this->assertTrue(app(EventVocabulary::class)->has('song_shared'));
    }

    #[Test]
    public function each_half_names_its_registrant(): void
    {
        config()->set('beam.analytics.events', ['play_demo' => []]);

        $this->registry()->register('song_shared', [], 'acme/songs');

        $this->assertSame('splicewire/laravel-beam-analytics', $this->registry()->registrantOf('sign_up'));
        $this->assertSame('acme/songs', $this->registry()->registrantOf('song_shared'));
        $this->assertSame('config:beam.analytics.events', $this->registry()->registrantOf('play_demo'));
    }

    #[Test]
    public function it_reads_as_a_registry_under_its_root(): void
    {
        config()->set('beam.analytics.events', ['PlayDemo' => ['song_id']]);

        $this->assertTrue($this->registry()->has('sign_up'));
        $this->assertTrue($this->registry()->has('PlayDemo'));
        $this->assertFalse($this->registry()->has('Not A Name'));
        $this->assertSame(['song_id'], $this->registry()->resolve('PlayDemo'));
        $this->assertContains('beam.analytics.events.sign_up', array_map('strval', $this->registry()->keys()));
    }

    #[Test]
    public function register_refuses_what_the_host_half_refuses(): void
    {
        foreach ([['Bad Name', []], [str_repeat('a', 41), []], ['sign_up', []], ['song_shared', 'song_id'], ['song_shared', [1]]] as [$name, $parameters]) {
            try {
                $this->registry()->register($name, $parameters);
                $this->fail("accepted [{$name}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_second_contribution_with_other_parameters_is_refused_and_a_repeat_is_not(): void
    {
        $this->registry()->register('song_shared', ['song_id'])->register('song_shared', ['song_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('song_shared');

        $this->registry()->register('song_shared', ['other']);
    }

    #[Test]
    public function a_host_cannot_redeclare_a_contribution(): void
    {
        $this->registry()->register('song_shared', []);
        config()->set('beam.analytics.events', ['song_shared' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('song_shared');

        app(EventVocabulary::class)->all();
    }

    #[Test]
    public function a_host_colliding_with_a_contribution_is_refused_at_boot(): void
    {
        $this->registry()->register('song_shared', []);
        config()->set('beam.analytics.events', ['song_shared' => []]);

        $this->expectException(InvalidArgumentException::class);

        (new BeamAnalyticsServiceProvider($this->app))->packageBooted();
    }
}
