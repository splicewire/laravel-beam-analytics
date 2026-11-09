<?php

namespace Splicewire\Beam\Analytics\Scope;

use InvalidArgumentException;

/**
 * The window a reading covers, in days. The overview is fixed at {@see OVERVIEW_DAYS} and labelled, because the summary
 * route forwards no facets; an area page's period choice is a later ticket's.
 */
final class AnalyticsPeriod
{
    public const OVERVIEW_DAYS = 28;

    public function __construct(
        public readonly int $days,
    ) {
        if ($days < 1) {
            throw new InvalidArgumentException("An analytics period is at least one day; got {$days}.");
        }
    }

    public static function overview(): self
    {
        return new self(self::OVERVIEW_DAYS);
    }

    public function label(): string
    {
        return "Last {$this->days} days";
    }
}
