# ADR-0237 — Every site carries the Analytics Tag, off until it has an id, and a Hosted site measures with its own GA4 id

Status: **accepted**. Decided in editor-parity ticket 17, 2026-10-16, under the owner's standing instruction of that day
to accept every recommended answer. There was no live exchange; the ticket records each question and its options.
Date: 2026-10-16
Builds on: nothing in this repo; this is its first ADR.
Companions: `splicewire/laravel-beam-ux docs/adr/0234-the-renderer-composes-a-pages-head-on-the-server-with-its-structured-data.md`
(the Page Head, the Click Event, the brand on a Site Host) and beam JS `CONTEXT.md` (**Analytics Tag**, **Analytics
Event**, **Event Vocabulary**, **Site Settings**).
Cites, without editing: `splicewire/laravel-beam-ux docs/adr/0232-a-preview-serves-a-release-on-the-sites-own-urls-and-a-version-holds-everything-a-guest-sees.md`
decision 12 (a Preview fires no analytics tag or event), `splicewire/laravel-beam-ux docs/adr/0227-a-tenant-owned-entry-holds-blocks-not-code-and-starts-as-a-pack-copy.md`
decision 1 (a tenant-owned entry holds no code), `splicewire/tower docs/adr/0008-a-tenants-site-is-a-hosted-site-served-from-its-own-schema-on-its-own-origin.md`
(a Hosted site on its own origin, edited under `site.edit` in a tenant Site section) and `splicewire/laravel-beam-ux
docs/adr/0236-a-moved-page-leaves-a-redirect-at-its-old-url-and-a-hidden-page-hides-the-pages-under-it.md` decision 14
(the Site section's Redirects, which a Scaffold Pack doesn't carry either).

## Context

Measured 2026-10-16 at this package's `e6a636e`, unless a line says otherwise:

- **What it renders.** Two Blade directives print the markup of every provider that has an id, and only in the
  environments `enabled_envs` lists, `production` by default (`config/beam/analytics.php:19-22`;
  `src/AnalyticsManager.php:40-45`, `:101-105`). One provider ships, Google Tag Manager: a container loader and its
  `<noscript>` frame (`src/Providers/GtmProvider.php:20-48`). Ids come from config only (`src/AnalyticsManager.php:59`;
  `config/beam/analytics.php:50`).
- **Who has it.** Only the flagship installs it and places the directives (`~/Herd/splicewire-app/resources/views/app.blade.php:23,27`;
  editor-parity research 16, 2026-10-14). The five starters' one root template places neither (re-read 2026-10-16).
- **An installed site without an id fails.** The standalone doctor reads a keyless provider as a Fail
  (`src/Doctor/AnalyticsWiringAudit.php:54-56`, pinned by `tests/DoctorCommandTest.php:50-58`) and checks the directives
  only while a provider has an id (`:65`). Its gate rests on installing the package being the opt-in (beam-facade ticket
  119's table of gating audits).
- **The runbook makes analytics opt-in,** its interview defaulting it off (`splicewire-beam-runbook` `RUNBOOK.md:137-139`;
  `references/analytics.md:5-9`).
- **Events and consent.** Only thingsontv reports an event (`affiliate_click`, from a delegated click listener). The
  flagship's declarative `data-sw-event` tracker went with its marketing views on 2026-07-03. No package has an event
  API, and no host sets Consent Mode (research 16).
- **A container runs its owner's code.** A GTM Custom HTML tag takes "your own custom HTML or JavaScript code" (Tag
  Manager help, `support.google.com/tagmanager/answer/6107167`). A Hosted site refuses code: ADR-0227 decision 1, and a
  tenant that needs code has a Connected site.
- **Google's side.** Consent Mode takes its defaults before the tag measures, and a default can be scoped to regions
  (`developers.google.com/tag-platform/security/guides/consent`). GA4 counts a page view when the browser history
  changes, as an option of enhanced measurement (`support.google.com/analytics/answer/9216061`). `sign_up`, `login`,
  `generate_lead`, `search`, `begin_checkout`, `purchase` and `select_content` are GA4's recommended event names
  (`support.google.com/analytics/answer/9267735`). Whether the estate's properties have the history option on is set
  at Google and wasn't read.

## Decision

1. **Every site carries the Analytics Tag.** `splicewire/laravel-beam-ux` requires this package, so every site with the
   renderer has it. The starters' root template places its head and body slots, and each existing host adds them when
   it adopts the renderer, or sooner. The runbook's analytics step joins its base recipe and stops being an opt-in
   branch.
2. **It is off until it has an id.** A site with no id renders nothing, and the doctor reads that as "Analytics off": a
   pass that measured nothing outside an allowed environment, and a warning inside one. The doctor checks the slots'
   placement whether or not a provider has an id. The other gates stay: only allowed environments render, an empty id
   renders nothing, and an unknown provider renders nothing.
3. **The tag sits in the root template, outside Inertia's head.** It loads once per document and survives client
   navigation. A page view after a client navigation comes from GA4's history-change measurement in the property, not
   from the package, so none is counted twice; provisioning keeps that option on. The renderer suppresses the tag during
   a Preview (ADR-0232 decision 12).
4. **A provider's id is resolved per request, through a port.** Its default reads config, as today. The tenancy layer
   rebinds it for a Site Host, as it rebinds the sitemap's base URL.
5. **A Hosted site measures with its own GA4 measurement id.** A new GA4 provider renders Google's tag for that id:
   shipped code configured by an id, which the blocks-only rule allows. A container id is refused on a Hosted site,
   because a container runs whatever its owner adds; a tenant that needs one has a Connected site. The install's own
   container never renders on a Site Host, and a Hosted site with no id renders no tag.
6. **A Hosted site's Site Settings are its tenant's own.** Its brand (its name and logo) and its measurement id are held
   in its tenant's schema, edited in its Site section by members holding `site.edit`, and take effect when saved, as its
   domains do. A Scaffold Pack never carries them: a copied id would put the source tenant's property on every new site.
   The central site's and a Connected site's come from their configuration, as today.
7. **Consent defaults fail closed.** Before the tag loads, the package sets Consent Mode's defaults: the ad signals denied
   everywhere, since no beam site runs ads, and analytics storage denied in the regions where the law asks for opt-in
   and granted elsewhere. The list defaults to the EEA, the United Kingdom and Switzerland. The central site and a
   Connected site may change it in config; a Hosted site uses its install's. Deferred, with its shape set: the consent
   notice, a packaged Code Component in a layout that calls the tag's consent update.
8. **Analytics Events go through the tag.** The **Event Vocabulary** declares each event a site may report, with its
   parameters. The package's half is GA4's recommended names for what packaged surfaces do (`sign_up`, `login`,
   `generate_lead`, `search`, `begin_checkout`, `purchase`, `select_content`), and a host declares its own names beside
   them. Each packaged surface reports what it completes, and an author can name a block's Click Event (ADR-0234
   decision 13). One packaged client call reports an event, and the provider decides how it reaches the property: the
   container's data layer for GTM, GA4's event call for the GA4 provider. Nothing fires without a tag, in the canvas or
   in a Preview, and an event's consent is the tag's. Names a tenant adds are deferred.
9. **No server-side events here.** A site's Analytics Events are reported from the browser, through the tag. A
   server-side event pipeline, such as the Measurement Protocol or xAPI for learning records, is a separate effort.

## Consequences

- **The provider contract grows.** A provider renders the consent defaults before its loader and says how an event
  reaches its property. `GtmProvider` gains both, and a GA4 provider joins it.
- **The doctor's pinned behaviour changes.** `a_keyless_provider_fails` becomes a keyless provider reading as off, a
  warning in an allowed environment, and an unplaced slot is reported with or without an id.
- **`laravel-beam-ux` reads the Event Vocabulary from here** and serves it in the Manifest Profile (ADR-0234 decision
  14). This package still requires nothing of beam's.
- **Visitors in the listed regions set no analytics cookie until a consent notice exists.** Their visits count only as
  far as Consent Mode's denied state allows.
- **The tenant Site section gains Settings,** beside Entries, Theme, Releases, Redirects and Collections.
- **The runbook changes:** `references/analytics.md` stops being an opt-in branch, and its doctor command, config path
  and written-out container id get fixed (a launch-00 line, 2026-10-16).
- **Nothing changes locally.** A `local` host renders no tag, as today.

## Considered and not taken

- **Analytics kept opt-in.** A fresh site would have no tag, and each satellite would wire it by hand, which is where
  adoption stood at 1 of 17 hosts.
- **One install-wide id for every site.** It breaks the estate's one container per brand, and it would measure every
  tenant's visitors in the platform's property.
- **Container ids on Hosted sites.** A container runs its owner's code on the Site Host, which ADR-0227 refuses.
- **The install's container measuring every Hosted site.** Same objection as the install-wide id, for every tenant.
- **The tag inside the Page Head.** Inertia manages that head, so it would remove and re-add the loader on every
  navigation.
- **A `page_view` push on every client navigation.** With GA4's history option on, every navigation would count twice.
- **No consent handling.** It sets analytics cookies for visitors where the law asks for opt-in.
- **A consent notice on every site now.** Every visitor would see a banner, including where no consent is needed.
- **No tag until consent.** It drops every visitor's data where no consent is needed.
- **Free-text event names, GA4's names alone, or a host's names alone.** Free text spells one event three ways, GA4's
  names can't say "request a demo", and host names alone make every host declare `sign_up` again.
- **Site Settings as a versioned entry, like the theme.** A Scaffold Pack copies entries, which would carry the id, and
  neither an id nor a site's name needs review before it goes live.
