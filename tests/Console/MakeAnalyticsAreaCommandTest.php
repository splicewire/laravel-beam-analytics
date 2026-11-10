<?php

namespace Splicewire\Beam\Analytics\Tests\Console;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\AreaDefinition;
use Splicewire\Beam\Analytics\Tests\TestCase;

/**
 * `splicewire:beam:analytics:make-area`, in the shape of exec's `make-card`: it mints an area the registry accepts, refuses a
 * key the registry would refuse before writing anything, and reads back what it wrote, exiting 1 on a finding.
 *
 * Each test mints a class name of its own, because a class the generator loads stays loaded for the rest of the process.
 */
class MakeAnalyticsAreaCommandTest extends TestCase
{
    private string $areas;

    private string $stub;

    private string $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->areas = app_path('Analytics');
        $this->stub = base_path('stubs/analytics-area.stub');
        $this->package = sys_get_temp_dir().'/aa07-make-area-'.getmypid();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    private function clean(): void
    {
        File::deleteDirectory($this->areas);
        File::deleteDirectory($this->package);
        File::delete($this->stub);
    }

    #[Test]
    public function it_mints_an_area_the_registry_accepts_with_the_key_from_the_class_name_and_prints_its_registration(): void
    {
        $this->artisan('splicewire:beam:analytics:make-area', ['name' => 'TrafficMixArea', '--icon' => 'PieChart', '--order' => 30])
            ->expectsOutputToContain('analytics-traffic-mix')
            ->expectsOutputToContain("->add(\\App\\Analytics\\Areas\\TrafficMixArea::class, 'vendor/package')")
            ->assertSuccessful();

        $source = File::get($this->areas.'/Areas/TrafficMixArea.php');

        $this->assertStringContainsString('namespace App\Analytics\Areas;', $source);
        $this->assertStringContainsString("#[Area(key: 'traffic-mix', label: 'Traffic Mix', icon: 'PieChart', order: 30, ability: null)]", $source);

        $definition = AreaDefinition::of('App\Analytics\Areas\TrafficMixArea');
        $areas = app(AnalyticsAreaRegistry::class)->add($definition->class, 'acme/traffic');

        $this->assertSame('analytics-traffic-mix', $definition->resourceKey());
        $this->assertTrue(is_subclass_of($definition->class, AnalyticsArea::class));
        $this->assertSame($definition->class, $areas->resolve('traffic-mix'));
        $this->assertSame([], app($definition->class)->measure(app(AnalyticsReading::class)), 'a fresh area measures nothing rather than inventing a figure');
    }

    #[Test]
    public function it_takes_an_explicit_key_label_and_ability_and_defaults_the_icon_and_order(): void
    {
        $this->artisan('splicewire:beam:analytics:make-area', ['name' => 'Reach', '--key' => 'reach', '--label' => "Founders' reach", '--ability' => 'reports.read'])
            ->assertSuccessful();

        $this->assertStringContainsString(
            "#[Area(key: 'reach', label: 'Founders\\' reach', icon: 'Activity', order: 100, ability: 'reports.read')]",
            File::get($this->areas.'/Areas/Reach.php'),
        );
        $this->assertSame(['entitlement:analytics.view', 'reports.read'], AreaDefinition::of('App\Analytics\Areas\Reach')->requires());
    }

    #[Test]
    public function it_writes_into_a_package_given_a_namespace_and_a_path_and_refuses_one_without_the_other(): void
    {
        $this->artisan('splicewire:beam:analytics:make-area', ['name' => 'SalesArea', '--namespace' => 'Acme\Reports\Areas', '--path' => $this->package.'/src/Areas'])
            ->assertSuccessful();

        $this->assertStringContainsString('namespace Acme\Reports\Areas;', File::get($this->package.'/src/Areas/SalesArea.php'));
        $this->assertSame('sales', AreaDefinition::of('Acme\Reports\Areas\SalesArea')->key());

        $this->artisan('splicewire:beam:analytics:make-area', ['name' => 'HalfArea', '--namespace' => 'Acme\Reports\Areas'])
            ->expectsOutputToContain('Pass --namespace and --path together')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->package.'/src/Areas/HalfArea.php');
    }

    #[Test]
    public function it_refuses_a_key_the_registry_would_refuse_and_writes_nothing(): void
    {
        $this->artisan('splicewire:beam:analytics:make-area', ['name' => 'MeasurementArea'])
            ->expectsOutputToContain('already holds the key `measurement`')
            ->assertFailed();

        $this->artisan('splicewire:beam:analytics:make-area', ['name' => 'Odd', '--key' => 'Not_Kebab'])
            ->expectsOutputToContain('`Not_Kebab` is not kebab-case')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->areas);
    }

    #[Test]
    public function it_exits_1_naming_the_finding_when_the_class_it_wrote_is_not_a_conforming_area(): void
    {
        // A published stub that drops the #[Area] attribute: the class is written, read back, and refused by the registry's check.
        File::ensureDirectoryExists(dirname($this->stub));
        File::put($this->stub, <<<'STUB'
            <?php

            namespace {{ namespace }};

            use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
            use Splicewire\Beam\Analytics\Areas\AnalyticsReading;

            final class {{ class }} implements AnalyticsArea
            {
                public function measure(AnalyticsReading $reading): array
                {
                    return [];
                }
            }
            STUB);

        $this->artisan('splicewire:beam:analytics:make-area', ['name' => 'UnmarkedArea'])
            ->expectsOutputToContain('is not a conforming Analytics Area')
            ->expectsOutputToContain('declares no #[Area]')
            ->assertExitCode(1);

        $this->assertFileExists($this->areas.'/Areas/UnmarkedArea.php', 'the non-conforming class is left for its author to fix');

        // A published stub that pins its own key: the class conforms on its own, but not to the key that was asked for.
        File::put($this->stub, str_replace(
            'final class',
            "#[\\Splicewire\\Beam\\Analytics\\Areas\\Attributes\\Area(key: 'fixed', label: 'Fixed', icon: 'Lock')]\nfinal class",
            File::get($this->stub),
        ));

        $this->artisan('splicewire:beam:analytics:make-area', ['name' => 'PinnedArea'])
            ->expectsOutputToContain('declares the key [fixed], not the [pinned] asked for')
            ->assertExitCode(1);
    }
}
