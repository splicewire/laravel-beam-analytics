<?php

namespace Splicewire\Beam\Analytics;

use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Analytics\Console\BeamAnalyticsDoctorCommand;

class BeamAnalyticsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-analytics')
            ->hasConfigFile('analytics')
            ->hasCommand(BeamAnalyticsDoctorCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AnalyticsManager::class);
    }

    public function packageBooted(): void
    {
        // The injection seam: two directives, placement-agnostic across both fleet
        // root-Blade shapes (@inertiaHead/@inertia; <x-inertia::head>/<x-inertia::app/>).
        // @beamAnalyticsHead goes in <head>; @beamAnalyticsBody immediately after <body>.
        Blade::directive('beamAnalyticsHead', fn () => '<?php echo app(\\Splicewire\\Beam\\Analytics\\AnalyticsManager::class)->head(); ?>');
        Blade::directive('beamAnalyticsBody', fn () => '<?php echo app(\\Splicewire\\Beam\\Analytics\\AnalyticsManager::class)->body(); ?>');
    }
}
