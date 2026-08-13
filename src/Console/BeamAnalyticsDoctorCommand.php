<?php

namespace Splicewire\Beam\Analytics\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\Concerns\RunsDoctorFloor;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorRunner;
use Splicewire\Beam\Analytics\Doctor\AnalyticsWiringAudit;

/**
 * `php artisan splicewire:beam:analytics:doctor` — verify a site's analytics wiring without loading
 * it in a browser. The checks live in {@see AnalyticsWiringAudit} (directive placement, per-provider
 * coherence, env posture — extracted there by particle-doctrine-followups ticket 08); the shared
 * {@see DoctorRunner} executes it as a gate registration at the `--floor` (default `fail`), and the
 * findings render as `<check>: <detail>` at info (Pass) / warn (Warn) / error (Fail) via
 * {@see RunsDoctorFloor} — the same lines this command always printed; the shared DoctorRenderer is
 * deliberately not adopted (the suite pins output strings exclusively).
 *
 * A fully-empty `providers` map is analytics-intentionally-off: it reports cleanly and the
 * command succeeds. Exits non-zero only on a real incoherence (keyless/unknown provider, or
 * an active provider with no directive placement) — or, at `--floor=warn`, on an unverified
 * directive placement — so CI / a deploy gate can block on it.
 */
class BeamAnalyticsDoctorCommand extends Command
{
    use RunsDoctorFloor;

    protected $signature = 'splicewire:beam:analytics:doctor
        {--floor=fail : Severity a finding must reach to fail the run (pass|warn|fail)}';

    protected $description = 'Verify analytics wiring: directive placement and per-provider config coherence.';

    public function handle(DoctorRunner $runner): int
    {
        $floor = $this->parseFloor();

        if ($floor === null) {
            return self::FAILURE;
        }

        [$report, $failed] = $this->runAtFloor($runner, [
            new DoctorRegistration('splicewire/laravel-beam-analytics', AnalyticsWiringAudit::class, gate: true),
        ], $floor);

        $this->renderFindings($report->findings);

        return $this->finish($failed);
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
