<?php

namespace Splicewire\Beam\Analytics\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\DoctorFailed;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Analytics\Doctor\AnalyticsWiringAudit;

/**
 * `php artisan splicewire:beam:analytics:doctor` — verify a site's analytics wiring without loading
 * it in a browser. The checks live in {@see AnalyticsWiringAudit} (directive placement, per-provider
 * coherence, env posture — extracted there by particle-doctrine-followups ticket 08); the shared
 * {@see DoctorRunner} executes it as a gate registration at the `--floor` (default `fail`), and this
 * command renders the findings as `<check>: <detail>` at info (Pass) / warn (Warn) / error (Fail),
 * the same lines it always printed — the shared renderer is deliberately not adopted (the suite pins
 * output strings exclusively).
 *
 * A fully-empty `providers` map is analytics-intentionally-off: it reports cleanly and the
 * command succeeds. Exits non-zero only on a real incoherence (keyless/unknown provider, or
 * an active provider with no directive placement) — or, at `--floor=warn`, on an unverified
 * directive placement — so CI / a deploy gate can block on it.
 */
class BeamAnalyticsDoctorCommand extends Command
{
    protected $signature = 'splicewire:beam:analytics:doctor
        {--floor=fail : Severity a finding must reach to fail the run (pass|warn|fail)}';

    protected $description = 'Verify analytics wiring: directive placement and per-provider config coherence.';

    public function handle(DoctorRunner $runner): int
    {
        $floor = DoctorStatus::tryFrom(strtolower((string) $this->option('floor')));

        if ($floor === null) {
            $this->components->error('Invalid --floor value; expected one of: pass, warn, fail.');

            return self::FAILURE;
        }

        $failed = false;

        try {
            $report = $runner->run([
                new DoctorRegistration('splicewire/laravel-beam-analytics', AnalyticsWiringAudit::class, gate: true),
            ], $floor);
        } catch (DoctorFailed $failure) {
            $report = $failure->report;
            $failed = true;
        }

        foreach ($report->findings as $finding) {
            $this->render($finding);
        }

        return $this->finish($failed);
    }

    private function render(Finding $finding): void
    {
        match ($finding->status) {
            DoctorStatus::Pass => $this->components->info($finding->check.': '.$finding->detail),
            DoctorStatus::Warn => $this->components->warn($finding->check.': '.$finding->detail),
            DoctorStatus::Fail => $this->components->error($finding->check.': '.$finding->detail),
        };
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
