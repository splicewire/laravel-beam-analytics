<?php

namespace Splicewire\Beam\Analytics\Tests\Support;

use RuntimeException;
use Splicewire\Beam\Analytics\Contracts\TrafficSource;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Analytics\Traffic\TrafficSnapshot;

/**
 * The ONLY Traffic Source in ticket 08's scope: a test fake. It covers the scopes a test names and returns the snapshot a test
 * sets per scope, or throws for a scope a test says fails. It counts every {@see read()}, with the scope it was asked for, so a
 * test can prove a reader's request never asks the source and a refresh asks it only for its own scope.
 */
final class FakeTrafficSource implements TrafficSource
{
    /** @var array<string, TrafficSnapshot|\Throwable> scope key => what read() returns (or throws) */
    public static array $readings = [];

    /** @var list<string> scope keys read() was asked for, in order */
    public static array $asked = [];

    /** @var list<string> `scope@days` for each read(), in order (ticket 09: which period a refresh asked for) */
    public static array $askedPeriods = [];

    public static function reset(): void
    {
        self::$readings = [];
        self::$asked = [];
        self::$askedPeriods = [];
    }

    public static function key(): string
    {
        return 'fake-traffic';
    }

    public function covers(AnalyticsScope $scope): bool
    {
        return array_key_exists(self::scopeKey($scope), self::$readings);
    }

    public function read(AnalyticsScope $scope, AnalyticsPeriod $period): TrafficSnapshot
    {
        $key = self::scopeKey($scope);
        self::$asked[] = $key;
        self::$askedPeriods[] = $key.'@'.$period->days;
        // A per-period reading (`install@7`) wins over the scope's, so a test can tell one period's numbers from another's.
        $reading = self::$readings[$key.'@'.$period->days] ?? self::$readings[$key] ?? throw new RuntimeException("The fake does not cover [{$key}].");

        if ($reading instanceof \Throwable) {
            throw $reading;
        }

        return $reading;
    }

    public static function scopeKey(AnalyticsScope $scope): string
    {
        return $scope->isTenant() ? 'tenant:'.$scope->tenantId : 'install';
    }

    /** A snapshot whose numbers say which scope it belongs to, so a test can tell one tenant's reading from another's. */
    public static function snapshot(int $base): TrafficSnapshot
    {
        return new TrafficSnapshot(
            users: $base,
            sessions: $base * 2,
            views: $base * 3,
            trend: ['2026-11-08' => $base, '2026-11-09' => $base + 1, '2026-11-10' => $base + 2],
            pages: [['label' => '/pricing', 'value' => $base * 2], ['label' => '/', 'value' => $base]],
            referrers: [['label' => 'google', 'value' => $base], ['label' => 'newsletter', 'value' => 1]],
        );
    }
}
