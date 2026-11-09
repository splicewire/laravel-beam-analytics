<?php

namespace Splicewire\Beam\Analytics\Scope;

/**
 * Whose figures an area reports. On a tenancy-free install, and on the operator side, that is the install: Install scope.
 *
 * Bound scoped, so it is decided once per request. Tenant scope, decided from the request's tenancy boundary, is ticket
 * 05's; until then every reading is in Install scope, and the section is seated in the operator realm only.
 */
final class AnalyticsScope
{
    private function __construct(
        public readonly AnalyticsScopeKind $kind,
    ) {}

    public static function install(): self
    {
        return new self(AnalyticsScopeKind::Install);
    }

    public function isInstall(): bool
    {
        return $this->kind === AnalyticsScopeKind::Install;
    }
}
