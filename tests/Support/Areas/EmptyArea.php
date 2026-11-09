<?php

namespace Splicewire\Beam\Analytics\Tests\Support\Areas;

use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;

/** An area that measures nothing: its card declines, and the dashboard-tier audit must say so. */
#[Area(key: 'empty', label: 'Empty', icon: 'Box', order: 95)]
final class EmptyArea implements AnalyticsArea
{
    public function measure(AnalyticsReading $reading): array
    {
        return [];
    }
}
