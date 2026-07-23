<?php

use Splicewire\BeamAnalytics\Providers\GtmProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Render environment allowlist
    |--------------------------------------------------------------------------
    |
    | The environments permitted to emit real analytics tags. A comma-separated
    | list (e.g. "production,staging"). Defaults to `production` only, so local
    | and CI never emit a real tag or pollute analytics. When the current
    | `app.env` is not in this list NOTHING renders, regardless of provider ids —
    | this is the outermost fail-closed gate.
    |
    */
    'enabled_envs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BEAM_ANALYTICS_ENABLED_ENVS', 'production')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Providers (non-exclusive)
    |--------------------------------------------------------------------------
    |
    | A map keyed by provider name; each entry is `{ adapter: class-string, id }`.
    | Any number of providers may run AT ONCE — the manager aggregates every
    | keyed provider rather than selecting a single one. A provider contributes
    | markup only when its `id` is non-empty AND its `adapter` resolves to an
    | AnalyticsProvider; otherwise it renders nothing (fail-closed), never fatal.
    |
    | Env prefix `BEAM_ANALYTICS_*` matches the fleet convention. An empty id is
    | analytics OFF for that provider — that emptiness IS the per-site conditional.
    | Adding a second provider (Plausible, Fathom, …) is one adapter class + one
    | line here; sites flip purely via env, with no site-file change.
    |
    */
    'providers' => [
        'gtm' => [
            'adapter' => GtmProvider::class,
            'id' => env('BEAM_ANALYTICS_GTM_ID'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Root templates (doctor)
    |--------------------------------------------------------------------------
    |
    | Candidate root Blade templates `beam-analytics:doctor` greps to confirm the
    | directive pair is placed. Both fleet root-Blade shapes live here:
    | splicewire-app's `resources/views/app.blade.php` and the satellite default.
    | Doctor passes if ANY listed template that exists carries both directives.
    |
    */
    'root_templates' => array_values(array_filter([
        function_exists('resource_path') ? resource_path('views/app.blade.php') : null,
    ])),
];
