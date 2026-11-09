<?php

namespace Splicewire\Beam\Analytics\Scope;

use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * Whose figures an area reports. Inside a tenant's boundary, including a `sole` install's one tenant, that is the tenant:
 * Tenant scope. On the operator side, and on an install with no tenancy, it is the install: Install scope.
 *
 * Decided from beam's {@see TenancyContext}, which the route boundary has already initialized, so this package holds no
 * topology code: it never reads hosts, paths or a tenancy package. Bound scoped, so it is decided once per request.
 */
final class AnalyticsScope
{
    private function __construct(
        public readonly AnalyticsScopeKind $kind,
        public readonly int|string|null $tenantId = null,
    ) {}

    public static function install(): self
    {
        return new self(AnalyticsScopeKind::Install);
    }

    public static function tenant(int|string $tenantId): self
    {
        return new self(AnalyticsScopeKind::Tenant, $tenantId);
    }

    /** Tenant scope when the request is inside a tenant's boundary, Install scope otherwise. */
    public static function fromTenancy(TenancyContext $tenancy): self
    {
        $id = $tenancy->currentTenant() !== null ? $tenancy->currentTenantId() : null;

        return $id === null || $id === '' ? self::install() : self::tenant($id);
    }

    public function isInstall(): bool
    {
        return $this->kind === AnalyticsScopeKind::Install;
    }

    public function isTenant(): bool
    {
        return $this->kind === AnalyticsScopeKind::Tenant;
    }
}
