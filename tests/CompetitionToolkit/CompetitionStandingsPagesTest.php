<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The organiser's standings pages (competition toolkit A6): behind COMPETITION_TOOLKIT_MANAGE (= COMPETITION_EDIT: the
 * event's organisers and admins), the hub card, the live preview labelled provisional, the forms, delete,
 * "one per category" and the organiser JSON (COMPETITION_RESULTS_ENTRY).
 */
final class CompetitionStandingsPagesTest extends WebTestCase
{
    use WorldSeriesSetup;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testThePageIsBehindTheToolkitVoter(): void
    {
        $url = '/en/manage-event-standings/' . self::WS_COMPETITION;

        $this->browser->request('GET', $url);
        self::assertResponseRedirects();

        // The organiser gets the page; another player never
        TestingLogin::asPlayer($this->browser, self::WS_ORGANISER);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', $url . '/new');
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standings-empty]');
        self::assertSelectorExists('[data-standings-per-category]');
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheHubCardListsTheStandings(): void
    {
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/manage-event-toolkit/' . self::WS_COMPETITION);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-toolkit-card="standings"] [data-toolkit-placeholder]');
        self::assertSelectorTextContains('[data-toolkit-standing="' . $standingId . '"]', 'World Series - Solo');
        self::assertStringContainsString('6 rounds', $crawler->filter('[data-toolkit-standing="' . $standingId . '"]')->text());
        self::assertSelectorExists('[data-toolkit-card="standings"] a[href="/en/manage-event-standings/' . self::WS_COMPETITION . '"]');
    }

    public function testThePreviewSumsEveryRoundAndSaysProvisional(): void
    {
        $solo = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $pairs = $this->create('World Series - Pairs', RoundCategory::Duo, $this->pairRounds);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-preview="' . $solo . '"] [data-standing-provisional]');
        $soloNames = $crawler->filter('[data-standing-preview="' . $solo . '"] [data-standing-name]')->each(static fn ($node): string => $node->text());
        self::assertSame(['Alice', 'Cyril', 'Bob', 'Dora'], $soloNames);
        $soloRanks = $crawler->filter('[data-standing-preview="' . $solo . '"] [data-standing-rank]')->each(static fn ($node): string => $node->text());
        self::assertSame(['1', '2', '3', '–'], $soloRanks);
        $totals = $crawler->filter('[data-standing-preview="' . $solo . '"] [data-standing-total]')->each(static fn ($node): string => $node->text());
        self::assertSame(['01:40:00', '01:50:00', '02:15:00', '01:06:40'], $totals);
        self::assertSelectorTextContains('[data-standing-preview="' . $solo . '"] [data-standing-incomplete]', '5 of 6 rounds');

        $pairNames = $crawler->filter('[data-standing-preview="' . $pairs . '"] [data-standing-name]')->each(static fn ($node): string => $node->text());
        self::assertSame(['Alice & Bob', 'Team Cyril', 'Team Cyril'], $pairNames);
        // Every category is covered - no "one per category" offer
        self::assertSelectorNotExists('[data-standings-per-category]');
    }

    public function testTheFormsCreateEditAndDelete(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION . '/new');
        self::assertResponseIsSuccessful();
        $this->submitForm($crawler, [
            'name' => 'Solo overall',
            'category' => 'solo',
            'penaltySecondsPerPiece' => '3',
            // Competition toolkit T6 - pieces missing from a finished puzzle penalised too
            'penaliseFinishedMissing' => '1',
            'orderRule' => 'total_only',
            'roundIds' => $this->soloRounds,
        ]);
        self::assertResponseRedirects('/en/manage-event-standings/' . self::WS_COMPETITION, 303);

        /** @var array{id: string, round_ids: string, unfinished_penalty_seconds_per_piece: int, order_rule: string, penalise_finished_missing: bool} $row */
        $row = $this->database->fetchAssociative('SELECT id, round_ids, unfinished_penalty_seconds_per_piece, order_rule, penalise_finished_missing FROM competition_standing WHERE competition_id = :id', ['id' => self::WS_COMPETITION]);
        self::assertSame(3, $row['unfinished_penalty_seconds_per_piece']);
        self::assertTrue($row['penalise_finished_missing']);
        self::assertSame('total_only', $row['order_rule']);
        self::assertSame($this->soloRounds, json_decode($row['round_ids'], true, flags: JSON_THROW_ON_ERROR));

        // A pair round in a solo standing: refused, 422, nothing changed
        $editUrl = '/en/manage-event-standings/' . self::WS_COMPETITION . '/' . $row['id'] . '/edit';
        $crawler = $this->browser->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-preview="' . $row['id'] . '"]');
        $this->submitForm($crawler, ['roundIds' => [...$this->soloRounds, $this->pairRounds[0]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-standing-form]', 'All rounds of a standing must be of its category.');

        // Another event's id in the URL: 404
        $this->browser->request('GET', '/en/manage-event-standings/' . Uuid::uuid7()->toString() . '/' . $row['id'] . '/edit');
        self::assertResponseStatusCodeSame(404);

        // Delete needs the token
        $deleteUrl = '/en/manage-event-standings/' . self::WS_COMPETITION . '/' . $row['id'] . '/delete';
        $this->browser->request('POST', $deleteUrl, ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);
        $token = $this->browser->getCrawler()->filter('[data-standing="' . $row['id'] . '"] input[name="_token"]')->attr('value');
        $this->browser->request('POST', $deleteUrl, ['_token' => $token]);
        self::assertResponseRedirects('/en/manage-event-standings/' . self::WS_COMPETITION, 303);
        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_standing WHERE id = :id', ['id' => $row['id']]));
    }

    public function testOnePerCategoryCreatesTheWorldSeriesStandings(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);
        $token = $this->browser->getCrawler()->filter('[data-standings-per-category] input[name="_token"]')->attr('value');

        $this->browser->request('POST', '/en/manage-event-standings/' . self::WS_COMPETITION . '/per-category', ['_token' => $token]);
        self::assertResponseRedirects('/en/manage-event-standings/' . self::WS_COMPETITION, 303);

        /** @var list<array{name: string, category: string, round_ids: string}> $rows */
        $rows = $this->database->fetchAllAssociative('SELECT name, category, round_ids FROM competition_standing WHERE competition_id = :id ORDER BY position', ['id' => self::WS_COMPETITION]);
        self::assertSame(['Overall - Solo', 'Overall - Pair'], array_column($rows, 'name'));
        self::assertSame(['solo', 'duo'], array_column($rows, 'category'));
        self::assertSame($this->soloRounds, json_decode($rows[0]['round_ids'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($this->pairRounds, json_decode($rows[1]['round_ids'], true, flags: JSON_THROW_ON_ERROR));

        // Again: nothing more to create
        $this->browser->request('POST', '/en/manage-event-standings/' . self::WS_COMPETITION . '/per-category', ['_token' => $token]);
        self::assertEquals(2, $this->database->fetchOne('SELECT COUNT(*) FROM competition_standing WHERE competition_id = :id', ['id' => self::WS_COMPETITION]));
    }

    public function testTheOrganiserJson(): void
    {
        $solo = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $url = '/en/official-results/standings/' . $solo;

        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(401);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        // The organiser reads it too
        TestingLogin::asPlayer($this->browser, self::WS_ORGANISER);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));

        /** @var array{standing: array<string, mixed>, rounds: list<array<string, mixed>>, rows: list<array<string, mixed>>} $state */
        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('World Series - Solo', $state['standing']['name']);
        self::assertSame('solo', $state['standing']['category']);
        self::assertTrue($state['standing']['provisional']);
        self::assertTrue($state['standing']['includesUnpublished']);
        self::assertFalse($state['standing']['published']);
        self::assertSame(6, $state['standing']['roundsCounted']);
        self::assertSame(['penaltySecondsPerPiece' => 0, 'order' => 'finished_rounds_first', 'missing' => 'incomplete', 'penaliseFinishedMissing' => false], $state['standing']['rules']);
        self::assertSame([true, true, true, false, false, false], array_column($state['rounds'], 'published'));
        self::assertSame([1, 2, 3, null], array_column($state['rows'], 'rank'));
        self::assertSame([6000, 6600, 8100, 4000], array_column($state['rows'], 'totalSeconds'));
        self::assertSame(['Alice', 'Cyril', 'Bob', 'Dora'], array_column($state['rows'], 'name'));
        /** @var list<array<string, mixed>> $bobRounds */
        $bobRounds = $state['rows'][2]['perRound'];
        self::assertSame(['roundId' => $this->soloRounds[5], 'status' => 'unfinished', 'result' => ['piecesPlaced' => 450], 'scoreSeconds' => 3600, 'rank' => 4, 'timeSeconds' => 3600, 'missingPieces' => 50, 'penaltySeconds' => 0], $bobRounds[5]);

        // Another standing id: 404
        $this->browser->request('GET', '/en/official-results/standings/' . Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Submits the standing form with these fields changed (the rest as the page rendered them).
     *
     * @param array<string, string|list<string>> $fields
     */
    private function submitForm(Crawler $crawler, array $fields): void
    {
        $form = $crawler->filter('[data-standing-form]')->form();
        $values = $form->getPhpValues();
        /** @var array<string, mixed> $data */
        $data = $values['competition_standing_form'];
        $values['competition_standing_form'] = [...$data, ...$fields];

        $this->browser->request('POST', $form->getUri(), $values);
    }

    /**
     * @param list<string> $roundIds
     */
    private function create(string $name, RoundCategory $category, array $roundIds): string
    {
        $standingId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new CreateCompetitionStanding($standingId, self::WS_COMPETITION, $name, $category, $roundIds, new StandingRules()),
        );

        return $standingId->toString();
    }
}
