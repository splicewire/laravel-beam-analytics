<?php

namespace Splicewire\Beam\Analytics\Providers;

use Splicewire\Beam\Analytics\Contracts\AnalyticsProvider;

/**
 * The fail-closed sentinel. The manager resolves a provider to this whenever the
 * provider is keyless, its adapter is unknown/unresolvable, or the env is disallowed.
 * It renders nothing, so a typo or an absent id degrades to silence, never a broken
 * tag and never a fatal error.
 */
class NullProvider implements AnalyticsProvider
{
    public function head(): string
    {
        return '';
    }

    public function body(): string
    {
        return '';
    }
}
