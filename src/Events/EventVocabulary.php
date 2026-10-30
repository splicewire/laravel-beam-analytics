<?php

namespace Splicewire\Beam\Analytics\Events;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The **Event Vocabulary** (ADR-0237 decision 8): every Analytics Event a site may report, with its parameters.
 *
 * The package's half is GA4's recommended names for what packaged surfaces do; a host declares its own beside them in
 * `config('beam.analytics.events')` as `name => [parameters]`. A host may not redeclare a package name, and every name
 * must be one GA4 accepts. The Manifest Profile serves {@see all()} as `x-events` (laravel-beam-ux ADR-0234 decision 14),
 * a block's Click Event is checked against it at save, and packaged surfaces report through one client call.
 * Names a tenant adds are deferred.
 */
class EventVocabulary
{
    /** GA4's recommended events for packaged surfaces, in the order the profile serves them. */
    public const PACKAGE = [
        'sign_up' => ['method'],
        'login' => ['method'],
        'generate_lead' => ['currency', 'value'],
        'search' => ['search_term'],
        'begin_checkout' => ['currency', 'value', 'items'],
        'purchase' => ['transaction_id', 'currency', 'value', 'items'],
        'select_content' => ['content_type', 'content_id'],
    ];

    /** GA4's event-name rule: a letter, then letters, digits or underscores, at most 40 characters. */
    private const NAME = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

    public function __construct(private Repository $config) {}

    /** @return list<array{name: string, source: 'package'|'host', parameters: list<string>}> */
    public function all(): array
    {
        $events = [];
        foreach (self::PACKAGE as $name => $parameters) {
            $events[] = ['name' => $name, 'source' => 'package', 'parameters' => $parameters];
        }
        foreach ($this->hostEvents() as $name => $parameters) {
            $events[] = ['name' => $name, 'source' => 'host', 'parameters' => $parameters];
        }

        return $events;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, self::PACKAGE) || array_key_exists($name, $this->hostEvents());
    }

    /** @return array<string, list<string>> */
    private function hostEvents(): array
    {
        $declared = $this->config->get('beam.analytics.events', []);
        $events = [];
        foreach ((array) $declared as $name => $parameters) {
            $name = (string) $name;
            if (! preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException("Analytics Event [{$name}] is not a GA4 event name: a letter, then letters, digits or underscores, at most 40 characters.");
            }
            if (array_key_exists($name, self::PACKAGE)) {
                throw new InvalidArgumentException("Analytics Event [{$name}] is the package's; a host declares its own names beside it, not over it.");
            }
            if (! is_array($parameters) || ! array_is_list($parameters) || array_filter($parameters, fn ($p) => ! is_string($p)) !== []) {
                throw new InvalidArgumentException("Analytics Event [{$name}] declares its parameters as a list of names.");
            }
            $events[$name] = $parameters;
        }

        return $events;
    }
}
