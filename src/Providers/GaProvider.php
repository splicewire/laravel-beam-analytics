<?php

namespace Splicewire\Beam\Analytics\Providers;

use Splicewire\Beam\Analytics\Contracts\AnalyticsProvider;

/**
 * ADR-0237 decision 5's provider half — a Google Analytics 4 adapter that renders Google's gtag.js for
 * a measurement id (G-XXXX): shipped code configured by an id, which the blocks-only rule allows. In
 * EP-04 this adapter is CONFIG-DRIVEN and proves the id port resolves TWO providers alongside
 * {@see GtmProvider}; it carries NO tenancy. The Hosted-site, tenancy-rebound id for a Site Host is
 * EP-33, not here.
 *
 * gtag's own history-change measurement counts SPA navigations in the property (ADR-0237 decision 3),
 * so there is no client-side package and no <body> <noscript>. Consent Mode defaults are set once by
 * {@see \Splicewire\Beam\Analytics\Consent\ConsentMode} BEFORE this loader; the `gtag`/`dataLayer`
 * bootstrap below is idempotent with that block (same definition), so order, not ownership, is what
 * matters.
 */
class GaProvider implements AnalyticsProvider
{
    public function __construct(private string $id) {}

    public function head(): string
    {
        $src = 'https://www.googletagmanager.com/gtag/js?id='.rawurlencode($this->id);
        $id = json_encode($this->id, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return <<<HTML
        <!-- Google Analytics (gtag.js) -->
        <script async src="{$src}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
        gtag('js',new Date());gtag('config',{$id});</script>
        <!-- End Google Analytics -->

        HTML;
    }

    public function body(): string
    {
        return '';
    }
}
