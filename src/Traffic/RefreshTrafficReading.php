<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * Read a scope's traffic from its source and keep the reading (exec's RefreshDoctorPosture shape).
 *
 * {@see TrafficReadings::status()} queues it when a read finds no reading, or one older than its ttl, so the read itself never
 * waits for the source. It carries the scope it was queued for, as plain values, and {@see TrafficReadings::refresh()} refuses
 * (and reports) when it runs in any other scope. A job that finds a fresh reading already landed asks the source nothing.
 */
final class RefreshTrafficReading implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** A provider's report API can be slow; well past one read, well short of a stuck worker. */
    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $tenantId,
        public readonly int $days,
    ) {}

    public static function for(AnalyticsScope $scope, AnalyticsPeriod $period): self
    {
        return new self($scope->isTenant() ? (string) $scope->tenantId : null, $period->days);
    }

    public function scope(): AnalyticsScope
    {
        return $this->tenantId === null ? AnalyticsScope::install() : AnalyticsScope::tenant($this->tenantId);
    }

    public function handle(TrafficReadings $readings): void
    {
        $readings->refreshUnlessFresh($this->scope(), new AnalyticsPeriod($this->days));
    }
}
