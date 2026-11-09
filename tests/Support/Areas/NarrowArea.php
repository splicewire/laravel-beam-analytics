<?php

namespace Splicewire\Beam\Analytics\Tests\Support\Areas;

use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;

/** An area that narrows its gate to a second ability, beside `analytics.view`. */
#[Area(key: 'narrow', label: 'Narrow', icon: 'Lock', order: 90, ability: 'reports.read')]
final class NarrowArea implements AnalyticsArea
{
    public function measure(AnalyticsReading $reading): array
    {
        return [new AnalyticsFigureData(id: 'rows', label: 'Rows', value: 3)];
    }
}
