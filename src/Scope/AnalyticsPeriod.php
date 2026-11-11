<?php

namespace Splicewire\Beam\Analytics\Scope;

use InvalidArgumentException;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;

/**
 * The window a reading covers, in days.
 *
 * - The overview is fixed at {@see OVERVIEW_DAYS} and labelled, because the summary route forwards no facets.
 * - An area page offers {@see CHOICES} through its `period` facet ({@see facet()}): `filter[period]=7` in the page's URL, so
 *   a shared link opens the same view. Only those values are accepted; anything else (absent, unknown, not a whole number of
 *   days, a list) reads the default, the overview's window ({@see fromFilter()}).
 */
final class AnalyticsPeriod
{
    public const OVERVIEW_DAYS = 28;

    /** The windows an area page offers, in days. */
    public const CHOICES = [7, 28, 90];

    /** The `filter[...]` key an area page's period travels in. */
    public const FACET = 'period';

    /**
     * The Options Source the facet's select reads its choices from. An Options Source rather than an inline domain, because
     * the facets panel resolves a select's options through `optionsRef` (beam's ResourceRegistryBacking records an inline
     * domain rendering an empty picker).
     */
    public const OPTIONS_SOURCE = 'beam_analytics_periods';

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

    /**
     * The period a `filter[period]` value asks for: one of {@see CHOICES}, given as a whole number of days ("7" or 7), or the
     * default (the overview's window) for anything else. Never throws: an area page always draws.
     */
    public static function fromFilter(mixed $value): self
    {
        $days = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^[0-9]{1,3}$/', $value) === 1 => (int) $value,
            default => null,
        };

        return in_array($days, self::CHOICES, true) ? new self($days) : self::overview();
    }

    /** The facet an area page declares: a select over {@see CHOICES}, read from {@see OPTIONS_SOURCE}. */
    public static function facet(): DeclaredFacet
    {
        return DeclaredFacet::exact(self::FACET, options: self::OPTIONS_SOURCE)->titled('Period');
    }

    /**
     * The select's choices, in order, narrowed by `$search` on the label (the Options Source contract).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(?string $search = null): array
    {
        $options = array_map(fn (int $days): array => ['value' => (string) $days, 'label' => (new self($days))->label()], self::CHOICES);
        $search = trim((string) $search);

        return $search === ''
            ? $options
            : array_values(array_filter($options, fn (array $option): bool => str_contains(mb_strtolower($option['label']), mb_strtolower($search))));
    }

    public function label(): string
    {
        return "Last {$this->days} days";
    }
}
