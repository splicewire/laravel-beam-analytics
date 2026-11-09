<?php

namespace Splicewire\Beam\Analytics\Areas;

use InvalidArgumentException;
use ReflectionClass;
use Splicewire\Beam\Analytics\AnalyticsSection;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;

/**
 * An area class, checked: it is an {@see AnalyticsArea}, it carries one {@see Area} attribute, and its key is kebab-case.
 * Anything else refuses here, where the area is registered, so a misdeclared area fails at boot and never as a blank card.
 */
final class AreaDefinition
{
    public const RESOURCE_PREFIX = 'analytics-';

    public const ABILITY_PREFIX = 'analytics-area.view.';

    private const KEY = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    /** @param  class-string<AnalyticsArea>  $class */
    private function __construct(
        public readonly string $class,
        public readonly Area $area,
    ) {}

    public static function of(mixed $class): self
    {
        if (! is_string($class) || ! class_exists($class)) {
            throw new InvalidArgumentException(sprintf('An Analytics Area is the class-string of an %s; got %s.', AnalyticsArea::class, is_string($class) ? "[{$class}]" : get_debug_type($class)));
        }

        if (! is_subclass_of($class, AnalyticsArea::class)) {
            throw new InvalidArgumentException("[{$class}] is not an ".AnalyticsArea::class.', so it cannot be an Analytics Area.');
        }

        $attributes = (new ReflectionClass($class))->getAttributes(Area::class);

        if ($attributes === []) {
            throw new InvalidArgumentException("[{$class}] declares no #[Area]: an Analytics Area names the key, label and icon it is drawn under.");
        }

        $area = $attributes[0]->newInstance();

        if (! self::isValidKey($area->key)) {
            throw new InvalidArgumentException("[{$class}] declares the area key [{$area->key}]; a key is kebab-case, because the area is drawn as the resource [".self::RESOURCE_PREFIX.'{key}].');
        }

        if (trim($area->label) === '' || trim($area->icon) === '') {
            throw new InvalidArgumentException("[{$class}] declares an empty label or icon for the area [{$area->key}].");
        }

        return new self($class, $area);
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY, $key) === 1;
    }

    public function key(): string
    {
        return $this->area->key;
    }

    public function resourceKey(): string
    {
        return self::RESOURCE_PREFIX.$this->area->key;
    }

    /**
     * The area resource's `policy:`. It asks for `analytics.view` first and then the area's own ability, if it declares
     * one, so an area can narrow the section's gate and never widen it.
     */
    public function ability(): string
    {
        return self::ABILITY_PREFIX.$this->area->key;
    }

    /** The abilities {@see ability()} requires, in the order it asks them. */
    public function requires(): array
    {
        return array_values(array_filter(['entitlement:'.AnalyticsSection::ENTITLEMENT, $this->area->ability]));
    }
}
