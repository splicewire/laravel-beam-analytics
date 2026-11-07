<?php

namespace Splicewire\Beam\Analytics\Tests\Registries;

use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\AnalyticsManager;
use Splicewire\Beam\Analytics\Events\EventVocabulary;
use Splicewire\Beam\Analytics\Providers\GaProvider;
use Splicewire\Beam\Analytics\Tests\TestCase;

/**
 * Both Blade slots and `x-events` against files captured from the source at 14cf808, before the registries existed.
 * The site covers an active, a misdeclared and a keyless provider, and a host event name {@see \Rushing\Popcorn\Registries\Key}
 * would refuse. Do not regenerate these files: a difference here is a change to what every site renders.
 */
class ByteIdenticalOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configure('production', [
            'gtm' => $this->gtm('GTM-BYTES01'),
            'ga4' => ['adapter' => GaProvider::class, 'id' => 'G-BYTES02'],
            'mystery' => ['adapter' => 'Nope', 'id' => 'X'],
            'keyless' => $this->gtm(''),
        ]);
        config()->set('beam.analytics.events', ['play_demo' => ['song_id'], 'PlayDemo' => [], 'share_song' => ['song_id', 'channel']]);
    }

    #[Test]
    public function the_head_slot_is_unchanged(): void
    {
        $this->assertSame($this->before('head.html'), app(AnalyticsManager::class)->head());
    }

    #[Test]
    public function the_body_slot_is_unchanged(): void
    {
        $this->assertSame($this->before('body.html'), app(AnalyticsManager::class)->body());
    }

    #[Test]
    public function x_events_is_unchanged(): void
    {
        $this->assertSame($this->before('x-events.json'), json_encode(app(EventVocabulary::class)->all()));
    }

    private function before(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/fixtures/before/'.$file);
    }
}
