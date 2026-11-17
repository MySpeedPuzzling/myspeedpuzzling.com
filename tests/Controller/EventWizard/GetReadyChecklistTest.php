<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * W2 of the event creation wizard (docs/features/competitions-management/toolkit/W-creation-wizard-research.md §3.10,
 * §6): the "Get ready" checklist on the edit page, its statement cost, "Continue setting up" of a resumable draft on
 * the edit page, the draft banner and "You organize", and the checklist right after the wizard's Publish. The page
 * without the wizard is pinned byte for byte by EditPageWithoutWizardGoldenTest.
 */
final class GetReadyChecklistTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    private const string FLAG = 'EVENT_CREATION_WIZARD';
    private const string EVENT = CompetitionFixture::COMPETITION_UNAPPROVED;
    private const string ORGANISER = PlayerFixture::PLAYER_REGULAR;
    private const string DRAFT = OrganizationFixture::COMPETITION_DRAFT_NIGHT;
    private const string DRAFT_ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private null|KernelBrowser $client = null;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testAFreshEventHasDetailsDoneTheRoundToAddAndJudgesAsASuggestion(): void
    {
        $crawler = $this->editPage(self::EVENT, self::ORGANISER);

        self::assertSame(['details' => 'done', 'rounds' => 'todo', 'judges' => 'tip'], self::rows($crawler));
        self::assertSelectorTextContains('[data-get-ready-count]', '1 of 2 done');
        self::assertSelectorTextContains('[data-get-ready-heading]', 'Get ready for');
        self::assertStringContainsString('/en/manage-event-rounds/' . self::EVENT, (string) $crawler->filter('[data-get-ready-row="rounds"] a')->attr('href'));
        // Above the button row, which stays
        self::assertSelectorExists('[data-get-ready] + .mb-3 [data-referees-link]');
        self::assertSelectorNotExists('[data-event-wizard-continue]');
        self::assertSame('false', $crawler->filter('[data-get-ready]')->attr('data-get-ready-force-open-value'));
    }

    public function testWithoutTheFlagAnOrganiserHasNoChecklistAnAdminHasIt(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);

        $this->editPage(self::EVENT, self::ORGANISER);
        self::assertSelectorNotExists('[data-get-ready]');

        self::ensureKernelShutdown();
        $this->editPage(self::EVENT, PlayerFixture::PLAYER_ADMIN);
        self::assertSelectorExists('[data-get-ready]');
    }

    public function testAnotherPlayerGetsNoEditPage(): void
    {
        $this->client = self::createClient();
        TestingLogin::asPlayer($this->browser(), PlayerFixture::PLAYER_PRIVATE);
        $this->browser()->request('GET', '/en/edit-event/' . self::EVENT);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEveryRowOfAnEventInFullPreparation(): void
    {
        $this->client = self::createClient();
        [$secretRound, $laterRound] = $this->prepareFullEvent();

        $crawler = $this->editPage(self::EVENT, self::ORGANISER, newClient: false);

        self::assertSame([
            'details' => 'done',
            'rounds' => 'todo',
            'judges' => 'done',
            'registration' => 'done',
            'tables' => 'todo',
            'name_tags' => 'tip',
            'timer_screen' => 'tip',
            'stopwatch_test' => 'tip',
        ], self::rows($crawler));
        self::assertSelectorTextContains('[data-get-ready-count]', '3 of 5 done');

        // The round decided later → its puzzles; the seating of the round missing tables; the secret round's stopwatch
        self::assertStringContainsString('/en/manage-round-puzzles/' . $laterRound, (string) $crawler->filter('[data-get-ready-row="rounds"] a')->attr('href'));
        self::assertSelectorTextContains('[data-get-ready-row="rounds"]', 'Later round has no puzzle yet.');
        self::assertSelectorTextContains('[data-get-ready-row="judges"]', 'Judge added (1)');
        self::assertSelectorTextContains('[data-get-ready-row="registration"]', '2 of 10 places taken');
        self::assertSelectorTextContains('[data-get-ready-row="tables"]', '1 of 2 entries have a table');
        self::assertStringContainsString('/en/round-seating/' . $secretRound, (string) $crawler->filter('[data-get-ready-row="tables"] a')->attr('href'));
        self::assertStringContainsString('/en/manage-round-stopwatch/' . $secretRound, (string) $crawler->filter('[data-get-ready-row="stopwatch_test"] a')->attr('href'));
    }

    public function testTheChecklistIsGoneOnceTheLastRoundFinished(): void
    {
        $this->client = self::createClient();
        $this->insertRound(self::EVENT, 'Morning', TestClock::now()->modify('-5 hours')->format('Y-m-d H:i:s'));
        $this->editPage(self::EVENT, self::ORGANISER, newClient: false);
        self::assertSelectorNotExists('[data-get-ready]');
    }

    public function testTheChecklistCostsTwoStatements(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $without = $this->measure(self::EVENT, self::ORGANISER);

        $this->overrideFeatureFlagEnv(self::FLAG, true);
        self::ensureKernelShutdown();
        self::assertSame($without + 2, $this->measure(self::EVENT, self::ORGANISER), 'the event with its counts + its rounds with theirs');
    }

    public function testTheChecklistCostsTwoStatementsWhateverTheNumberOfRounds(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $this->client = self::createClient();
        $this->prepareFullEvent();
        foreach (range(1, 4) as $more) {
            $this->insertRound(self::EVENT, 'Extra ' . $more, '2027-01-0' . $more . ' 10:00:00');
        }
        $without = $this->measureWithClient(self::EVENT, self::ORGANISER);

        // Six rounds with entries, puzzles, a judge and a display: still two
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        self::ensureKernelShutdown();
        self::assertSame($without + 2, $this->measure(self::EVENT, self::ORGANISER));
    }

    public function testContinueSettingUpForAResumableDraft(): void
    {
        $crawler = $this->editPage(self::DRAFT, self::DRAFT_ORGANISER);
        $continue = '/en/new-event/' . self::DRAFT;
        self::assertSame($continue, $crawler->filter('[data-event-wizard-continue-line] [data-event-wizard-continue]')->attr('href'));
        self::assertSelectorExists('[data-get-ready]');

        $crawler = $this->browser()->request('GET', '/en/events/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG);
        self::assertResponseIsSuccessful();
        self::assertSame($continue, $crawler->filter('[data-draft-banner-own] [data-event-wizard-continue]')->attr('href'));

        $crawler = $this->browser()->request('GET', '/en/you-organize');
        self::assertResponseIsSuccessful();
        self::assertSame($continue, $crawler->filter('[data-organized-id="' . self::DRAFT . '"] [data-event-wizard-continue]')->attr('href'));
        // One link per resumable draft, none for published events
        self::assertCount(count(array_unique($crawler->filter('[data-event-wizard-continue]')->each(static fn (Crawler $link): string => (string) $link->attr('href')))), $crawler->filter('[data-event-wizard-continue]'));

        // The link resumes: the draft's round has its puzzle, nothing of Extras is on → Extras (W6)
        $this->browser()->request('GET', $continue);
        self::assertResponseRedirects('/en/new-event/' . self::DRAFT . '/extras');
    }

    public function testNoContinueWithoutTheWizardOrForADraftItCannotResume(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $this->editPage(self::DRAFT, self::DRAFT_ORGANISER);
        self::assertSelectorNotExists('[data-event-wizard-continue]');
        $this->browser()->request('GET', '/en/events/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG);
        self::assertSelectorExists('[data-draft-banner-own]');
        self::assertSelectorNotExists('[data-event-wizard-continue]');
        $this->browser()->request('GET', '/en/you-organize');
        self::assertSelectorNotExists('[data-event-wizard-continue]');

        // A second round: since W5 several rounds have their wizard steps - the draft resumes there (its Puzzles step)
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->insertRound(self::DRAFT, 'Second round', '2026-11-08 20:30:00');
        $this->editPage(self::DRAFT, self::DRAFT_ORGANISER, newClient: false);
        self::assertSelectorExists('[data-get-ready]');
        self::assertSelectorExists('[data-event-wizard-continue]');
        $this->browser()->request('GET', '/en/you-organize');
        self::assertSelectorExists('[data-organized-id="' . self::DRAFT . '"] [data-event-wizard-continue]');
    }

    public function testAfterTheWizardsPublishTheChecklistIsOpenWithWhatIsLeft(): void
    {
        $this->client = self::createClient();
        TestingLogin::asPlayer($this->browser(), self::ORGANISER);

        $crawler = $this->browser()->request('GET', '/en/new-event/basics');
        self::assertResponseIsSuccessful();
        $this->browser()->submit($crawler->filter('form[name="event_wizard_basics_form"]')->form(), [
            'event_wizard_basics_form[name]' => 'Checklist Cup',
            'event_wizard_basics_form[isOnline]' => '0',
            'event_wizard_basics_form[location]' => 'Library, Brno',
            'event_wizard_basics_form[dateFrom]' => '15.06.2027',
            'event_wizard_basics_form[startTime]' => '10:00',
        ]);
        $competitionId = $this->database()->fetchOne("SELECT id FROM competition WHERE name = 'Checklist Cup'");
        self::assertIsString($competitionId);

        // Finished later: "Continue setting up" on "You organize" resumes at the puzzle (decided later)
        $crawler = $this->browser()->request('GET', '/en/you-organize');
        $continue = $crawler->filter('[data-organized-id="' . $competitionId . '"] [data-event-wizard-continue]');
        self::assertCount(1, $continue);
        $this->browser()->request('GET', (string) $continue->attr('href'));
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle');

        $crawler = $this->browser()->request('GET', '/en/new-event/' . $competitionId . '/review');
        $this->browser()->submit($crawler->selectButton('Publish')->form());
        self::assertResponseRedirects('/en/edit-event/' . $competitionId, 303);

        $crawler = $this->browser()->followRedirect();
        self::assertSelectorTextContains('[data-get-ready-heading]', "Published - here's what's left");
        self::assertSame('true', $crawler->filter('[data-get-ready]')->attr('data-get-ready-force-open-value'));
        self::assertSame('todo', $crawler->filter('[data-get-ready-row="rounds"]')->attr('data-get-ready-state'));
        self::assertSelectorNotExists('[data-event-wizard-continue]');

        // Once: the next visit is the plain checklist
        $this->browser()->request('GET', '/en/edit-event/' . $competitionId);
        self::assertSelectorTextContains('[data-get-ready-heading]', 'Get ready for');
    }

    /**
     * Two rounds of the event (the first with a secret puzzle and two entries, one seated; the second decided later),
     * managed registration for 10, one judge, one timer screen.
     *
     * @return array{string, string}
     */
    private function prepareFullEvent(): array
    {
        $database = $this->database();
        $database->executeStatement('UPDATE competition SET registration_managed = true, capacity = 10 WHERE id = :id', ['id' => self::EVENT]);

        $secretRound = $this->insertRound(self::EVENT, 'Main round', '2027-01-10 10:00:00');
        $laterRound = $this->insertRound(self::EVENT, 'Later round', '2027-01-10 14:00:00');
        $database->insert('competition_round_puzzle', [
            'id' => Uuid::uuid7()->toString(),
            'round_id' => $secretRound,
            'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
            'hide_until_round_starts' => 'true',
            'reveal_mode' => 'automatic',
            'hides_everywhere' => 'false',
        ]);

        foreach (['Ada' => 7, 'Bea' => null] as $name => $table) {
            $participant = Uuid::uuid7()->toString();
            $database->executeStatement(
                "INSERT INTO competition_participant (id, name, country, competition_id, source) VALUES (:id, :name, 'cz', :competition, 'imported')",
                ['id' => $participant, 'name' => $name, 'competition' => self::EVENT],
            );
            $database->executeStatement(
                'INSERT INTO competition_participant_round (id, participant_id, round_id, table_number, result_did_not_start) VALUES (:id, :participant, :round, :table, false)',
                ['id' => Uuid::uuid7()->toString(), 'participant' => $participant, 'round' => $secretRound, 'table' => $table],
            );
        }

        $database->executeStatement(
            'INSERT INTO competition_referee (id, competition_id, player_id, added_at) VALUES (:id, :competition, :player, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'competition' => self::EVENT, 'player' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new AddCompetitionDisplay(Uuid::uuid7(), self::EVENT, DisplayKind::Screen, 'Hall', 'en', PlayerFixture::PLAYER_ADMIN));

        return [$secretRound, $laterRound];
    }

    private function insertRound(string $competitionId, string $name, string $startsAt): string
    {
        $id = Uuid::uuid7()->toString();
        $this->database()->executeStatement(
            'INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at) VALUES (:id, :competition, :name, 60, :startsAt)',
            ['id' => $id, 'competition' => $competitionId, 'name' => $name, 'startsAt' => $startsAt],
        );

        return $id;
    }

    private function editPage(string $competitionId, string $playerId, bool $newClient = true): Crawler
    {
        if ($newClient) {
            $this->client = self::createClient();
        }

        TestingLogin::asPlayer($this->browser(), $playerId);
        $crawler = $this->browser()->request('GET', '/en/edit-event/' . $competitionId);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * The second request is counted (the first warms the caches)
     */
    private function measure(string $competitionId, string $playerId): int
    {
        $this->client = self::createClient();

        return $this->measureWithClient($competitionId, $playerId);
    }

    private function measureWithClient(string $competitionId, string $playerId): int
    {
        TestingLogin::asPlayer($this->browser(), $playerId);
        $this->browser()->request('GET', '/en/edit-event/' . $competitionId);
        $this->startCountingQueries($this->browser());
        $this->browser()->request('GET', '/en/edit-event/' . $competitionId);
        self::assertResponseIsSuccessful();

        return $this->queryCount($this->browser());
    }

    /**
     * @return array<string, string> row key => done | todo | tip
     */
    private static function rows(Crawler $crawler): array
    {
        $rows = [];

        foreach ($crawler->filter('[data-get-ready-row]') as $row) {
            self::assertInstanceOf(\DOMElement::class, $row);
            $rows[$row->getAttribute('data-get-ready-row')] = $row->getAttribute('data-get-ready-state');
        }

        return $rows;
    }

    private function browser(): KernelBrowser
    {
        self::assertNotNull($this->client, 'Create the client first');

        return $this->client;
    }

    private function database(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
