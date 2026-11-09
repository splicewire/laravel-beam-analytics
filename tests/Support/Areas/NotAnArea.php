<?php

namespace Splicewire\Beam\Analytics\Tests\Support\Areas;

use Splicewire\Beam\Analytics\Areas\Attributes\Area;

/** Declared, but not an area. */
#[Area(key: 'not-an-area', label: 'Not an area', icon: 'Box', order: 93)]
final class NotAnArea {}
