> You are in **splicewire/laravel-beam-analytics** — analytics for beam hosts: the frontend tag, the Event Vocabulary, and the registries an Analytics section is filled from.

A beam-tier package (ADR-0242): it requires `splicewire/laravel-beam`, `schemastud/laravel-frame` and
`rushing/laravel-popcorn`, and `extra.package-topology` fences out beam-ux, tower, the satellite tier and
`laravel-tower-*`. Packages contribute to its registries by naming them as strings, with no composer edge.

Two Blade directives (`@beamAnalyticsHead` / `@beamAnalyticsBody`) render the analytics markup for
every configured-and-keyed provider at once and nothing when a provider is keyless, the env is
disallowed, or a provider name is unknown. GTM and GA4 ship as adapters; a
`splicewire:beam:analytics:doctor` command verifies directive placement and env/config coherence.
There are no server-side events here (ADR-0237 decision 9). Never reads a service-account key.
