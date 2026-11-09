<?php

namespace Splicewire\Beam\Analytics\Scope;

/** Which party a reading reports: the install, or one tenant inside its own boundary. */
enum AnalyticsScopeKind: string
{
    case Install = 'install';
    case Tenant = 'tenant';
}
