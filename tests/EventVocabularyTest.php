<?php

namespace Splicewire\Beam\Analytics\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\Events\EventVocabulary;

/**
 * ADR-0237 decision 8: the Event Vocabulary declares each Analytics Event a site may report, with its parameters — the
 * package's half is GA4's recommended names for what packaged surfaces do, and a host declares its own beside them.
 * The Manifest Profile serves {@see EventVocabulary::all()} as `x-events` (laravel-beam-ux ADR-0234 decision 14).
 */
class EventVocabularyTest extends TestCase
{
    private function vocabulary(array $hostEvents = []): EventVocabulary
    {
        config()->set('beam.analytics.events', $hostEvents);

        return app(EventVocabulary::class);
    }

    #[Test]
    public function the_package_declares_the_ga4_recommended_names_for_packaged_surfaces_first(): void
    {
        $all = $this->vocabulary()->all();

        $this->assertSame(
            ['sign_up', 'login', 'generate_lead', 'search', 'begin_checkout', 'purchase', 'select_content'],
            array_column($all, 'name'),
        );
        $this->assertSame(['package'], array_values(array_unique(array_column($all, 'source'))));
        $this->assertSame(['name' => 'search', 'source' => 'package', 'parameters' => ['search_term']], $all[3]);
    }

    #[Test]
    public function a_hosts_names_follow_the_packages_in_declaration_order(): void
    {
        $all = $this->vocabulary(['play_demo' => ['song_id'], 'share_song' => []])->all();

        $this->assertSame(
            [['name' => 'play_demo', 'source' => 'host', 'parameters' => ['song_id']], ['name' => 'share_song', 'source' => 'host', 'parameters' => []]],
            array_slice($all, 7),
        );
    }

    #[Test]
    public function it_answers_whether_a_name_is_declared(): void
    {
        $vocabulary = $this->vocabulary(['play_demo' => []]);

        $this->assertTrue($vocabulary->has('sign_up'));
        $this->assertTrue($vocabulary->has('play_demo'));
        $this->assertFalse($vocabulary->has('page_view'));
    }

    #[Test]
    public function a_host_cannot_redeclare_a_package_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sign_up');

        $this->vocabulary(['sign_up' => ['method', 'plan']])->all();
    }

    #[Test]
    public function a_host_name_must_be_a_valid_ga4_event_name(): void
    {
        foreach (['Bad Name', '1st_play', 'play-demo', str_repeat('a', 41)] as $name) {
            try {
                $this->vocabulary([$name => []])->all();
                $this->fail("accepted the invalid event name [{$name}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_hosts_parameters_are_a_list_of_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('play_demo');

        $this->vocabulary(['play_demo' => 'song_id'])->all();
    }
}
