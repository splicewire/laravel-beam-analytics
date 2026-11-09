<?php

namespace Splicewire\Beam\Analytics\Tests\Support\Areas;

use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;

/** No #[Area]: nothing says what key, label or icon it is drawn under. */
final class UndeclaredArea implements AnalyticsArea
{
    public function measure(AnalyticsReading $reading): array
    {
        return [];
    }
}
