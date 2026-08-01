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
        config(['analytics.root_templates' => [$this->template]]);
    }

    #[Test]
    public function a_coherent_config_with_placed_directives_passes(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-ABC123')]);
        $this->rootTemplateWith('<head>@beamAnalyticsHead</head><body>@beamAnalyticsBody</body>');

        $this->artisan('splicewire:beam:analytics-doctor')
            ->expectsOutputToContain("Provider 'gtm': keyed")
            ->assertSuccessful();
    }

    #[Test]
    public function an_empty_config_reports_off_and_passes(): void
    {
        $this->configure('production', []);

        $this->artisan('splicewire:beam:analytics-doctor')
            ->expectsOutputToContain('Analytics off')
            ->assertSuccessful();
    }

    #[Test]
    public function a_keyless_provider_fails(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('')]);
        $this->rootTemplateWith('@beamAnalyticsHead @beamAnalyticsBody');

        $this->artisan('splicewire:beam:analytics-doctor')
            ->expectsOutputToContain('id is empty')
            ->assertFailed();
    }

    #[Test]
    public function an_unknown_provider_name_fails(): void
    {
        $this->configure('production', [
            'mystery' => ['adapter' => 'Splicewire\\Beam\\Analytics\\NoSuchProvider', 'id' => 'X-1'],
        ]);
        $this->rootTemplateWith('@beamAnalyticsHead @beamAnalyticsBody');

        $this->artisan('splicewire:beam:analytics-doctor')
            ->expectsOutputToContain('unknown/unresolvable adapter')
            ->assertFailed();
    }

    #[Test]
    public function an_active_provider_without_placed_directives_fails(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-ABC123')]);
        $this->rootTemplateWith('<head></head><body></body>');

        $this->artisan('splicewire:beam:analytics-doctor')
            ->expectsOutputToContain('not found in any root template')
            ->assertFailed();
    }
}
