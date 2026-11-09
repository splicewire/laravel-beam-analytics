<?php

namespace Splicewire\Beam\Analytics\Areas\Attributes;

use Attribute;

/**
 * What an {@see \Splicewire\Beam\Analytics\Areas\AnalyticsArea} is drawn under: its key (the resource is
 * `analytics-{key}`), label, icon and order on the overview.
 *
 * `ability` narrows the area's gate: a reader then needs `analytics.view` AND this ability. It can never widen it, because
 * the area's policy always asks for `analytics.view` first.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Area
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $icon,
        public readonly int $order = 100,
        public readonly ?string $ability = null,
    ) {}
}
