<?php

namespace Splicewire\Beam\Analytics\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Analytics\AnalyticsManager;

/**
 * The analytics wiring checks, extracted from the doctor command into the shared finding vocabulary
 * (particle-doctrine-followups ticket 08). Three checks, exactly as the command printed them:
 *
 *   1. Provider coherence — iterate EVERY configured provider and flag each incoherence
 *      independently: a keyless provider (in the map, empty id) or an unknown/unresolvable
 *      adapter. Each incoherence is a Fail finding.
 *   2. Directive placement — at least one configured root Blade template carries BOTH
 *      `@beamAnalyticsHead` and `@beamAnalyticsBody` (only checked while a provider is active;
 *      no template found at all is a Warn, an active provider with no placement is a Fail).
 *   3. Env posture — report-only (always Pass): whether the current env would render
 *      (in `enabled_envs`) or is suppressed here.
 *
 * A fully-empty `providers` map is analytics-intentionally-off: one Pass finding, nothing else.
 * Check names + details concatenate to the exact lines the command always printed
 * (`<check>: <detail>`), so extraction is provably behavior-preserving — the command's pinned
 * output-string assertions pass unmodified. Container-resolvable (the manager is bound), so the
 * shared DoctorRunner and the beam doctor manifest can both `make()` it.
 */
class AnalyticsWiringAudit implements DoctorAudit
{
    public function __construct(private AnalyticsManager $manager) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $statuses = $this->manager->providerStatuses();

        // --- Empty config: analytics intentionally off -------------------------------
        if ($statuses === []) {
            return [Finding::pass('Analytics off', 'no providers configured — nothing to render (this is fine).')];
        }

        $findings = [];

        // --- Check 1: per-provider coherence -----------------------------------------
        $active = 0;
        foreach ($statuses as $name => $s) {
            switch ($s['status']) {
                case 'active':
                    $active++;
                    $findings[] = Finding::pass("Provider '{$name}'", "keyed ({$s['id']}) via {$s['adapter']}.");
                    break;
                case 'keyless':
                    $findings[] = Finding::fail("Provider '{$name}'", 'configured but id is empty — renders nothing.');
                    break;
                case 'unknown':
                    $adapter = $s['adapter'] ?? '(none)';
                    $findings[] = Finding::fail("Provider '{$name}'", "unknown/unresolvable adapter '{$adapter}' — renders nothing.");
                    break;
            }
        }

        // --- Check 2: directive placement --------------------------------------------
        if ($active > 0) {
            [$placed, $checked] = $this->directivesPlaced();

            if ($checked === []) {
                $findings[] = Finding::warn('Directive placement unverified', 'no root template found — set `analytics.root_templates`.');
            } elseif ($placed !== []) {
                $findings[] = Finding::pass('Directive pair present in', implode(', ', $placed).'.');
            } else {
                $findings[] = Finding::fail(
                    'Directive pair (@beamAnalyticsHead + @beamAnalyticsBody) not found in any root template',
                    implode(', ', $checked).'.',
                );
            }
        }

        // --- Check 3: env posture ----------------------------------------------------
        $env = (string) config('app.env');

        $findings[] = $this->manager->envAllowed()
            ? Finding::pass("Env '{$env}' is allowlisted", 'active provider(s) would render here.')
            : Finding::pass("Env '{$env}' is not allowlisted", 'all output suppressed here (renders in `enabled_envs` only).');

        return $findings;
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

        foreach ((array) config('beam.analytics.root_templates', []) as $path) {
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
}
