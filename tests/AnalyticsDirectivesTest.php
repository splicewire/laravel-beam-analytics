<?php

namespace Splicewire\BeamAnalytics\Tests;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\BeamAnalytics\Providers\GtmProvider;

/**
 * The single high seam (per the PRD testing decisions): the rendered HTML the two
 * directives produce given a config state. Asserts external behaviour only — never the
 * manager's internal wiring. Every consumer inherits whatever this proves.
 */
class AnalyticsDirectivesTest extends TestCase
{
    private function renderHead(): string
    {
        return Blade::render('@beamAnalyticsHead');
    }

    private function renderBody(): string
    {
        return Blade::render('@beamAnalyticsBody');
    }

    #[Test]
    public function a_keyed_gtm_provider_renders_in_an_allowlisted_env(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('GTM-ABC123')]);

        $head = $this->renderHead();
        $this->assertStringContainsString('GTM-ABC123', $head);
        $this->assertStringContainsString('googletagmanager.com/gtm.js', $head);

        $body = $this->renderBody();
        $this->assertStringContainsString('<noscript>', $body);
        $this->assertStringContainsString('GTM-ABC123', $body);
        $this->assertStringContainsString('googletagmanager.com/ns.html', $body);
    }

    #[Test]
    public function two_keyed_providers_both_render_concatenated(): void
    {
        // Two independently-keyed providers (both GTM adapters, distinct ids) prove the
        // manager AGGREGATES rather than selecting one — the non-exclusive amendment.
        $this->configure('production', [
            'gtm' => $this->gtm('GTM-FIRST01'),
            'gtm_secondary' => ['adapter' => GtmProvider::class, 'id' => 'GTM-SECND02'],
        ]);

        $head = $this->renderHead();
        $this->assertStringContainsString('GTM-FIRST01', $head);
        $this->assertStringContainsString('GTM-SECND02', $head);

        $body = $this->renderBody();
        $this->assertStringContainsString('GTM-FIRST01', $body);
        $this->assertStringContainsString('GTM-SECND02', $body);
    }

    #[Test]
    public function an_empty_id_renders_nothing_for_that_provider(): void
    {
        $this->configure('production', ['gtm' => $this->gtm('')]);

        $this->assertSame('', trim($this->renderHead()));
        $this->assertSame('', trim($this->renderBody()));
    }

    #[Test]
    public function an_env_outside_the_allowlist_renders_nothing_even_when_keyed(): void
    {
        $this->configure('local', ['gtm' => $this->gtm('GTM-ABC123')], enabledEnvs: ['production']);

        $this->assertSame('', trim($this->renderHead()));
        $this->assertSame('', trim($this->renderBody()));
    }

    #[Test]
    public function an_unknown_provider_name_resolves_to_null_output_not_a_fatal(): void
    {
        $this->configure('production', [
            'mystery' => ['adapter' => 'Splicewire\\BeamAnalytics\\NoSuchProvider', 'id' => 'X-123'],
        ]);

        $this->assertSame('', trim($this->renderHead()));
        $this->assertSame('', trim($this->renderBody()));
    }
}
