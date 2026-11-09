<?php

namespace Splicewire\Beam\Analytics\Scope;

/** Which party a reading reports. Install is the one kind until ticket 05 adds a tenant's. */
enum AnalyticsScopeKind: string
{
    case Install = 'install';
}
