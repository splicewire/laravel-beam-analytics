<?php

namespace Splicewire\Beam\Analytics\Tests\Support;

use Illuminate\Support\ServiceProvider;

/**
 * A contributing package with no composer edge to analytics: it names the registries only as strings, so nothing here
 * loads unless analytics is installed and resolves them. One contribution comes from register() and one from boot(),
 * which runs after analytics' own boot when this provider is listed second.
 */
class AcmeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving('Splicewire\Beam\Analytics\Providers\AnalyticsProviderRegistry', function ($registry): void {
            $registry->register('acme', ['adapter' => AcmeAdapter::class, 'id' => 'ACME-1'], 'acme/analytics');
        });
    }

    public function boot(): void
    {
        $this->app->afterResolving('Splicewire\Beam\Analytics\Events\AnalyticsEventRegistry', function ($registry): void {
            $registry->register('acme_clicked', ['target'], 'acme/analytics');
        });
    }
}
