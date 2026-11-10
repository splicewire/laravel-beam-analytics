<?php

namespace Splicewire\Beam\Analytics\Traffic;

use InvalidArgumentException;

/**
 * One reading of a site's traffic over a period, provider-neutral: what any {@see \Splicewire\Beam\Analytics\Contracts\TrafficSource}
 * returns, whatever it reads from. It carries plain numbers and names only, never a provider's own types, so it is cached as an
 * array and drawn by the Traffic area and its panels without knowing the source.
 *
 * - `users`, `sessions`, `views`: totals over the period.
 * - `trend`: views per day, oldest first, `[date (Y-m-d) => views]`.
 * - `pages`: the most viewed pages, most first, `[label, views]`.
 * - `referrers`: the sources that sent the most sessions, most first, `[label, sessions]`.
 *
 * Every count is a whole number of at least zero, and a list is never padded: a source with no page data returns no pages.
 */
final class TrafficSnapshot
{
    /**
     * @param  array<string, int>  $trend
     * @param  list<array{label: string, value: int}>  $pages
     * @param  list<array{label: string, value: int}>  $referrers
     */
    public function __construct(
        public readonly int $users,
        public readonly int $sessions,
        public readonly int $views,
        public readonly array $trend = [],
        public readonly array $pages = [],
        public readonly array $referrers = [],
    ) {
        foreach (['users' => $users, 'sessions' => $sessions, 'views' => $views] as $name => $count) {
            self::count($count, $name);
        }

        foreach ($trend as $date => $count) {
            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException('A traffic trend is keyed by day (Y-m-d); got ['.$date.'].');
            }
            self::count($count, "trend[{$date}]");
        }

        foreach (['pages' => $pages, 'referrers' => $referrers] as $name => $rows) {
            if (! array_is_list($rows)) {
                throw new InvalidArgumentException("A traffic snapshot's {$name} is a list, most first.");
            }
            foreach ($rows as $i => $row) {
                if (! is_array($row) || ! is_string($row['label'] ?? null) || trim($row['label']) === '') {
                    throw new InvalidArgumentException("A traffic snapshot's {$name}[{$i}] has no label.");
                }
                self::count($row['value'] ?? null, "{$name}[{$i}]");
            }
        }
    }

    /** @return array{users: int, sessions: int, views: int, trend: array<string, int>, pages: list<array{label: string, value: int}>, referrers: list<array{label: string, value: int}>} */
    public function toArray(): array
    {
        return [
            'users' => $this->users,
            'sessions' => $this->sessions,
            'views' => $this->views,
            'trend' => $this->trend,
            'pages' => $this->pages,
            'referrers' => $this->referrers,
        ];
    }

    /** The snapshot an array from {@see toArray()} holds, or null when it is not that shape (a cache written by another version). */
    public static function tryFromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        try {
            return new self(
                users: $value['users'] ?? -1,
                sessions: $value['sessions'] ?? -1,
                views: $value['views'] ?? -1,
                trend: is_array($value['trend'] ?? null) ? $value['trend'] : [],
                pages: is_array($value['pages'] ?? null) ? $value['pages'] : [],
                referrers: is_array($value['referrers'] ?? null) ? $value['referrers'] : [],
            );
        } catch (InvalidArgumentException|\TypeError) {
            return null;
        }
    }

    private static function count(mixed $count, string $name): void
    {
        if (! is_int($count) || $count < 0) {
            throw new InvalidArgumentException("A traffic count is a whole number of at least zero; {$name} is ".var_export($count, true).'.');
        }
    }
}
