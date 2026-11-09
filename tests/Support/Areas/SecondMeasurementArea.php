<?php

namespace Splicewire\Beam\Analytics\Tests\Support\Areas;

use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;

/** A second class claiming Measurement's key. */
#[Area(key: 'measurement', label: 'Another measurement', icon: 'Gauge', order: 92)]
final class SecondMeasurementArea implements AnalyticsArea
{
    public function measure(AnalyticsReading $reading): array
    {
        return [];
    }
}
