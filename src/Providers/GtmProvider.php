<?php

namespace Splicewire\BeamAnalytics\Providers;

use Splicewire\BeamAnalytics\Contracts\AnalyticsProvider;

/**
 * Google Tag Manager. Renders the standard container loader `<script>` in `<head>`
 * and the `<noscript>` iframe just after `<body>`, both keyed on ONE container id
 * (`GTM-XXXX`). Only a container id is ever configured — GA4 rides inside the
 * container, so there is no separate measurement tag to manage. GTM's own History
 * Trigger handles SPA route changes, so no client-side package is needed.
 *
 * Placement follows Google's documented install (head script + body noscript).
 */
class GtmProvider implements AnalyticsProvider
{
    public function __construct(private string $id) {}

    public function head(): string
    {
        $id = json_encode($this->id, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return <<<HTML
        <!-- Google Tag Manager -->
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer',{$id});</script>
        <!-- End Google Tag Manager -->

        HTML;
    }

    public function body(): string
    {
        $id = rawurlencode($this->id);
        $attr = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={$id}"
        height="0" width="0" style="display:none;visibility:hidden" title="{$attr}"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->

        HTML;
    }
}
