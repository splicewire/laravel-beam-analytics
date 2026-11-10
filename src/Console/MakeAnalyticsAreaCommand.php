<?php

namespace Splicewire\Beam\Analytics\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AreaDefinition;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * `splicewire:beam:analytics:make-area`: mints an Analytics Area, a class implementing
 * {@see \Splicewire\Beam\Analytics\Areas\AnalyticsArea} and declared with
 * {@see \Splicewire\Beam\Analytics\Areas\Attributes\Area}, and prints the line that registers it. It follows exec's
 * `make-card`, and adds the check that card generator does not make: it reads back the class it wrote.
 *
 * Before writing, the key is checked by the rule the registry applies, and refused when an area already holds it, so the
 * generator cannot mint an area the registry would refuse. After writing, the class is loaded and checked by
 * {@see AreaDefinition::of()}, the same check that refuses a misdeclared area at boot. A finding there (a published stub
 * that drops the attribute, a class that is not an AnalyticsArea, a key or label the check refuses) prints the finding,
 * leaves the file for the author to fix, and exits 1.
 *
 * A host publishes its own stub at `stubs/analytics-area.stub`, as with Laravel's generators. A package author passes
 * `--namespace` and `--path` to write into the package rather than the host's `app/`.
 */
class MakeAnalyticsAreaCommand extends GeneratorCommand
{
    protected $name = 'splicewire:beam:analytics:make-area';

    protected $description = 'Scaffold an Analytics Area (an AnalyticsArea declared with #[Area]) and check the class it wrote.';

    protected $type = 'Analytics area';

    /** The icon an area gets when none is given: one the console's icon map draws. */
    public const DEFAULT_ICON = 'Activity';

    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/analytics-area.stub');

        return is_file($published) ? $published : dirname(__DIR__, 2).'/stubs/analytics-area.stub';
    }

    protected function rootNamespace(): string
    {
        $namespace = $this->option('namespace');

        return is_string($namespace) && $namespace !== '' ? trim($namespace, '\\').'\\' : parent::rootNamespace();
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        // qualifyClass() hands this the root namespace already trimmed (`App`), as exec's make-card relies on.
        return $this->targetsPackage() ? rtrim($rootNamespace, '\\') : $rootNamespace.'\Analytics\Areas';
    }

    protected function getPath($name): string
    {
        if (! $this->targetsPackage()) {
            return parent::getPath($name);
        }

        $path = (string) $this->option('path');
        $base = str_starts_with($path, '/') ? $path : $this->laravel->basePath($path);
        $relative = Str::after($name, $this->rootNamespace());

        return rtrim($base, '/').'/'.str_replace('\\', '/', $relative).'.php';
    }

    public function handle(): int
    {
        if ($this->targetsPackage() === null) {
            $this->components->error('Pass --namespace and --path together, or neither.');

            return self::FAILURE;
        }

        $key = $this->areaKey();

        if (! AreaDefinition::isValidKey($key)) {
            $this->components->error("The area key `{$key}` is not kebab-case. Pass --key=.");

            return self::FAILURE;
        }

        if ($this->laravel->make(AnalyticsAreaRegistry::class)->tryResolve($key) !== null) {
            $this->components->error("An Analytics Area already holds the key `{$key}`, and the registry refuses a second. Pass --key=.");

            return self::FAILURE;
        }

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $class = $this->qualifyClass($this->getNameInput());
        $path = $this->getPath($class);

        // The finding and the registration line are plain lines, so a narrow terminal never wraps a path or a class name.
        $finding = $this->checkWritten($class, $path);
        if ($finding !== null) {
            $this->components->error('The class it wrote is not a conforming Analytics Area.');
            $this->line("    {$path}");
            $this->line("    {$finding}");
            $this->line('    It is left in place for you to fix; nothing registers it.');

            return self::FAILURE;
        }

        $this->components->info('It conforms. Register it from your provider:');
        $this->line("    \$this->app->afterResolving('".AnalyticsAreaRegistry::class."', fn (\$areas) => \$areas->add(\\{$class}::class, 'vendor/package'));");
        $this->line('    It is drawn as the resource '.AreaDefinition::RESOURCE_PREFIX.$key.'.');

        return self::SUCCESS;
    }

    /** Load the class just written and check it as the registry will; the finding, or null when it conforms. */
    private function checkWritten(string $class, string $path): ?string
    {
        try {
            if (! class_exists($class, false)) {
                require_once $path;
            }

            $definition = AreaDefinition::of($class);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        if ($definition->key() !== $this->areaKey()) {
            return "it declares the key [{$definition->key()}], not the [{$this->areaKey()}] asked for.";
        }

        return null;
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $key = $this->areaKey();
        $icon = $this->option('icon');
        $order = $this->option('order');
        $ability = $this->option('ability');

        return str_replace(
            ['{{ key }}', '{{ label }}', '{{ icon }}', '{{ order }}', '{{ ability }}'],
            [
                $key,
                $this->quoted((string) ($this->option('label') ?: Str::headline($key))),
                $this->quoted(is_string($icon) && $icon !== '' ? $icon : self::DEFAULT_ICON),
                is_numeric($order) ? (string) (int) $order : '100',
                is_string($ability) && $ability !== '' ? "'".$this->quoted($ability)."'" : 'null',
            ],
            $stub,
        );
    }

    private function quoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /** The key: --key, or the class name in kebab-case without an `Area` suffix (`TrafficMixArea` -> `traffic-mix`). */
    private function areaKey(): string
    {
        $key = $this->option('key');

        if (is_string($key) && $key !== '') {
            return $key;
        }

        $base = class_basename($this->getNameInput());

        return Str::kebab(Str::beforeLast($base, 'Area') ?: $base);
    }

    /** True when writing into a package (--namespace and --path), false for the host's app/, null when only one is given. */
    private function targetsPackage(): ?bool
    {
        $namespace = (string) $this->option('namespace');
        $path = (string) $this->option('path');

        if (($namespace === '') !== ($path === '')) {
            return null;
        }

        return $namespace !== '';
    }

    protected function getOptions(): array
    {
        return [
            ['key', null, InputOption::VALUE_REQUIRED, 'The area key (kebab-case); its resource is analytics-{key}'],
            ['label', null, InputOption::VALUE_REQUIRED, 'The area label'],
            ['icon', null, InputOption::VALUE_REQUIRED, 'An icon name the console draws (default '.self::DEFAULT_ICON.')'],
            ['order', null, InputOption::VALUE_REQUIRED, 'The area\'s place on the overview, ascending (default 100)'],
            ['ability', null, InputOption::VALUE_REQUIRED, 'An ability that narrows the area\'s gate beyond analytics.view'],
            ['namespace', null, InputOption::VALUE_REQUIRED, 'Write into a package: the namespace of the class (with --path)'],
            ['path', null, InputOption::VALUE_REQUIRED, 'Write into a package: the directory that namespace maps to (with --namespace)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the area if it already exists'],
        ];
    }
}
