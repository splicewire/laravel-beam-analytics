<?php

namespace Splicewire\Beam\Analytics\Events;

/**
 * The **Event Vocabulary** (ADR-0237 decision 8): every Analytics Event a site may report, with its parameters.
 *
 * The package's half is GA4's recommended names for what packaged surfaces do; a host declares its own beside them in
 * `config('beam.analytics.events')` as `name => [parameters]`, and another package contributes through
 * {@see AnalyticsEventRegistry}, which holds and validates all three. A host may not redeclare a package name, and every
 * name must be one GA4 accepts. The Manifest Profile serves {@see all()} as `x-events` (laravel-beam-ux ADR-0234 decision
 * 14), a block's Click Event is checked against it at save, and packaged surfaces report through one client call. Names a
 * tenant adds are deferred.
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

    public function __construct(private AnalyticsEventRegistry $registry) {}

    /** @return list<array{name: string, source: 'package'|'host', parameters: list<string>}> */
    public function all(): array
    {
        $events = [];
        foreach ($this->registry->events() as $name => $event) {
            $events[] = ['name' => $name, 'source' => $event['source'], 'parameters' => $event['parameters']];
        }

        return $events;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->registry->events());
    }

    /** Refuse a misdeclared host vocabulary at boot (EP29-SLICE-PLAN contract item 3), not on its first use. */
    public function validate(): void
    {
        $this->registry->validate();
    }
}
