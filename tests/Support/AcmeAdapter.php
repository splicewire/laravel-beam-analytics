<?php

namespace Splicewire\Beam\Analytics\Tests\Support;

use Splicewire\Beam\Analytics\Contracts\AnalyticsProvider;

/** A contributing package's tag adapter. */
class AcmeAdapter implements AnalyticsProvider
{
    public function __construct(private string $id) {}

    public function head(): string
    {
        return "<!-- acme head {$this->id} -->";
    }

    public function body(): string
    {
        return "<!-- acme body {$this->id} -->";
    }
}
