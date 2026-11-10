<?php

namespace Splicewire\Beam\Analytics\Traffic;

use RuntimeException;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * A Traffic refresh found itself outside the scope it was queued for, so it measured nothing. Reported, never thrown at a
 * reader: the scope keeps its honest state ("Not measured yet", or its last reading).
 *
 * The usual cause is a host whose queue does not carry the tenant into a queued job (stancl's QueueTenancyBootstrapper is
 * not in its `tenancy.bootstrappers`), so a refresh queued from a tenant's request runs centrally. Asking the source there
 * could read and store another scope's traffic under this one, so the refresh refuses instead.
 */
final class TrafficScopeMismatch extends RuntimeException
{
    public function __construct(
        public readonly AnalyticsScope $queuedFor,
        public readonly AnalyticsScope $runningIn,
    ) {
        parent::__construct(sprintf(
            'A Traffic refresh queued for [%s] ran in [%s], so it measured nothing. Carry the tenant into queued jobs (stancl\'s QueueTenancyBootstrapper), or refresh each tenant inside its own context.',
            TrafficReadings::scopeKey($queuedFor),
            TrafficReadings::scopeKey($runningIn),
        ));
    }
}
