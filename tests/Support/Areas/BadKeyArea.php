<?php

namespace Splicewire\Beam\Analytics\Tests\Support\Areas;

use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;

/** A key that cannot be a resource key segment. */
#[Area(key: 'Bad Key', label: 'Bad key', icon: 'Box', order: 94)]
final class BadKeyArea implements AnalyticsArea
{
    public function measure(AnalyticsReading $reading): array
    {
        return [];
    }
}
