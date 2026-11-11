<?php

namespace Splicewire\Beam\Analytics\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\Overview;
use Schemastud\Frame\Attributes\Summary;
use Schemastud\Frame\Data\SummaryFigureData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One day of a site's views: a row on the Traffic trend page, and one period of its card, which frame's `trend-card` draws as
 * the series, oldest first. The value is a plain number, so the card plots it.
 */
#[TypeScript, Title('Traffic trend point'), Summary('trend-card')]
#[Overview] // ticket 09 criterion 4: an overview row, so frame draws the inherited trend-card inside the overview chrome, whose subtitle is the summary's period
#[Description('One day of a site\'s views: a row on the Traffic trend page and one period of its trend card.')]
class TrafficPointData extends BeamData
{
    public function __construct(
        #[Description('The day, Y-m-d.')]
        public string $id,
        #[Column(label: 'Day', sort: 0)]
        #[Description('The day, as drawn.')]
        public string $label,
        #[Column(label: 'Views', sort: 1)]
        #[Description('The views that day, a whole number.')]
        public int $value,
    ) {}

    public function toSummaryFigure(): SummaryFigureData
    {
        return new SummaryFigureData(key: $this->id, label: $this->label, value: $this->value);
    }
}
