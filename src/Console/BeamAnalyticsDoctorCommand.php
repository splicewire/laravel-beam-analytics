<?php

namespace Splicewire\Beam\Analytics\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Analytics\Doctor\AnalyticsWiringAudit;

/**
 * `php artisan splicewire:beam:analytics:doctor` — verify a site's analytics wiring without loading
 * it in a browser. The checks live in {@see AnalyticsWiringAudit} (directive placement, per-provider
 * coherence, env posture — extracted there by particle-doctrine-followups ticket 08); this command
 * renders its findings as `<check>: <detail>` at info (Pass) / warn (Warn) / error (Fail), the same
 * lines it always printed.
 *
 * A fully-empty `providers` map is analytics-intentionally-off: it reports cleanly and the
 * command succeeds. Exits non-zero only on a real incoherence (keyless/unknown provider, or
 * an active provider with no directive placement) so CI / a deploy gate can block on it.
 */
class BeamAnalyticsDoctorCommand extends Command
{
    protected $signature = 'splicewire:beam:analytics:doctor';

    protected $description = 'Verify analytics wiring: directive placement and per-provider config coherence.';

    public function handle(AnalyticsWiringAudit $audit): int
    {
        $failed = false;

        foreach ($audit->run() as $finding) {
            $this->render($finding);
            $failed = $failed || $finding->status === DoctorStatus::Fail;
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
