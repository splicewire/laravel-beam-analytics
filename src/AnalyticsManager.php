<?php

namespace Splicewire\BeamAnalytics;

use Illuminate\Contracts\Config\Repository;
use Splicewire\BeamAnalytics\Contracts\AnalyticsProvider;
use Splicewire\BeamAnalytics\Providers\NullProvider;

/**
 * The central fail-closed aggregator. Given the `analytics.*` config it resolves the
 * configured providers and concatenates their markup for each slot. Fail-closed at two
 * layers:
 *
 *   1. Env gate — if the current `app.env` is not in `enabled_envs`, EVERYTHING is off
 *      (both slots render empty), regardless of provider ids.
 *   2. Per-provider gate — a provider contributes markup only when it is `active`
 *      (non-empty id AND a resolvable AnalyticsProvider adapter); otherwise it resolves
 *      to a NullProvider (empty). A keyless provider or an unknown adapter degrades to
 *      silence, never a fatal error.
 *
 * Providers are NON-EXCLUSIVE: every active provider renders, concatenated.
 */
class AnalyticsManager
{
    public function __construct(private Repository $config) {}

    /** The concatenated `<head>` markup of every active provider (empty if env disallowed). */
    public function head(): string
    {
        return $this->render('head');
    }

    /** The concatenated after-`<body>` markup of every active provider (empty if env disallowed). */
    public function body(): string
    {
        return $this->render('body');
    }

    /** Whether the current env is allowed to emit tags at all. */
    public function envAllowed(): bool
    {
        $envs = (array) $this->config->get('analytics.enabled_envs', []);

        return in_array((string) $this->config->get('app.env'), $envs, true);
    }

    /**
     * Per-provider diagnostic, keyed by provider name. Status is one of:
     *   - `active`  : keyed and its adapter resolves — will render.
     *   - `keyless` : adapter resolves but the id is empty — renders nothing.
     *   - `unknown` : adapter is missing / not an AnalyticsProvider — renders nothing.
     *
     * @return array<string, array{id: string, adapter: ?string, status: string}>
     */
    public function providerStatuses(): array
    {
        $out = [];

        foreach ((array) $this->config->get('analytics.providers', []) as $name => $def) {
            $id = trim((string) ($def['id'] ?? ''));
            $adapter = $def['adapter'] ?? null;
            $known = is_string($adapter)
                && class_exists($adapter)
                && is_subclass_of($adapter, AnalyticsProvider::class);

            $status = ! $known ? 'unknown' : ($id === '' ? 'keyless' : 'active');

            $out[(string) $name] = [
                'id' => $id,
                'adapter' => is_string($adapter) ? $adapter : null,
                'status' => $status,
            ];
        }

        return $out;
    }

    /**
     * The resolved provider instance for every configured entry — the active adapter
     * (constructed with its id) or a NullProvider for keyless/unknown entries.
     *
     * @return list<AnalyticsProvider>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ($this->providerStatuses() as $status) {
            if ($status['status'] === 'active') {
                /** @var class-string<AnalyticsProvider> $adapter */
                $adapter = $status['adapter'];
                $providers[] = new $adapter($status['id']);
            } else {
                $providers[] = new NullProvider;
            }
        }

        return $providers;
    }

    private function render(string $slot): string
    {
        if (! $this->envAllowed()) {
            return '';
        }

        $out = '';
        foreach ($this->providers() as $provider) {
            $out .= $provider->{$slot}();
        }

        return $out;
    }
}
