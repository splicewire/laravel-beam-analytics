<?php

namespace Splicewire\Beam\Analytics\Traffic;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Throwable;

/**
 * A scope's Traffic: the last reading, kept in the cache per scope and period, and refreshed by a queued job. The shape is
 * exec's DoctorPostureContributor (and its RefreshDoctorPosture job), for the same reason: reading a provider is slow and
 * can fail, so **a reader's request never asks the source**.
 *
 * - {@see status()} reads the cache only. No source covers the scope: "Not connected". No reading yet: "Not measured yet", and
 *   a refresh is queued. A reading older than `beam.analytics.traffic.ttl` seconds (default 3600) is still shown, with its
 *   time, and a refresh is queued, or, where none can be (a tenant on a sync queue), it says why it can't. At most one refresh is outstanding per scope and period.
 * - {@see refresh()} runs in the queued {@see RefreshTrafficReading} job, and asks the source. It runs only inside the scope's
 *   own tenant: the job carries its scope, and before it asks a source it checks that beam's {@see TenancyContext} reports
 *   that same tenant (or no tenant, for Install scope). A mismatch, such as a host whose queue does not carry the tenant into
 *   the job, measures nothing and is REPORTED; the reader keeps its honest state. A source that throws is reported too, and
 *   the scope keeps its last good reading, or reads "Couldn't be measured" when it has none.
 *
 * Every cache key names its scope, so one tenant's reading is never served to another, even on a host whose cache store is
 * not itself tenant-scoped.
 */
final class TrafficReadings
{
    public const CACHE_PREFIX = 'beam-analytics:traffic:';

    public function __construct(
        private readonly TrafficSourceRegistry $sources,
        private readonly Container $container,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly Dispatcher $bus,
    ) {}

    /** What Traffic shows for `$scope` over `$period`, from the cache alone. Queues a refresh when it has none or it is stale. */
    public function status(AnalyticsScope $scope, AnalyticsPeriod $period): TrafficStatus
    {
        if ($this->sources->sourceFor($scope) === null) {
            return TrafficStatus::notConnected();
        }

        $entry = $this->entry($scope, $period);

        if ($entry === null) {
            return TrafficStatus::notMeasured($this->refreshLater($scope, $period));
        }

        [$snapshot, $measuredAt, $failedAt] = $entry;

        if ($snapshot === null) {
            $this->refreshLater($scope, $period);

            return TrafficStatus::failed($failedAt);
        }

        $stale = $measuredAt->addSeconds($this->ttl())->isPast();

        // A stale reading keeps its last good numbers and time. When it cannot be refreshed on this host (a tenant on a sync
        // queue), the reason travels with it, so it never claims to be refreshing (08 amend 2).
        $reason = $stale ? $this->refreshLater($scope, $period) : null;

        return TrafficStatus::measured($snapshot, $measuredAt, $stale, $failedAt, $reason);
    }

    /**
     * Refresh unless a reading fresher than the ttl has landed since this refresh was queued, so jobs queued by reads the queue
     * had not caught up with ask the source once. Returns whether it asked.
     */
    public function refreshUnlessFresh(AnalyticsScope $scope, AnalyticsPeriod $period): bool
    {
        $entry = $this->entry($scope, $period);

        if ($entry !== null && $entry[0] !== null && ! $entry[1]->addSeconds(max(1, $this->ttl()))->isPast()) {
            $this->cache->forget($this->queuedKey($scope, $period));

            return false;
        }

        return $this->refresh($scope, $period);
    }

    /**
     * Ask the scope's source and keep the reading. Only the queued job calls this; a reader's request never does. Returns
     * whether the source was asked.
     */
    public function refresh(AnalyticsScope $scope, AnalyticsPeriod $period): bool
    {
        $keepQueued = false;

        try {
            $running = $this->runningScope();

            if (! self::same($running, $scope)) {
                // The queued flag is KEPT until its ttl, so reads do not queue a job that would be refused again: one refusal,
                // and one report, per ttl window, not one per read (08 amend A).
                $keepQueued = true;
                report(new TrafficScopeMismatch($scope, $running));

                return false;
            }

            $source = $this->sources->sourceFor($scope);

            if ($source === null) {
                return false;
            }

            $now = CarbonImmutable::now();

            try {
                $snapshot = $source->read($scope, $period);
            } catch (Throwable $e) {
                report($e);
                $this->keepFailure($scope, $period, $now);

                return true;
            }

            $this->cache->forever($this->key($scope, $period), [
                'measured_at' => $now->toIso8601String(),
                'source' => $source::key(),
                'snapshot' => $snapshot->toArray(),
            ]);

            return true;
        } finally {
            if (! $keepQueued) {
                $this->cache->forget($this->queuedKey($scope, $period));
            }
        }
    }

    /** The cache key holding `$scope`'s reading over `$period`. It names the scope, so tenants never share an entry. */
    public function key(AnalyticsScope $scope, AnalyticsPeriod $period): string
    {
        return self::CACHE_PREFIX.self::scopeKey($scope).':'.$period->days.'d';
    }

    public function queuedKey(AnalyticsScope $scope, AnalyticsPeriod $period): string
    {
        return $this->key($scope, $period).':queued';
    }

    /** `install`, or `tenant:{id}` with the id encoded, so no tenant id can collide with another's key. */
    public static function scopeKey(AnalyticsScope $scope): string
    {
        return $scope->isTenant() ? 'tenant:'.rawurlencode((string) $scope->tenantId) : 'install';
    }

    /** Keep a failed refresh: the last good reading stays, marked failed at `$at`; with none, the failure alone. */
    private function keepFailure(AnalyticsScope $scope, AnalyticsPeriod $period, CarbonImmutable $at): void
    {
        $previous = $this->cache->get($this->key($scope, $period));
        $kept = is_array($previous) && TrafficSnapshot::tryFromArray($previous['snapshot'] ?? null) !== null ? $previous : [];

        $this->cache->forever($this->key($scope, $period), [...$kept, 'failed_at' => $at->toIso8601String()]);
    }

    /**
     * The cached entry as [snapshot or null, measured at or null, failed at or null], or null when there is none or it is not
     * this shape (a cache written by another version).
     *
     * @return array{0: ?TrafficSnapshot, 1: ?CarbonImmutable, 2: ?CarbonImmutable}|null
     */
    private function entry(AnalyticsScope $scope, AnalyticsPeriod $period): ?array
    {
        $entry = $this->cache->get($this->key($scope, $period));

        if (! is_array($entry)) {
            return null;
        }

        $failedAt = is_string($entry['failed_at'] ?? null) ? CarbonImmutable::parse($entry['failed_at']) : null;
        $snapshot = TrafficSnapshot::tryFromArray($entry['snapshot'] ?? null);

        if ($snapshot !== null && is_string($entry['measured_at'] ?? null)) {
            return [$snapshot, CarbonImmutable::parse($entry['measured_at']), $failedAt];
        }

        return $failedAt !== null ? [null, null, $failedAt] : null;
    }

    /**
     * Queue a refresh, at most one outstanding per scope and period. Returns null when one is (or already was) queued, or the
     * reason it cannot be: in TENANT scope on a SYNC queue a job would run after the response, after the request's tenancy
     * has ended, and the guard would refuse it every time (08 amend B). So none is dispatched there; the reader is told why,
     * and it is reported once per ttl window. On a real queue the job is dispatched normally, and the host's queue tenancy
     * (stancl's QueueTenancyBootstrapper) carries the tenant into it.
     */
    private function refreshLater(AnalyticsScope $scope, AnalyticsPeriod $period): ?string
    {
        $sync = $this->config->get('queue.connections.'.$this->config->get('queue.default').'.driver') === 'sync';

        if ($scope->isTenant() && $sync) {
            if ($this->cache->add($this->queuedKey($scope, $period).':needs-worker-reported', true, max(1, $this->ttl()))) {
                report(new TrafficNeedsQueueWorker($scope));
            }

            return TrafficNeedsQueueWorker::REASON;
        }

        if (! $this->cache->add($this->queuedKey($scope, $period), true, max(1, $this->ttl()))) {
            return null; // one is already queued
        }

        $job = RefreshTrafficReading::for($scope, $period);

        if ($sync) {
            $this->bus->dispatchAfterResponse($job); // Install scope: no tenancy to lose after the response

            return null;
        }

        $this->bus->dispatch($job);

        return null;
    }

    /** The scope this process is running in, as beam's TenancyContext reports it: Install when no tenancy is bound. */
    private function runningScope(): AnalyticsScope
    {
        return $this->container->bound(TenancyContext::class)
            ? AnalyticsScope::fromTenancy($this->container->make(TenancyContext::class))
            : AnalyticsScope::install();
    }

    private static function same(AnalyticsScope $a, AnalyticsScope $b): bool
    {
        return self::scopeKey($a) === self::scopeKey($b);
    }

    private function ttl(): int
    {
        return (int) $this->config->get('beam.analytics.traffic.ttl', 3600);
    }
}
