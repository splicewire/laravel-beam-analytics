<?php

namespace Splicewire\Beam\Analytics\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Analytics\AnalyticsManager;

/**
 * `php artisan splicewire:beam:analytics:doctor` — verify a site's analytics wiring without loading
 * it in a browser. Three checks:
 *
 *   1. Directive placement — at least one configured root Blade template carries BOTH
 *      `@beamAnalyticsHead` and `@beamAnalyticsBody`.
 *   2. Provider coherence — iterate EVERY configured provider and flag each incoherence
 *      independently: a keyless provider (in the map, empty id) or an unknown/unresolvable
 *      adapter.
 *   3. Env posture — report whether the current env would render (in `enabled_envs`) or is
 *      suppressed here.
 *
 * A fully-empty `providers` map is analytics-intentionally-off: it reports cleanly and the
 * command succeeds. Exits non-zero only on a real incoherence (keyless/unknown provider, or
 * an active provider with no directive placement) so CI / a deploy gate can block on it.
 */
class BeamAnalyticsDoctorCommand extends Command
{
    protected $signature = 'splicewire:beam:analytics:doctor';

    protected $description = 'Verify analytics wiring: directive placement and per-provider config coherence.';

    public function handle(AnalyticsManager $manager): int
    {
        $failed = false;
        $env = (string) config('app.env');
        $statuses = $manager->providerStatuses();

        // --- Empty config: analytics intentionally off -------------------------------
        if ($statuses === []) {
            $this->components->info('Analytics off: no providers configured — nothing to render (this is fine).');

            return self::SUCCESS;
        }

        // --- Check 1: per-provider coherence -----------------------------------------
        $active = 0;
        foreach ($statuses as $name => $s) {
            switch ($s['status']) {
                case 'active':
                    $active++;
                    $this->components->info("Provider '{$name}': keyed ({$s['id']}) via {$s['adapter']}.");
                    break;
                case 'keyless':
                    $this->components->error("Provider '{$name}': configured but id is empty — renders nothing.");
                    $failed = true;
                    break;
                case 'unknown':
                    $adapter = $s['adapter'] ?? '(none)';
                    $this->components->error("Provider '{$name}': unknown/unresolvable adapter '{$adapter}' — renders nothing.");
                    $failed = true;
                    break;
            }
        }

        // --- Check 2: directive placement --------------------------------------------
        if ($active > 0) {
            [$placed, $checked] = $this->directivesPlaced();

            if ($checked === []) {
                $this->components->warn('Directive placement unverified: no root template found — set `analytics.root_templates`.');
            } elseif ($placed !== []) {
                $this->components->info('Directive pair present in: '.implode(', ', $placed).'.');
            } else {
                $this->components->error(
                    'Directive pair (@beamAnalyticsHead + @beamAnalyticsBody) not found in any root template: '
                    .implode(', ', $checked).'.',
                );
                $failed = true;
            }
        }

        // --- Check 3: env posture ----------------------------------------------------
        if ($manager->envAllowed()) {
            $this->components->info("Env '{$env}' is allowlisted: active provider(s) would render here.");
        } else {
            $this->components->info("Env '{$env}' is not allowlisted: all output suppressed here (renders in `enabled_envs` only).");
        }

        return $this->finish($failed);
    }

    /**
     * Which configured root templates that exist carry BOTH directives, and which were checked.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function directivesPlaced(): array
    {
        $placed = [];
        $checked = [];

        foreach ((array) config('analytics.root_templates', []) as $path) {
            $path = (string) $path;
            if ($path === '' || ! is_file($path)) {
                continue;
            }
            $checked[] = $path;
            $blade = (string) file_get_contents($path);
            if (str_contains($blade, '@beamAnalyticsHead') && str_contains($blade, '@beamAnalyticsBody')) {
                $placed[] = $path;
            }
        }

        return [$placed, $checked];
    }

    private function finish(bool $failed): int
    {
        if ($failed) {
            $this->newLine();
            $this->components->error('Analytics wiring is incoherent — fix the flagged item(s) above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
