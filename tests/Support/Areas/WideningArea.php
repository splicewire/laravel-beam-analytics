<?php

namespace Splicewire\Beam\Analytics\Tests\Support\Areas;

use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;

/** An area whose own ability admits everyone: it must still not admit a reader without `analytics.view`. */
#[Area(key: 'wide', label: 'Wide', icon: 'Unlock', order: 91, ability: 'anyone')]
final class WideningArea implements AnalyticsArea
{
    public function measure(AnalyticsReading $reading): array
    {
        return [new AnalyticsFigureData(id: 'rows', label: 'Rows', value: 1)];
    }
}
