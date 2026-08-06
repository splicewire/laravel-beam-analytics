# laravel-beam-analytics

Provider-agnostic, opt-in, **fail-closed** frontend analytics for beam satellites.

Turning a satellite's analytics on is three moves: require the package, add one directive
pair to the root Blade template, and set a per-provider env id. Empty config renders
nothing — that emptiness *is* the per-site conditional. Google Tag Manager is the first
provider adapter; Plausible / Fathom / GA-direct are each one adapter class + one config
line later, with sites flipping purely via env.

This package covers the **frontend tag only**. It never reads a service-account key — the
Google service-account / API track (Drive, YouTube, Custom Search) is a separate concern.

## Install

```jsonc
// composer.json — ship contract
"repositories": [
  { "type": "vcs", "url": "…/splicewire/laravel-beam-analytics" }
]
// composer.local.json — co-dev overlay (path symlink)
"repositories": [
  { "type": "path", "url": "../packages/splicewire/laravel-beam-analytics" }
]
```

```bash
composer require splicewire/laravel-beam-analytics:dev-main
```

## Wire

Add the directive pair to the root Blade template — `@beamAnalyticsHead` in `<head>`,
`@beamAnalyticsBody` immediately after `<body>`:

```blade
<head>
    @beamAnalyticsHead
    @inertiaHead
</head>
<body>
    @beamAnalyticsBody
    @inertia
</body>
```

Then set the env id(s) in the site's production `.env`:

```dotenv
BEAM_ANALYTICS_GTM_ID=GTM-XXXXXXX
# BEAM_ANALYTICS_ENABLED_ENVS=production   # default; add staging if one exists
```

## Config

`config/analytics.php`:

- `enabled_envs` (`BEAM_ANALYTICS_ENABLED_ENVS`, default `production`) — outside these, nothing renders.
- `providers` — a map `name → { adapter: class-string, id }`. **Any number run at once**; each
  renders only when its id is non-empty and its adapter resolves, else nothing (fail-closed).
- **Global-var fallback.** The GTM id reads `BEAM_ANALYTICS_GTM_ID` first, then falls back to the
  conventional `GOOGLE_ANALYTICS_ID` (the ecosystem's well-known analytics env var) — the
  beam-namespaced var always wins when set. Prefer a GTM container id (`GTM-XXXX`); a bare GA4
  measurement id (`G-XXXX`) in the fallback is legacy-continuity only.

## Doctor

```bash
php artisan splicewire:beam:analytics:doctor
```

Asserts the directive pair is placed in a root template and that every configured provider
is coherent (keyed + resolvable). An empty config reports "analytics off" and passes; a
keyless or unknown provider fails non-zero for a CI / deploy gate.
