<?php

namespace Splicewire\Beam\Analytics\Tests\Registries;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Registrant;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
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
        foreach ([['Bad Name', []], [str_repeat('a', 41), []], ['song_shared', 'song_id'], ['song_shared', [1]], ['beam.analytics.events.Bad Name', []]] as [$name, $parameters]) {
            try {
                $this->registry()->register($name, $parameters);
                $this->fail("accepted [{$name}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_contribution_over_a_package_name_is_refused_naming_both(): void
    {
        $this->assertRefused('sign_up', ['method'], 'acme/two', 'splicewire/laravel-beam-analytics');
    }

    #[Test]
    public function a_second_contribution_is_refused_naming_both_whatever_it_carries(): void
    {
        $this->registry()->register('song_shared', ['song_id'], 'acme/one');

        foreach ([['song_id'], ['other']] as $parameters) {
            $this->assertRefused('song_shared', $parameters, 'acme/two', 'acme/one');
        }

        $this->assertSame('acme/one', $this->registry()->registrantOf('song_shared'));
    }

    #[Test]
    public function a_repeat_by_the_same_registrant_is_refused(): void
    {
        $this->registry()->register('song_shared', [], 'acme/one');

        $this->assertRefused('song_shared', [], 'acme/one', 'acme/one');
    }

    #[Test]
    public function a_legacy_name_is_a_duplicate_like_any_other(): void
    {
        $this->registry()->register('SongShared', [], 'acme/one');

        $this->assertRefused('SongShared', [], 'acme/two', 'acme/one');
    }

    #[Test]
    public function an_authorizer_hides_a_gated_contribution_from_every_read(): void
    {
        $this->registry()->register('private_event', ['secret'], 'acme/private', 'private.view');

        $this->assertTrue(app(EventVocabulary::class)->has('private_event'));

        $this->registry()->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });

        $this->assertFalse($this->registry()->has('private_event'));
        $this->assertNull($this->registry()->tryResolve('private_event'));
        $this->assertNotContains('beam.analytics.events.private_event', array_map('strval', $this->registry()->keys()));
        $this->assertFalse(app(EventVocabulary::class)->has('private_event'));
        $this->assertNotContains('private_event', array_column(app(EventVocabulary::class)->all(), 'name'));
        $this->assertTrue($this->registry()->has('sign_up'));
        $this->assertTrue($this->registry()->unfiltered()->has('private_event'));
    }

    #[Test]
    public function a_hidden_contribution_still_holds_its_name_against_a_host(): void
    {
        $this->registry()->register('private_event', [], 'acme/private', 'private.view');
        $this->registry()->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });
        config()->set('beam.analytics.events', ['private_event' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('private_event');

        $this->registry()->validate();
    }

    #[Test]
    public function a_scoped_registrant_survives_past_its_scope(): void
    {
        Registrant::scoped('acme/scoped', fn () => $this->registry()->register('scoped_event', []));

        $this->assertSame('acme/scoped', $this->registry()->registrantOf('scoped_event'));
    }

    #[Test]
    public function a_rooted_key_registers_as_its_event_name(): void
    {
        $key = 'beam.analytics.events.index_event';

        $this->registry()->register($key, ['target'], 'acme/one');
        $this->registry()->register(Key::parse('beam.analytics.events.parsed_event'), [], 'acme/one');

        $this->assertSame(['target'], $this->registry()->resolve($key));
        $this->assertTrue(app(EventVocabulary::class)->has('index_event'));
        $this->assertTrue(app(EventVocabulary::class)->has('parsed_event'));
        $this->assertRefused('index_event', ['target'], 'acme/two', 'acme/one');
    }

    #[Test]
    public function the_index_routes_a_rooted_contribution_here(): void
    {
        $index = new RegistryIndex;
        $index->describe($this->registry());

        $index->ownerOf('beam.analytics.events.index_event')->register('beam.analytics.events.index_event', [], 'acme/one');

        $this->assertTrue($this->registry()->has('index_event'));
    }

    #[Test]
    public function the_host_half_is_read_through_and_never_held(): void
    {
        config()->set('beam.analytics.events', ['play_demo' => []]);
        $this->assertTrue($this->registry()->has('play_demo'));

        config()->set('beam.analytics.events', []);
        $this->assertFalse($this->registry()->has('play_demo'));
    }

    private function assertRefused(string $name, array $parameters, string $incoming, string $existing): void
    {
        try {
            $this->registry()->register($name, $parameters, $incoming);
            $this->fail("accepted a second registration of [{$name}]");
        } catch (DuplicateRegistryKey $e) {
            $this->assertStringContainsString($name, $e->key);
            $this->assertSame($incoming, $e->incoming);
            $this->assertSame($existing, $e->existing);
        }
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
