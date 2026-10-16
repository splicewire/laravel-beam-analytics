<?php

namespace Splicewire\Beam\Analytics\Consent;

use Illuminate\Contracts\Config\Repository;

/**
 * ADR-0237 decision 7 — Consent Mode's fail-closed defaults, rendered BEFORE any provider's loader so
 * no Google tag measures before consent is set. Ad signals are denied EVERYWHERE (no beam site runs
 * ads); analytics storage is denied in the regions where the law asks for opt-in — the EEA, the United
 * Kingdom and Switzerland by default — and granted elsewhere. The central site and a Connected site may
 * change the regions in config; a Hosted site uses its install's. The {@see AnalyticsManager} renders
 * this once, before the providers, whenever a provider is active.
 *
 * Deferred, with its shape set (decision 7): the consent NOTICE — a packaged Code Component in a layout
 * that calls the tag's consent update. This class sets only the fail-closed DEFAULTS.
 */
class ConsentMode
{
    public function __construct(private Repository $config) {}

    /** The <script> that sets Consent Mode defaults, or '' when consent handling is turned off in config. */
    public function head(): string
    {
        $consent = (array) $this->config->get('beam.analytics.consent', []);

        if (($consent['enabled'] ?? true) === false) {
            return '';
        }

        $regions = array_values(array_filter(array_map(
            fn ($r): string => trim((string) $r),
            (array) ($consent['denied_regions'] ?? ['EEA', 'GB', 'CH']),
        )));

        $regionsJson = json_encode($regions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        // A global default (ad signals denied, analytics GRANTED) then a region-scoped default (analytics
        // DENIED in the listed regions). Google applies the most specific region match, so the regions get
        // the denied value and everywhere else keeps granted. gtag/dataLayer are bootstrapped here so the
        // defaults are set before any provider loader runs.
        return <<<HTML
        <!-- Consent Mode defaults (fail-closed, ADR-0237) -->
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
        gtag('consent','default',{'ad_storage':'denied','ad_user_data':'denied','ad_personalization':'denied','analytics_storage':'granted','wait_for_update':500});
        gtag('consent','default',{'ad_storage':'denied','ad_user_data':'denied','ad_personalization':'denied','analytics_storage':'denied','region':{$regionsJson},'wait_for_update':500});</script>
        <!-- End Consent Mode defaults -->

        HTML;
    }
}
