<?php

namespace Splicewire\Beam\Analytics\Tests;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver;
use Splicewire\Beam\Analytics\Providers\GaProvider;
use Splicewire\Beam\Analytics\Providers\GtmProvider;

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
            'mystery' => ['adapter' => 'Splicewire\\Beam\\Analytics\\NoSuchProvider', 'id' => 'X-123'],
        ]);

        $this->assertSame('', trim($this->renderHead()));
        $this->assertSame('', trim($this->renderBody()));
    }

    #[Test]
    public function a_keyed_provider_sets_consent_defaults_before_its_loader(): void
    {
        // ADR-0237 d7: Consent Mode's fail-closed defaults render BEFORE the provider loader — ad signals
        // denied everywhere, analytics denied in the listed regions (EEA/GB/CH by default).
        $this->configure('production', ['gtm' => $this->gtm('GTM-ABC123')]);

        $head = $this->renderHead();
        $this->assertStringContainsString("gtag('consent','default'", $head);
        $this->assertStringContainsString("'ad_storage':'denied'", $head);
        $this->assertStringContainsString("'analytics_storage':'denied'", $head);
        // ADR-0237 d7 / EP-04 HIGH-1: the region default must use ISO 3166-2 codes, not the word "EEA"
        // (Consent Mode ignores "EEA", which would fail OPEN for the EU). The EEA shorthand expands to the
        // country codes — DE, FR, IS present — and no literal "EEA" reaches gtag.
        $this->assertStringContainsString('"DE"', $head);
        $this->assertStringContainsString('"FR"', $head);
        $this->assertStringContainsString('"IS"', $head);
        $this->assertStringNotContainsString('EEA', $head);

        $this->assertLessThan(
            strpos($head, 'googletagmanager.com/gtm.js'),
            strpos($head, "gtag('consent','default'"),
            'Consent defaults must render before the tag loader.',
        );
    }

    #[Test]
    public function the_provider_id_resolves_through_the_id_port(): void
    {
        // ADR-0237 d4: the id comes through the AnalyticsIdResolver port, not straight from config.
        // Rebinding the port (as the tenancy layer does for a Site Host, EP-33) changes the rendered id
        // without touching the config value.
        $this->configure('production', ['gtm' => $this->gtm('GTM-CONFIG01')]);
        $this->app->bind(AnalyticsIdResolver::class, fn () => new class implements AnalyticsIdResolver
        {
            public function idFor(string $provider): ?string
            {
                return 'GTM-PORT9999';
            }
        });

        $head = $this->renderHead();
        $this->assertStringContainsString('GTM-PORT9999', $head);
        $this->assertStringNotContainsString('GTM-CONFIG01', $head);
    }

    #[Test]
    public function a_keyed_ga4_provider_renders_gtag_with_no_noscript_body(): void
    {
        // ADR-0237 d5's provider half (config-driven in EP-04): a GA4 adapter renders gtag.js for a
        // measurement id and has no <noscript> body; consent still precedes it.
        $this->configure('production', ['ga4' => ['adapter' => GaProvider::class, 'id' => 'G-TEST12345']]);

        $head = $this->renderHead();
        $this->assertStringContainsString('gtag/js?id=G-TEST12345', $head);
        $this->assertStringContainsString('G-TEST12345', $head);
        $this->assertStringContainsString("gtag('config',", $head);
        $this->assertLessThan(
            strpos($head, 'gtag/js?id='),
            strpos($head, "gtag('consent','default'"),
            'Consent defaults must render before the GA4 loader.',
        );

        $this->assertSame('', trim($this->renderBody()));
    }
}
