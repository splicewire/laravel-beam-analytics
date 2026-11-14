<?php

namespace Splicewire\Beam\Analytics\Traffic;

use RuntimeException;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * A tenant's Traffic cannot be refreshed on this host: its queue is SYNC, and a sync job queued from a tenant's request runs
 * after the response, after the request's tenancy has ended, so the refresh would be refused every time
 * ({@see TrafficScopeMismatch}). Reported once per ttl window, never thrown at a reader, who reads "Not measured yet" with
 * {@see REASON}. A host measures tenant traffic by running a queue worker (with stancl's QueueTenancyBootstrapper).
 */
final class TrafficNeedsQueueWorker extends RuntimeException
{
    public const REASON = 'needs a queue worker to measure a tenant';

    /** Read-time copy only; REASON remains the public raw compatibility declaration. */
    public static function reason(): string
    {
        return __('beam-analytics::messages.traffic.needs_worker');
    }

    public function __construct(
        public readonly AnalyticsScope $scope,
    ) {
        parent::__construct(sprintf(
            'Traffic for [%s] is not refreshed: the queue is sync, and a sync job from a tenant\'s request runs after its tenancy has ended. Run a queue worker (stancl\'s QueueTenancyBootstrapper carries the tenant into the job).',
            TrafficReadings::scopeKey($scope),
        ));
    }
}
