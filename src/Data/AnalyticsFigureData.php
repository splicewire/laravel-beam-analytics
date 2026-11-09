<?php

namespace Splicewire\Beam\Analytics\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Data\SummaryFigureData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One figure an Analytics Area measures. It is a row on the area's page (`analytics-{key}`, drawn as a list whose columns
 * are the `#[Column]` fields) and, through {@see toSummaryFigure()}, a figure on the area's card on the section overview.
 * Both are read through one path, so a card and its page cannot disagree.
 *
 * `id` is the row key and is not drawn. The type reaches a host's generated TypeScript through the area resource's data
 * slot, so this package declares no TypeScript root.
 */
#[TypeScript, Title('Analytics figure')]
#[Description('One figure an Analytics Area measures: a row on the area page, and a figure on its card on the section overview.')]
class AnalyticsFigureData extends BeamData
{
    public function __construct(
        #[Description('The figure\'s stable key within its area.')]
        public string $id,
        #[Column(label: 'Figure', sort: 0)]
        #[Description('What the figure measures.')]
        public string $label,
        #[Column(label: 'Value', sort: 1)]
        #[Description('The value as measured. An area with nothing to measure says so in words; it never shows an invented zero.')]
        public int|float|string $value,
        #[Description('The figure\'s semantic tone from the summary vocabulary, when it carries one.')]
        public ?string $tone = null,
        #[Description('Where the figure leads: a page that explains or changes it, when there is one.')]
        public ?string $href = null,
    ) {}

    public function toSummaryFigure(): SummaryFigureData
    {
        return new SummaryFigureData(key: $this->id, label: $this->label, value: $this->value, tone: $this->tone);
    }
}
