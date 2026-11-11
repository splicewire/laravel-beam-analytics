<?php

namespace Splicewire\Beam\Analytics;

use Illuminate\Support\Facades\Blade;
use Rushing\DataFilters\DataFilterManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaResources;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Console\BeamAnalyticsDoctorCommand;
use Splicewire\Beam\Analytics\Console\MakeAnalyticsAreaCommand;
use Splicewire\Beam\Analytics\Contracts\MeasurementSettings;
use Splicewire\Beam\Analytics\Doctor\AnalyticsWiringAudit;
use Splicewire\Beam\Analytics\Events\AnalyticsEventRegistry;
use Splicewire\Beam\Analytics\Events\EventVocabulary;
use Splicewire\Beam\Analytics\Providers\AnalyticsProviderRegistry;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Analytics\Settings\ConfigMeasurementSettings;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Realm\RealmRegistry;

class BeamAnalyticsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-analytics')
            ->hasConfigFile('beam/analytics')
            ->hasCommands([BeamAnalyticsDoctorCommand::class, MakeAnalyticsAreaCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AnalyticsManager::class);
        $this->app->singleton(AnalyticsProviderRegistry::class);
        $this->app->singleton(AnalyticsEventRegistry::class);
        $this->app->singleton(AnalyticsAreaRegistry::class);
        $this->app->singleton(Traffic\TrafficSourceRegistry::class);

        // Whose figures and over what window, decided once per request: Tenant scope inside a tenant's boundary, as beam's
        // TenancyContext reports it, and Install scope otherwise. The measurement ids a scope's site is set up with are read
        // through MeasurementSettings, which a tenancy layer rebinds to a tenant's own settings.
        $this->app->bind(MeasurementSettings::class, ConfigMeasurementSettings::class);
        $this->app->scoped(AnalyticsScope::class, fn ($app): AnalyticsScope => $app->bound(TenancyContext::class)
            ? AnalyticsScope::fromTenancy($app->make(TenancyContext::class))
            : AnalyticsScope::install());
        $this->app->scoped(AnalyticsReading::class, fn ($app): AnalyticsReading => new AnalyticsReading($app->make(AnalyticsScope::class), AnalyticsPeriod::overview()));

        // `analytics.view` is a declared entitlement key, so `entitlement:analytics.view` is an ability beam defines. It is
        // declared here, in the register phase, because beam reads the key list when it boots. An undeclared key would deny
        // every area resource, and it would leave the seat's own entitlement check open.
        $keys = (array) $this->app->make('config')->get('beam.core.entitlements.keys', []);
        if (! in_array(AnalyticsSection::ENTITLEMENT, $keys, true)) {
            $keys[] = AnalyticsSection::ENTITLEMENT;
            $this->app->make('config')->set('beam.core.entitlements.keys', array_values($keys));
        }

        // ADR-0237 d4 — the id port's default: ids come from config, as before. Bound (not singleton) so
        // the tenancy layer can rebind it per request for a Site Host (EP-33) without this package knowing
        // about tenancy. Resolved lazily by the manager at render time, so a rebind takes effect.
        $this->app->bind(
            Contracts\AnalyticsIdResolver::class,
            Resolvers\ConfigIdResolver::class,
        );

        // Where an area's figure leads (the site editor, Site settings) is the host's to say, so the default names no
        // destination and draws no link. Bound (not singleton) so a host rebinds it, per scope if it needs to.
        $this->app->bind(
            Contracts\AnalyticsDestinations::class,
            Destinations\NoDestinations::class,
        );
    }

    public function packageBooted(): void
    {
        // ADR-0237 d8: a host's Event Vocabulary is refused at boot when it redeclares a package name or is not GA4-shaped.
        // Resolving the registries runs each package's afterResolving contribution, so a misdeclared one is refused here
        // too. This waits for `booted` because a provider booting after this one must still reach them.
        $this->app->booted(function ($app): void {
            $app->make(AnalyticsProviderRegistry::class);
            $app->make(EventVocabulary::class)->validate();
            $app->make(AnalyticsAreaRegistry::class)->definitions();
            $app->make(Traffic\TrafficSourceRegistry::class)->keys();
        });

        // The Analytics section (ticket 04): a seat in each of its realms, flagged `overview`, and one resource per area.
        // Each is guarded by beam's binding, because this package's own harness boots without beam's provider. The
        // resources are registered again on `booted()`, so route files see them and a later provider's area is drawn.
        if ($this->app->bound(NavSectionRegistry::class)) {
            $seats = $this->app->make(NavSectionRegistry::class);
            foreach (AnalyticsSection::REALMS as $realm) {
                $seats->register(AnalyticsSection::seat($realm, $this->routeBase($realm)), by: AnalyticsSection::REGISTRANT);
            }
        }
        $this->app->make(AnalyticsAreaResources::class)->register();
        $this->app->make(Traffic\TrafficPanelResources::class)->register();

        // The `period` facet's select (ticket 09) reads its choices from an Options Source, the way beam's own resource
        // registry declares its pickers. Guarded the same way: a host without data-filters draws no facet panel to fill.
        if ($this->app->bound(DataFilterManager::class)) {
            $this->app->make(DataFilterManager::class)->options(
                AnalyticsPeriod::OPTIONS_SOURCE,
                static fn (?string $search = null): array => AnalyticsPeriod::options($search),
            );
        }
        $this->app->booted(function ($app): void {
            $app->make(AnalyticsAreaResources::class)->register();
            $app->make(Traffic\TrafficPanelResources::class)->register();
        });

        // The injection seam: two directives, placement-agnostic across both fleet
        // root-Blade shapes (@inertiaHead/@inertia; <x-inertia::head>/<x-inertia::app/>).
        // @beamAnalyticsHead goes in <head>; @beamAnalyticsBody immediately after <body>.
        Blade::directive('beamAnalyticsHead', fn () => '<?php echo app(\\Splicewire\\Beam\\Analytics\\AnalyticsManager::class)->head(); ?>');
        Blade::directive('beamAnalyticsBody', fn () => '<?php echo app(\\Splicewire\\Beam\\Analytics\\AnalyticsManager::class)->body(); ?>');

        // Self-register into beam-core's install manifest so `splicewire:beam:install` publishes
        // this package's config with the rest of the stack (B2-2 enrollment; no migrations here).
        if ($this->app->bound('Splicewire\\Beam\\Install\\BeamInstallManifest')) {
            $this->app->make('Splicewire\\Beam\\Install\\BeamInstallManifest')->register(
                package: 'splicewire/laravel-beam-analytics',
                publishTags: ['beam-analytics-config'],
                migrates: false,
            );
        }

        // Register the wiring audit — ADVISORY — DOWN into beam-core's doctor aggregation manifest,
        // so one `splicewire:beam:doctor` run reports it with the rest of the family. Guarded by
        // string class-name, because this package's test harness boots it without beam's provider
        // and the manifest binding won't exist there. A real host always has beam (ADR-0242).
        if ($this->app->bound('Splicewire\\Beam\\Doctor\\BeamDoctorManifest')) {
            $this->app->make('Splicewire\\Beam\\Doctor\\BeamDoctorManifest')->register(
                package: 'splicewire/laravel-beam-analytics',
                audit: AnalyticsWiringAudit::class,
                gate: false,
            );
        }
    }

    /** `$realm`'s route base from beam's realm registry (`/operator`; `/` for the tenant realm), or `/{realm}` if unknown. */
    private function routeBase(string $realm): string
    {
        $realms = $this->app->bound(RealmRegistry::class) ? $this->app->make(RealmRegistry::class) : null;

        return $realms?->get($realm)?->routeBase ?? '/'.$realm;
    }
}
