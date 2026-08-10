> You are in **splicewire/laravel-beam-analytics** — provider-agnostic, opt-in, fail-closed frontend analytics for beam satellites.

Two Blade directives (`@beamAnalyticsHead` / `@beamAnalyticsBody`) render the analytics markup for
every configured-and-keyed provider at once and nothing when a provider is keyless, the env is
disallowed, or a provider name is unknown. GTM ships as the first adapter; a
`splicewire:beam:analytics:doctor` command verifies directive placement and env/config coherence.
Never reads a service-account key.
