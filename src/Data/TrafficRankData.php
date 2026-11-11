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
 * One entry in a Traffic top list (a page, or a referrer), most first: a row on its page, and a figure on its card, which
 * frame's `stat-row` draws as label and value.
 */
#[TypeScript, Title('Traffic top entry'), Summary('stat-row')]
#[Overview] // ticket 09 criterion 4: an overview row, so frame draws the inherited stat-row inside the overview chrome, whose subtitle is the summary's period
#[Description('One entry in a Traffic top list, a page or a referrer: a row on its page and a figure on its card.')]
class TrafficRankData extends BeamData
{
    public function __construct(
        #[Description('The entry\'s rank, 1 first.')]
        public string $id,
        #[Column(label: 'Name', sort: 0)]
        #[Description('The page or the referrer, as the source names it.')]
        public string $label,
        #[Column(label: 'Count', sort: 1)]
        #[Description('Views for a page, sessions for a referrer, over the period.')]
        public int $value,
    ) {}

    public function toSummaryFigure(): SummaryFigureData
    {
        return new SummaryFigureData(key: $this->id, label: $this->label, value: $this->value);
    }
}
