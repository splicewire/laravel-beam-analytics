# laravel-beam-analytics

Provider-agnostic analytics tags, a declared Event Vocabulary and the registries an Analytics
section is filled from, for beam hosts. Beam's renderer requires this package as part of its base
recipe; measurement stays off until a provider has an id and the environment is allowed. It is a
beam-tier package ([ADR-0242](docs/adr/0242-analytics-is-a-beam-tier-package-and-its-base-still-never-reads-a-service-account-key.md)):
it requires `splicewire/laravel-beam`, `schemastud/laravel-frame` and `rushing/laravel-popcorn`, and
never beam-ux, tower or the satellite tier. It never reads a service-account key or provisions
analytics accounts.

## Install and wire

An application using `splicewire/laravel-beam-ux` already receives this package transitively.
For a host without the renderer:

```bash
composer require splicewire/laravel-beam-analytics:dev-main
```

That brings beam and frame with it. Under the fleet's Git-resolved package contract, the **root
application's** `composer.json` needs a source entry for this package and every first-party
transitive dependency. Composer
ignores a dependency's own `repositories`. Add this entry to the root's existing list:

```json
{ "type": "git", "url": "https://github.com/splicewire/laravel-beam-analytics.git" }
```

A local development overlay may supply the selected package sources instead; do not put
machine-specific paths into the shipping manifest. The Laravel provider is auto-discovered.
Defaults load from this package's [config/beam/analytics.php](config/beam/analytics.php) under
`beam.analytics`; put host overrides in `config/beam/analytics.php`.

The root Blade template needs both slots. Beam starters already carry them; add them when
adopting analytics in another host:

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

Standalone gate/maintenance roots need their own slots. Configure
`beam.analytics.root_templates` for the templates the doctor should inspect.

## Providers, ids and consent

Both adapters ship:

| Provider | Adapter | Environment id |
| --- | --- | --- |
| `gtm` | `Splicewire\Beam\Analytics\Providers\GtmProvider` | `BEAM_ANALYTICS_GTM_ID=GTM-EXAMPLE` |
| `ga4` | `Splicewire\Beam\Analytics\Providers\GaProvider` | `BEAM_ANALYTICS_GA4_ID=G-EXAMPLE` |

Prefer one measurement route: a GTM container carrying GA4 **or** direct GA4. Every active
provider renders, so enabling both for the same property can duplicate measurement. Direct
GA4 renders `gtag.js` in the head and no body markup. Other adapters implement
`Splicewire\Beam\Analytics\Contracts\AnalyticsProvider` and join the `providers` config map, or a
package registers one through the [`beam.analytics.providers`](docs/registries/beam.analytics.providers.mdx)
registry with no composer edge to this package.

The GTM id falls back to `GOOGLE_ANALYTICS_ID` only when its namespaced variable is unset;
an explicitly empty `BEAM_ANALYTICS_GTM_ID` disables that fallback. Ids resolve lazily through
`AnalyticsIdResolver::idFor(string $provider): ?string`; the default `ConfigIdResolver` reads
`beam.analytics.providers.<name>.id`. Hosts can bind the port to another id source.

- Empty ids or unknown/unresolvable adapters emit no markup for that provider.
- `BEAM_ANALYTICS_ENABLED_ENVS` is comma-separated and defaults to `production`; outside it,
  all tag output is suppressed. Leave real ids unset in local/CI wiring proofs.
- A verified renderer Preview (`beam.ux.preview` request attribute) suppresses both slots.
  The manager does not infer Preview from an unvalidated client cookie.

With an active provider, Consent Mode defaults precede its loader. Ad storage, ad user data
and ad personalization default to denied everywhere; analytics storage defaults to denied in
`EEA,GB,CH` and granted elsewhere. `EEA` expands to country codes. Configure
`BEAM_ANALYTICS_CONSENT_DENIED_REGIONS` or disable the defaults with
`BEAM_ANALYTICS_CONSENT=false`. No active provider means no consent script. These are tag
defaults; the packaged consent notice/update UI remains separate work.

## Event Vocabulary

[EventVocabulary](src/Events/EventVocabulary.php) combines package events with host-declared
names. It describes the available events and their parameter names; declaring an event does
not emit it or enforce required parameters, parameter value types or provider delivery.

| Package event | Declared parameters |
| --- | --- |
| `sign_up`, `login` | `method` |
| `generate_lead` | `currency`, `value` |
| `search` | `search_term` |
| `begin_checkout` | `currency`, `value`, `items` |
| `purchase` | `transaction_id`, `currency`, `value`, `items` |
| `select_content` | `content_type`, `content_id` |

Add host events beside these through the `events` member of `config/beam/analytics.php`:

```php
'events' => [
    'demo_requested' => ['source'],
],
```

Names must start with an ASCII letter, contain only letters, digits or underscores and be
at most 40 characters. A host cannot redeclare a package event. Parameters must be a list
of strings. The provider calls `EventVocabulary::validate()` at boot and invalid declarations
throw `InvalidArgumentException`; `has()` and `all()` also validate the host vocabulary.
Tenant-added event names are deferred. A package adds its own through the
[`beam.analytics.events`](docs/registries/beam.analytics.events.mdx) registry; those names are
served after the package's and before the host's, as `source: 'package'`.

```php
use Splicewire\Beam\Analytics\Events\EventVocabulary;

$vocabulary = app(EventVocabulary::class);
$vocabulary->has('demo_requested'); // true with the config above
$vocabulary->all(); // [{name, source: 'package'|'host', parameters}, ...]
```

### Renderer and client integration

The owning `splicewire/laravel-beam-ux` renderer exposes the vocabulary in the Manifest
Profile's `x-events`. For JsonDoc bodies, the block `clickEvent` shape is `{ name, label? }`:
save and publish guards check the name against that profile, including nested children, named slots and Repeat
empty content. The check does not validate event parameter values or send an event.

The source lens preserves a literal declaration such as:

```tsx
<a href="/contact" bdEvent={{ name: 'generate_lead', label: 'Hero CTA' }}>Contact</a>
```

The renderer prints the compiled form as a JSON `data-bd-event` attribute. Published
`SiteEntry` installs a delegated click listener, forwarding the optional label as
`event_label`. Preview disables that listener; the canvas does not install it.

The companion `@splicewire/beam-ux/site` source exports the common client dispatcher:

```ts
import { reportEvent } from '@splicewire/beam-ux/site';

reportEvent('demo_requested', { source: 'pricing' });
```

`reportEvent` calls `gtag('event', name, parameters)` when available, otherwise pushes one
`{ event: name, ...parameters }` to an existing `dataLayer`; it does not dispatch through
both branches. With neither global, an empty name or no browser window, it does nothing.
Top-level null/undefined parameters are omitted. This helper does not independently check
the vocabulary or Preview: custom callers own their admission and emission conditions.

These are source contracts across the analytics, renderer and JS packages. A PHP source
update does not refresh installed JS `dist` exports, compiled page artifacts or host bundles;
use the supported build/adoption path for the selected consumer. An older built bundle can
therefore lack this API even when current source declares it. Vocabulary entries alone do
not establish automatic reporting on every packaged surface; search and checkout reporting
remain separate unfinished integration work.

## The Analytics section

On a beam host this package seats an **Analytics** section in the `operator` and `tenant` realms, each under its realm's
own route base. The seat is flagged `overview`, so it lands on an overview with one card per **Analytics Area**, and each
card links to that area's page. Anyone holding `analytics.view` opens it. The package declares that entitlement key, and
beam-accounts' default resolver composes it from an `operator` or a `tenant` grant.

One set of area resources serves both realms, and the scope comes from the request: Tenant scope inside a tenant's
boundary, as beam's `TenancyContext` reports it, and Install scope otherwise. The package holds no topology code. What a
scope's site is set up with is read through the `MeasurementSettings` port, which answers the install from config and gives
a tenant nothing of the install's; a tenancy layer rebinds it so a tenant reads its own settings.

Each area is a class in the [`beam.analytics.areas`](docs/registries/beam.analytics.areas.mdx) registry, drawn as the
resource `analytics-{key}`. Its page and its card read one figures path, so they cannot disagree. Its `policy:` asks for
`analytics.view` first, so an area can narrow the gate and never widen it.

The package ships **Measurement**: which providers are on, the environments allowed to render a tag, the consent regions,
and whether the tag's slots are placed. It shows configuration and calls no provider. It also ships **Events**: the Event
Vocabulary, each event with its parameters and labelled as declared by a package or by the host, never as fired.

And it ships **Traffic**: a site's users, sessions and views, with when they were measured, read from a Traffic Source in
the [`beam.analytics.sources`](docs/registries/beam.analytics.sources.mdx) registry through a read-only, provider-neutral
port (`TrafficSource` returning a `TrafficSnapshot`). No source ships here, so out of the box Traffic reads "Not
connected". A reader's request never asks a source: it reads the last reading from the cache ("Not measured yet" before
the first), and a queued job refreshes a missing or stale one (`beam.analytics.traffic.ttl`, default 3600 s) inside the
scope's own tenant, refusing and reporting if it finds itself in another. A tenant no source covers reads "Not connected",
never the install's numbers. Beside it, three panels draw the reading through frame's existing widgets: **Traffic trend**
(`trend-card`, views per day), **Top pages** and **Referrers** (`stat-row`). They are section pages of their own, because a
trend card draws one resource's whole summary as its series.

Packages add areas from their own providers, naming the registry as a string, with no composer edge to this package.
`php artisan splicewire:beam:analytics:make-area` writes a conforming area, checks the class it wrote, and exits 1 on a
finding.

A figure links to a host page, such as Site settings or the site editor, only through the `AnalyticsDestinations` port.
The host binds it to answer a named destination (`site-settings`, `site-editor`) for the current scope with a route name,
its parameters and the ability that opens it. A link is drawn only where that route exists and the viewer passes that
ability. The default binding answers nothing, so a host that binds nothing gets no links. A destination never changes who
can read an area.

```php
use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Destinations\AnalyticsDestination;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

$this->app->bind(AnalyticsDestinations::class, fn () => new class implements AnalyticsDestinations {
    public function destination(string $name, AnalyticsScope $scope): ?AnalyticsDestination
    {
        return $name === AnalyticsDestinations::SITE_SETTINGS
            ? new AnalyticsDestination(routeName: 'site.settings', ability: 'site.edit')
            : null;
    }
});
```

## Doctor

```bash
php artisan splicewire:beam:analytics:doctor
# Reject warnings as well as failures:
php artisan splicewire:beam:analytics:doctor --floor=warn
```

The default `--floor=fail` exits nonzero only for failures:

| Condition | Finding |
| --- | --- |
| Empty provider map | Inconclusive “Analytics off” |
| Keyless provider outside / inside the environment allowlist | Inconclusive / warning |
| Unknown or unresolvable adapter | Failure |
| No existing configured root template | Unverified-placement warning |
| Existing roots lack the directive pair, analytics off / any provider active | Warning / failure |

Placement passes when **any** existing configured root contains both directives; it does
not prove every root is wired. Check each required root separately when verifying a host.
Environment posture is informational, not proof of delivery to a provider. When Beam's doctor
manifest is available, the package also registers this wiring audit as advisory.
