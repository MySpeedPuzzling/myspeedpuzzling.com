<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The participants spreadsheet page (docs/features/competitions-management/participants-spreadsheet.md §9, contract
 * §4.4): the event's organisers only, never indexed or stored, full screen, the state embedded for the first paint
 * and the frame the `participants-sheet` controller works in.
 */
final class ParticipantsSheetPageTest extends WebTestCase
{
    private const string RESULTS_CUP = '/en/participants-sheet/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNobodySignedInIsSentToSignIn(): void
    {
        $this->browser->request('GET', self::RESULTS_CUP);

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $this->browser->getResponse()->headers->get('Location'));
    }

    public function testSomebodyWhoDoesNotOrganiseTheEventIsRefused(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', self::RESULTS_CUP);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownEventIsNotFound(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', '/en/participants-sheet/018d0020-0000-0000-0000-000000009999');
        self::assertResponseStatusCodeSame(404);

        $this->browser->request('GET', '/en/participants-sheet/not-an-event');
        self::assertResponseStatusCodeSame(404);
    }

    public function testASeriesMaintainerOrganisesItsEditions(): void
    {
        self::getContainer()->get(Connection::class)->insert('competition_series_maintainer', [
            'competition_series_id' => CompetitionSeriesFixture::SERIES_OFFLINE,
            'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->browser->request('GET', '/en/participants-sheet/' . CompetitionSeriesFixture::EDITION_OFFLINE_1);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLocalizedPaths(): array
    {
        return [
            'cs' => ['/tabulka-ucastniku/'],
            'en' => ['/en/participants-sheet/'],
            'es' => ['/es/participants-sheet/'],
            'ja' => ['/ja/participants-sheet/'],
            'fr' => ['/fr/participants-sheet/'],
            'de' => ['/de/participants-sheet/'],
        ];
    }

    /**
     * Every link of the page exists in every locale - a missing localized route is a 500 there
     */
    #[DataProvider('provideLocalizedPaths')]
    public function testThePageOpensInEveryLocale(string $path): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', $path . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertResponseIsSuccessful();
    }

    public function testThePageIsPrivateFullScreenAndNeverIndexed(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::RESULTS_CUP);

        self::assertResponseIsSuccessful();
        $headers = $this->browser->getResponse()->headers;
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));
        self::assertSame('same-origin', $headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        // No site header, menu or footer - the sheet's own bar
        self::assertCount(0, $crawler->filter('.navbar'));
        self::assertCount(0, $crawler->filter('footer'));
        self::assertCount(1, $crawler->filter('.participants-sheet-bar'));
        self::assertSame('Results Cup', $crawler->filter('.participants-sheet-bar__event')->text());
        self::assertSame('/en/edit-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $crawler->filter('[data-participants-sheet-back]')->attr('href'));
        self::assertStringContainsString('This tool needs JavaScript', $crawler->filter('noscript')->html());
    }

    public function testTheFrameCarriesTheControllersTargetsAndValues(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::RESULTS_CUP);
        $sheet = $crawler->filter('[data-controller="participants-sheet"]');
        self::assertCount(1, $sheet);

        foreach (['tabs', 'status', 'undo', 'redo', 'main', 'help'] as $target) {
            self::assertCount(1, $sheet->filter('[data-participants-sheet-target="' . $target . '"]'), $target);
        }

        // Undo and redo wait for the controller
        self::assertNotNull($sheet->filter('[data-participants-sheet-target="undo"]')->attr('disabled'));
        self::assertNotNull($sheet->filter('[data-participants-sheet-target="redo"]')->attr('disabled'));

        $competition = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $urls = self::jsonValue($sheet, 'urls');
        self::assertSame([
            'state' => '/en/participants-sheet-api/' . $competition . '/state',
            'version' => '/en/participants-sheet-api/' . $competition . '/version',
            'changes' => '/en/participants-sheet-api/' . $competition . '/changes',
            'registration' => '/en/participants-sheet-api/' . $competition . '/registration',
            'record' => '/en/official-results/rounds/__ROUND__/changes',
            'tables' => '/en/official-results/rounds/__ROUND__/table-numbers',
            'playerSearch' => '/en/player-search-autocomplete/?format=co-puzzler',
            'import' => '/en/import-event-participants/' . $competition,
            'export' => '/en/export-event-participants/' . $competition,
            'rounds' => '/en/manage-event-rounds/' . $competition,
        ], $urls);

        self::assertNotSame('', (string) $sheet->attr('data-participants-sheet-csrf-token-value'));
        self::assertSame('en', $sheet->attr('data-participants-sheet-locale-value'));
        self::assertSame('people', $sheet->attr('data-participants-sheet-tab-value'));

        $countries = self::jsonValue($sheet, 'countries');
        self::assertSame('Czechia', $countries['cz']);
        self::assertSame('United States', $countries['us']);

        // The three streams' texts - each a JSON object (jsonValue() asserts it)
        foreach (['texts-core', 'texts-round', 'texts-people'] as $texts) {
            self::jsonValue($sheet, $texts);
        }

        // The state is embedded - nothing to fetch for the first paint
        $state = self::embeddedState($crawler);
        self::assertIsArray($state['competition']);
        self::assertSame($competition, $state['competition']['id']);
        self::assertCount(5, self::listOf($state['rounds']));
        self::assertCount(9, self::listOf($state['people']));
        self::assertSame(['serverNow', 'version', 'competition', 'rounds', 'people', 'places', 'teams', 'mercure'], array_keys($state));
    }

    public function testTheEmbeddedStateIsWhatTheStateEndpointAnswers(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $embedded = self::embeddedState($this->browser->request('GET', self::RESULTS_CUP));

        $this->browser->request('GET', '/en/participants-sheet-api/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/state');
        $answered = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answered);

        // Tokens and the clock differ by request - everything else is the same
        self::assertSame(self::withoutVolatile($answered), self::withoutVolatile($embedded));
    }

    public function testATabOfAnotherEventIsNeverOpened(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::RESULTS_CUP . '?tab=' . OfficialResultsFixture::ROUND_PAIRS);
        self::assertSame(OfficialResultsFixture::ROUND_PAIRS, $crawler->filter('[data-controller="participants-sheet"]')->attr('data-participants-sheet-tab-value'));

        // A round of another event, junk, an upper-case id of its own round
        foreach ([CompetitionSeriesFixture::ROUND_OFFLINE_TEAM => 'people', 'junk' => 'people', strtoupper(OfficialResultsFixture::ROUND_GROUP_A) => OfficialResultsFixture::ROUND_GROUP_A] as $tab => $expected) {
            $crawler = $this->browser->request('GET', self::RESULTS_CUP . '?tab=' . $tab);
            self::assertSame($expected, $crawler->filter('[data-controller="participants-sheet"]')->attr('data-participants-sheet-tab-value'), (string) $tab);
        }
    }

    public function testNothingTypedIntoANameCanEndTheStateScript(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET name = :name WHERE id = :id',
            ['name' => '</script><script>alert("x")</script>', 'id' => OfficialResultsFixture::PARTICIPANT_IVAN],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::RESULTS_CUP);

        self::assertStringNotContainsString('<script>alert', (string) $this->browser->getResponse()->getContent());
        $names = array_column(array_filter(self::listOf(self::embeddedState($crawler)['people']), is_array(...)), 'name');
        self::assertContains('</script><script>alert("x")</script>', $names);
    }

    public function testTheToolsMenuOfAnInPersonEventWithoutManagedRegistration(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::RESULTS_CUP);
        $competition = OfficialResultsFixture::COMPETITION_RESULTS_CUP;

        self::assertSame(['import', 'export', 'template', 'name-tags', 'referees', 'results', 'rounds', 'event', 'edit'], $this->tools($crawler));
        self::assertSame('/en/name-tags/' . $competition, $crawler->filter('[data-sheet-tool="name-tags"]')->attr('href'));
        self::assertSame('/en/events/results-cup', $crawler->filter('[data-sheet-tool="event"]')->attr('href'));
        self::assertSame('/en/manage-event-results/' . $competition, $crawler->filter('[data-sheet-tool="results"]')->attr('href'));

        // The import dialog: the upload form and the help moved from the participants page
        $form = $crawler->filter('#participants-sheet-import form[action="/en/import-event-participants/' . $competition . '"]');
        self::assertCount(1, $form);
        self::assertSame('multipart/form-data', $form->attr('enctype'));
        self::assertCount(1, $form->filter('input[type="file"]'));
        self::assertContains('round_names', $crawler->filter('#participants-sheet-import details table code')->each(static fn (Crawler $code): string => $code->text()));
    }

    public function testManagedRegistrationAddsItsSettingsAndTheCheckInForAnInPersonEventOnly(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ([CompetitionFixture::COMPETITION_UNAPPROVED, CompetitionFixture::COMPETITION_RECURRING_ONLINE] as $competitionId) {
            $bus->dispatch(new ChangeCompetitionRegistrationSettings($competitionId, true, null, null, null, 'Europe/Prague', null, null));
        }
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/participants-sheet/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertSame(['import', 'export', 'template', 'registration', 'check-in', 'name-tags', 'referees', 'results', 'rounds', 'event', 'edit'], $this->tools($crawler));
        self::assertSame('/en/event-check-in/' . CompetitionFixture::COMPETITION_UNAPPROVED, $crawler->filter('[data-sheet-tool="check-in"]')->attr('href'));

        // Nobody walks in to an online event: no check-in, no name tags
        $crawler = $this->browser->request('GET', '/en/participants-sheet/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        self::assertSame(['import', 'export', 'template', 'registration', 'referees', 'results', 'rounds', 'event', 'edit'], $this->tools($crawler));
    }

    public function testTheSetupChecklistShowsOnlyWhileTheEventHasNoRoundsOrNobodyOnItsList(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::RESULTS_CUP);
        self::assertSelectorNotExists('[data-participants-sheet-checklist]');

        // Czech Nationals: one round, nobody on the list yet
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/en/participants-sheet/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);
        $checklist = $crawler->filter('[data-participants-sheet-checklist]');
        self::assertCount(1, $checklist);
        self::assertSame('checklist', $checklist->attr('data-participants-sheet-target'));
        self::assertStringContainsString('1 round(s) configured', $checklist->text());
        self::assertCount(1, $checklist->filter('.bi-check-circle-fill'), 'Rounds are done, the list is not');
        self::assertCount(1, $checklist->filter('a[href^="/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '"]'));
        self::assertCount(1, $checklist->filter('[data-bs-target="#participants-sheet-import"]'));
    }

    /**
     * @return list<string>
     */
    private function tools(Crawler $crawler): array
    {
        return $crawler->filter('[data-sheet-tool]')->each(static fn (Crawler $tool): string => (string) $tool->attr('data-sheet-tool'));
    }

    /**
     * @return array<mixed>
     */
    private static function jsonValue(Crawler $sheet, string $name): array
    {
        $value = json_decode((string) $sheet->attr('data-participants-sheet-' . $name . '-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function embeddedState(Crawler $crawler): array
    {
        $script = $crawler->filter('script#participants-sheet-state[type="application/json"]');
        self::assertCount(1, $script);

        $state = json_decode($script->text(normalizeWhitespace: false), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        /** @var array<string, mixed> $state */
        return $state;
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        return $value;
    }

    /**
     * @param array<mixed> $state
     * @return array<mixed>
     */
    private static function withoutVolatile(array $state): array
    {
        unset($state['serverNow']);

        if (is_array($state['mercure'] ?? null)) {
            $mercure = $state['mercure'];
            unset($mercure['token'], $mercure['expiresAt']);
            $state['mercure'] = $mercure;
        }

        return $state;
    }
}
