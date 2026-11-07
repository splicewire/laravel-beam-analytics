# ADR-0242 — Analytics is a beam-tier package, and its base still never reads a service-account key

Status: **accepted**. Decided in the owner's session of 2026-11-07, recorded in analytics-area ticket 02.
Date: 2026-11-07
Amends: [ADR-0237](0237-every-site-carries-the-analytics-tag-off-until-it-has-an-id-and-a-hosted-site-measures-with-its-own-ga4-id.md),
its statement that this package "requires nothing of beam's".
Wayfinding: `.scratch/splicewire/laravel-beam-analytics/analytics-area/` (`PRD.md`, `tickets/02-analytics-joins-the-beam-tier.md`).

## Context

ADR-0237 left this package a lean frontend tag: it required nothing of beam's, and it reached beam's install and doctor
manifests by string class-name so it could boot without beam. That was a position in prose. The package declared no
`package-topology`, so nothing enforced it.

The owner now wants a permission-gated Analytics section whose areas packages fill through registries. That section is
built from particles and Data classes, which live in `splicewire/laravel-beam` and `schemastud/laravel-frame`. The owner
ruled that it extends this package, rather than a new one, with hard requires on beam, frame and popcorn and none on
beam-ux.

The edge costs little in practice, because beam-ux requires this package and beam-ux itself requires beam. The ticket's
lock census records what it found across hosts and starters, and what a lock cannot show.

## Decision

1. **This package requires `splicewire/laravel-beam`, `schemastud/laravel-frame` and `rushing/laravel-popcorn`.** Its
   code may use particles and Data classes. Its PHP floor is `^8.3`, which those packages already need.
2. **It never requires beam-ux, tower, the satellite tier or any `laravel-tower-*` package.** `extra.package-topology`
   declares this and `tests/Topology/OwnTopologyDeclarationTest.php` pins it. beam-ux requires this package to carry the
   tag on every site, so an edge back would be a cycle.
3. **Packages contribute without an edge.** They name this package's registries as strings from their own provider, so an
   optional package stays optional in both directions.
4. **ADR-0237 decision 9 stands.** There are no server-side events here. A site's Analytics Events are reported from the
   browser, through the tag.
5. **This base package never reads a service-account key.** Reading a Google property with a credential is a separate,
   optional child package.
6. **Everything else in ADR-0237 stands.** Every site carries the tag, off until it has an id, and the other gates stay.

## Consequences

- **"Installs without Beam" is no longer true.** A host without the renderer gets beam and frame with this package, and
  its root `composer.json` needs a source entry for each first-party package in the closure.
- **The string class-name guards around beam's install and doctor manifests stay.** They are no longer about a host
  without beam, which is not a supported install. They remain because this package's test harness boots it without
  beam's provider, and removing them is not needed for anything here.
- **Local data stays a read over what packages already hold.** ADR-0237's position on new tables, cookies and consent
  questions does not change.
- **Every consumer gets the edge.** Host boot is proven by later tickets on real host shapes, not by this one.

## Considered and not taken

- **A new package for the section, leaving this one lean.** The owner ruled for extending this package.
- **An edge to beam-ux for its realm dashboards.** That would be a cycle, because beam-ux requires this package.
