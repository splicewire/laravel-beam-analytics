<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Carbon\CarbonImmutable;

/**
 * What Traffic can honestly say for a scope right now, read from the cache alone (never from the source):
 *
 * - **Not connected:** no registered source covers the scope.
 * - **Not measured yet:** a source covers it, and no reading has landed. A refresh is queued.
 * - **Measured:** the last reading, with when it was measured. `stale` when it is older than the ttl (a refresh is queued),
 *   and `failedAt` when the last refresh threw after it (the reading shown is still the last one that succeeded).
 * - **Failed:** a refresh threw, and there is no earlier reading to show. `failedAt` is when.
 */
final class TrafficStatus
{
    public const NOT_CONNECTED = 'not-connected';

    public const NOT_MEASURED = 'not-measured';

    public const MEASURED = 'measured';

    public const FAILED = 'failed';

    private function __construct(
        public readonly string $state,
        public readonly ?TrafficSnapshot $snapshot = null,
        public readonly ?CarbonImmutable $measuredAt = null,
        public readonly bool $stale = false,
        public readonly ?CarbonImmutable $failedAt = null,
    ) {}

    public static function notConnected(): self
    {
        return new self(self::NOT_CONNECTED);
    }

    public static function notMeasured(): self
    {
        return new self(self::NOT_MEASURED);
    }

    public static function measured(TrafficSnapshot $snapshot, CarbonImmutable $measuredAt, bool $stale, ?CarbonImmutable $failedAt = null): self
    {
        return new self(self::MEASURED, $snapshot, $measuredAt, $stale, $failedAt);
    }

    public static function failed(CarbonImmutable $failedAt): self
    {
        return new self(self::FAILED, failedAt: $failedAt);
    }

    public function isMeasured(): bool
    {
        return $this->state === self::MEASURED;
    }

    /**
     * The state in words, for the Traffic card and every panel alike, so they cannot word it differently: "Not connected",
     * "Not measured yet", or "Couldn't be measured (when)". Null when measured, since the reading then speaks for itself.
     */
    public function headline(): ?string
    {
        return match ($this->state) {
            self::NOT_CONNECTED => 'Not connected',
            self::NOT_MEASURED => 'Not measured yet',
            self::FAILED => "Couldn't be measured (".self::at($this->failedAt).')',
            default => null,
        };
    }

    /** A time as drawn: in UTC, to the minute, labelled. */
    public static function at(?\DateTimeInterface $time): string
    {
        return $time === null ? 'unknown' : CarbonImmutable::instance($time)->utc()->format('Y-m-d H:i').' UTC';
    }
}
