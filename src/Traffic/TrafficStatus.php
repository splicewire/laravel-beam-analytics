<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Carbon\CarbonImmutable;

/**
 * What Traffic can honestly say for a scope right now, read from the cache alone (never from the source):
 *
 * - **Not connected:** no registered source covers the scope.
 * - **Not measured yet:** a source covers it, and no reading has landed. A refresh is queued, or `reason` says why it
 *   cannot be on this host (a tenant on a sync queue: "needs a queue worker to measure a tenant").
 * - **Measured:** the last reading, with when it was measured. `stale` when it is older than the ttl (a refresh is queued),
 *   and `failedAt` when the last refresh threw after it (the reading shown is still the last one that succeeded). A stale
 *   reading that cannot be refreshed on this host carries `reason` instead of claiming a refresh ({@see refreshNote()}).
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
        public readonly ?string $reason = null,
    ) {}

    public static function notConnected(): self
    {
        return new self(self::NOT_CONNECTED);
    }

    /** Not measured yet; `$reason` says why it cannot be measured on this host, when it cannot (a tenant on a sync queue). */
    public static function notMeasured(?string $reason = null): self
    {
        return new self(self::NOT_MEASURED, reason: $reason);
    }

    /** Measured; when `$stale`, `$reason` says why it cannot be refreshed on this host, when it cannot (a tenant on a sync queue). */
    public static function measured(TrafficSnapshot $snapshot, CarbonImmutable $measuredAt, bool $stale, ?CarbonImmutable $failedAt = null, ?string $reason = null): self
    {
        return new self(self::MEASURED, $snapshot, $measuredAt, $stale, $failedAt, $reason);
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
            self::NOT_CONNECTED => __('beam-analytics::messages.traffic.not_connected'),
            self::NOT_MEASURED => __($this->reason === null ? 'beam-analytics::messages.traffic.not_measured' : 'beam-analytics::messages.traffic.not_measured_reason', ['reason' => $this->reason ?? '']),
            self::FAILED => __('beam-analytics::messages.traffic.failed', ['when' => self::at($this->failedAt)]),
            default => null,
        };
    }

    /**
     * What a measured reading says about its next refresh: "last refresh failed", "Can't refresh (why)" when it is stale and
     * cannot be refreshed on this host, "refreshing" when it is stale and a refresh is queued, or null when it is fresh.
     */
    public function refreshNote(): ?string
    {
        return match (true) {
            $this->failedAt !== null => __('beam-analytics::messages.traffic.refresh_failed'),
            $this->stale && $this->reason !== null => __('beam-analytics::messages.traffic.cannot_refresh', ['reason' => $this->reason]),
            $this->stale => __('beam-analytics::messages.traffic.refreshing'),
            default => null,
        };
    }

    /** A time as drawn: in UTC, to the minute, labelled. */
    public static function at(?\DateTimeInterface $time): string
    {
        return $time === null ? __('beam-analytics::messages.traffic.unknown') : CarbonImmutable::instance($time)->utc()->format('Y-m-d H:i').' UTC';
    }
}
