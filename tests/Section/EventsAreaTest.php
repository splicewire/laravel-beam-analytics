<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Analytics\Contracts\AnalyticsDestinations;
use Splicewire\Beam\Analytics\Destinations\AnalyticsDestination;
use Splicewire\Beam\Analytics\Events\AnalyticsEventRegistry;
use Splicewire\Beam\Analytics\Events\EventVocabulary;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;

/**
 * The Events area (ticket 07): the Event Vocabulary as rows, the package's names, a package's contribution and the host's
 * own, each labelled by who declared it and never as activity, on its page and its card, linking to the site editor
 * through the `site-editor` destination only.
 */
class EventsAreaTest extends SectionTestCase
{
    private const EDITOR_ROUTE = 'aa07.site-editor';

    private const EDITOR_ABILITY = 'site.editor.open';

    protected function setUp(): void
    {
        parent::setUp();

        config(['beam.analytics.events' => ['song_shared' => ['song_id'], 'newsletter_opened' => []]]);
        app(AnalyticsEventRegistry::class)->register('quiz_passed', ['quiz_id', 'score'], 'acme/quizzes');
    }

    /** @return list<array{id: string, label: string, value: mixed, href: ?string}> the page's rows, in order */
    private function page(SectionUser $reader): array
    {
        return array_map(
            fn (array $row): array => ['id' => $row['id'], 'label' => $row['label'], 'value' => $row['value'], 'href' => $row['href'] ?? null],
            $this->actingAs($reader)->getJson('/frame/resources/analytics-events')->assertOk()->json('data'),
        );
    }

    /** @return list<array{key: string, label: string, value: mixed}> */
    private function card(TestResponse $response): array
    {
        return array_map(
            fn (array $figure): array => ['key' => $figure['key'], 'label' => $figure['label'], 'value' => $figure['value']],
            $response->json('figures') ?? $response->json('data.figures') ?? [],
        );
    }

    private function analyst(): SectionUser
    {
        $analyst = $this->reader('analyst@beam.test', ['os.operate', 'analytics.view']);
        $this->defineGrants(['os.operate', 'analytics.view'], [self::EDITOR_ABILITY]);

        return $analyst;
    }

    #[Test]
    public function it_lists_every_declared_event_with_its_parameters_and_who_declared_it_on_its_page_and_its_card(): void
    {
        $analyst = $this->analyst();

        $expected = [];
        foreach (EventVocabulary::PACKAGE as $name => $parameters) {
            $expected[] = ['id' => $name, 'label' => $name, 'value' => 'Declared by a package · '.implode(', ', $parameters), 'href' => null];
        }
        $expected[] = ['id' => 'quiz_passed', 'label' => 'quiz_passed', 'value' => 'Declared by a package · quiz_id, score', 'href' => null];
        $expected[] = ['id' => 'song_shared', 'label' => 'song_shared', 'value' => 'Declared by the host · song_id', 'href' => null];
        $expected[] = ['id' => 'newsletter_opened', 'label' => 'newsletter_opened', 'value' => 'Declared by the host · no parameters', 'href' => null];

        $rows = $this->page($analyst);
        $this->assertSame($expected, $rows);
        $this->assertSame(
            array_map(fn (array $event): string => $event['name'], app(EventVocabulary::class)->all()),
            array_column($rows, 'id'),
            'the rows are the vocabulary x-events serves, in its order',
        );

        $card = $this->card($this->actingAs($analyst)->getJson('/frame/resources/analytics-events/summary')->assertOk());
        $this->assertSame(
            array_map(fn (array $row): array => ['key' => $row['id'], 'label' => $row['label'], 'value' => $row['value']], $rows),
            $card,
            'the card and the page read one figures path',
        );
    }

    #[Test]
    public function its_wording_says_declared_and_can_never_be_read_as_measured_activity(): void
    {
        $analyst = $this->analyst();
        $card = $this->card($this->actingAs($analyst)->getJson('/frame/resources/analytics-events/summary')->assertOk());

        foreach ([...$this->page($analyst), ...$card] as $figure) {
            $this->assertIsString($figure['value'], $figure['label']);
            $this->assertStringStartsWith('Declared by ', $figure['value'], $figure['label']);
            $this->assertDoesNotMatchRegularExpression('/\d|fired|sent|tracked|recorded|received|triggered|times|last /i', $figure['value'], $figure['label']);
        }
    }

    #[Test]
    public function its_rows_link_to_the_site_editor_only_through_the_destination_and_only_for_a_viewer_who_may_open_it(): void
    {
        $analyst = $this->analyst();
        $editor = $this->reader('editor@beam.test', ['os.operate', 'analytics.view', self::EDITOR_ABILITY]);
        $editorDestination = new AnalyticsDestination(routeName: self::EDITOR_ROUTE, ability: self::EDITOR_ABILITY);
        $hrefs = fn (SectionUser $reader): array => array_values(array_unique(array_column($this->page($reader), 'href')));

        // No binding: the default names no destination.
        $this->assertSame([null], $hrefs($editor), 'no binding');

        // A binding whose route does not exist.
        $this->bindDestinations([AnalyticsDestinations::SITE_EDITOR => $editorDestination]);
        $this->assertSame([null], $hrefs($editor), 'no route named '.self::EDITOR_ROUTE);

        Route::get('/aa07/site-editor', fn () => 'editor')->name(self::EDITOR_ROUTE);
        Route::getRoutes()->refreshNameLookups();

        // The route exists: a viewer without the ability gets no link, a viewer with it gets the link on every row.
        $this->assertSame([null], $hrefs($analyst), 'a viewer without '.self::EDITOR_ABILITY);
        $this->assertSame(['/aa07/site-editor'], $hrefs($editor), 'a viewer with '.self::EDITOR_ABILITY);

        // Events asks for site-editor, not site-settings: a binding that answers only site-settings draws nothing here.
        $this->bindDestinations([AnalyticsDestinations::SITE_SETTINGS => $editorDestination]);
        $this->assertSame([null], $hrefs($editor), 'only site-settings is bound');
    }

    #[Test]
    public function without_analytics_view_its_page_and_card_are_refused_even_for_a_viewer_who_may_open_the_editor(): void
    {
        $outsider = $this->reader('outsider@beam.test', ['os.operate', self::EDITOR_ABILITY]);
        $this->defineGrants(['os.operate', 'analytics.view'], [self::EDITOR_ABILITY]);
        Route::get('/aa07/site-editor', fn () => 'editor')->name(self::EDITOR_ROUTE);
        Route::getRoutes()->refreshNameLookups();
        $this->bindDestinations([AnalyticsDestinations::SITE_EDITOR => new AnalyticsDestination(routeName: self::EDITOR_ROUTE, ability: self::EDITOR_ABILITY)]);

        $this->actingAs($outsider)->getJson('/frame/resources/analytics-events')->assertForbidden();
        $this->actingAs($outsider)->getJson('/frame/resources/analytics-events/summary')->assertForbidden();
    }

    private function bindDestinations(array $destinations): void
    {
        $this->app->bind(AnalyticsDestinations::class, fn () => new class($destinations) implements AnalyticsDestinations
        {
            public function __construct(private readonly array $destinations) {}

            public function destination(string $name, AnalyticsScope $scope): ?AnalyticsDestination
            {
                return $this->destinations[$name] ?? null;
            }
        });
    }
}
