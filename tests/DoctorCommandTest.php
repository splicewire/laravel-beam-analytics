<?php

namespace Splicewire\Beam\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;

class DoctorCommandTest extends TestCase
{
    private ?string $template = null;

    protected function tearDown(): void
    {
        if ($this->template !== null && is_file($this->template)) {
            @unlink($this->template);
        }

        parent::tearDown();
    }

    /** Write a throwaway root template and point the doctor's `root_templates` at it. */
    private function rootTemplateWith(string $blade): void
    {
        $this->template = sys_get_temp_dir().'/beam-analytics-root-'.uniqid().'.blade.php';
        file_put_contents($this->template, $blade);
        config(['beam.analytics.root_templates' => [$this->template]]);
    }

    #[Test]
    public function a_coherent_config_with_placed_directives_passes(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-ABC123')]);
        $this->rootTemplateWith('<head>@beamAnalyticsHead</head><body>@beamAnalyticsBody</body>');

        $this->artisan('splicewire:beam:analytics:doctor')
            ->expectsOutputToContain("Provider 'gtm': keyed")
            ->assertSuccessful();
    }

    #[Test]
    public function an_empty_config_reports_off_and_passes(): void
    {
        $this->configure('production', []);

        $this->artisan('splicewire:beam:analytics:doctor')
            ->expectsOutputToContain('Analytics off')
            ->assertSuccessful();
    }

    #[Test]
    public function a_keyless_provider_reads_as_off_not_as_a_failure(): void
    {
        // ADR-0237 d2: a keyless provider is "Analytics off" — a WARNING inside an allowed env, never a
        // failure. A warn passes the default (fail) floor, so the command succeeds.
        $this->configure('production', ['gtm' => $this->gtm('')]);
        $this->rootTemplateWith('<head>@beamAnalyticsHead</head><body>@beamAnalyticsBody</body>');

        $this->artisan('splicewire:beam:analytics:doctor')
            ->expectsOutputToContain('Analytics off')
            ->assertSuccessful();
    }

    #[Test]
    public function a_keyless_provider_off_reading_passes_outside_an_allowed_env(): void
    {
        // ADR-0237 d2: outside an allowed env, "Analytics off" measured nothing — a plain pass (no warn).
        $this->configure('local', ['gtm' => $this->gtm('')]);
        $this->rootTemplateWith('<head>@beamAnalyticsHead</head><body>@beamAnalyticsBody</body>');

        $this->artisan('splicewire:beam:analytics:doctor', ['--floor' => 'warn'])
            ->expectsOutputToContain('Analytics off')
            ->assertSuccessful();
    }

    #[Test]
    public function a_slot_placement_gap_is_reported_even_without_an_id(): void
    {
        // ADR-0237 d2: the doctor checks slot placement whether or not a provider has an id.
        $this->configure('production', ['gtm' => $this->gtm('')]);
        $this->rootTemplateWith('<head></head><body></body>'); // directives NOT placed

        $this->artisan('splicewire:beam:analytics:doctor')
            ->expectsOutputToContain('not found in any root template')
            ->assertSuccessful(); // keyless ⇒ not active ⇒ the unplaced-slot finding is a WARN, passes the default floor
    }

    #[Test]
    public function an_unknown_provider_name_fails(): void
    {
        $this->configure('production', [
            'mystery' => ['adapter' => 'Splicewire\\Beam\\Analytics\\NoSuchProvider', 'id' => 'X-1'],
        ]);
        $this->rootTemplateWith('@beamAnalyticsHead @beamAnalyticsBody');

        $this->artisan('splicewire:beam:analytics:doctor')
            ->expectsOutputToContain('unknown/unresolvable adapter')
            ->assertFailed();
    }

    #[Test]
    public function an_active_provider_without_placed_directives_fails(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-ABC123')]);
        $this->rootTemplateWith('<head></head><body></body>');

        $this->artisan('splicewire:beam:analytics:doctor')
            ->expectsOutputToContain('not found in any root template')
            ->assertFailed();
    }

    // --- --floor (particle-doctrine-followups ticket 08): the unverified-placement WARN is the
    // --- only non-pass finding, so the floor is the only variable between these two runs.

    #[Test]
    public function an_unverified_directive_placement_passes_at_the_default_fail_floor(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-ABC123')]);
        config(['beam.analytics.root_templates' => []]);

        $this->artisan('splicewire:beam:analytics:doctor')
            ->expectsOutputToContain('Directive placement unverified')
            ->assertSuccessful();
    }

    #[Test]
    public function an_unverified_directive_placement_fails_at_a_warn_floor(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-ABC123')]);
        config(['beam.analytics.root_templates' => []]);

        $this->artisan('splicewire:beam:analytics:doctor', ['--floor' => 'warn'])
            ->expectsOutputToContain('Directive placement unverified')
            ->assertFailed();
    }
}
