<?php

namespace Splicewire\Beam\Analytics;

use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Analytics\Console\BeamAnalyticsDoctorCommand;
use Splicewire\Beam\Analytics\Doctor\AnalyticsWiringAudit;

class BeamAnalyticsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-analytics')
            ->hasConfigFile('beam/analytics')
            ->hasCommand(BeamAnalyticsDoctorCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AnalyticsManager::class);

        // ADR-0237 d4 — the id port's default: ids come from config, as before. Bound (not singleton) so
        // the tenancy layer can rebind it per request for a Site Host (EP-33) without this package knowing
        // about tenancy. Resolved lazily by the manager at render time, so a rebind takes effect.
        $this->app->bind(
            \Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver::class,
            \Splicewire\Beam\Analytics\Resolvers\ConfigIdResolver::class,
        );
    }

    public function packageBooted(): void
    {
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
        // string class-name so this package keeps booting in a host without beam-core installed
        // (this package does not require beam; the manifest binding simply won't exist there).
        if ($this->app->bound('Splicewire\\Beam\\Doctor\\BeamDoctorManifest')) {
            $this->app->make('Splicewire\\Beam\\Doctor\\BeamDoctorManifest')->register(
                package: 'splicewire/laravel-beam-analytics',
                audit: AnalyticsWiringAudit::class,
                gate: false,
            );
        }
    }
}
