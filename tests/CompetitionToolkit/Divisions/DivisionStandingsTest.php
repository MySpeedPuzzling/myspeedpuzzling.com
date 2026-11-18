<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionDivisions;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Message\EditCompetitionStanding;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Message\SetCompetitionDivisions;
use SpeedPuzzling\Web\Message\SetRoundEntryDivisions;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\PublicStandingRow;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Results\StandingTable;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\WorldSeriesSetup;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DivisionInput;
use SpeedPuzzling\Web\Value\EntryDivisionInput;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingMode;
use SpeedPuzzling\Web\Value\StandingRules;
use SpeedPuzzling\Web\Value\StandingScoreStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Standings per division (real events L2a, shared-puzzles-and-divisions.md D7) on the World-Series-like event
 * (WorldSeriesSetup: 6 solo rounds, 1-3 published): Regular and Senior 55+ (S) on every solo round, none on the pair
 * rounds. Alice and Cyril are Senior, Bob and Dora Regular - except Cyril, who is Regular in Solo 2.
 *
 * Senior standing over the 6 solo rounds: Alice 6 × 1000 = 6000 (1st), Cyril 5 × 1100 = 5500 incomplete (Solo 2 he
 * played in Regular - "not in the round" there, never a no-show). Each cell shows the place within the division.
 */
final class DivisionStandingsTest extends WebTestCase
{
    use WorldSeriesSetup;

    private KernelBrowser $browser;
    private MessageBusInterface $messageBus;
    private Connection $database;
    private string $regular = '';
    private string $senior = '';

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testTheOrganiserViewCountsOnlyTheDivisionsEntries(): void
    {
        $this->setUpDivisions();
        $standingId = $this->create('Senior cup', RoundCategory::Solo, $this->soloRounds, $this->senior);

        $table = $this->table($standingId);
        self::assertNotNull($table->standing->division);
        self::assertSame($this->senior, $table->standing->division->id);
        self::assertSame('Senior 55+', $table->standing->division->name);
        self::assertSame(6, $table->roundsCounted());
        self::assertSame(['Alice' => [1, 6000, true], 'Cyril' => [null, 5500, false]], self::rows($table->rows));

        $alice = $table->rows[0];
        // The place within the division: Alice is 3rd of round 1 overall (Dora, Bob are faster), 1st of the Seniors
        self::assertSame(1, $alice->perRound[$this->soloRounds[0]]->rank);
        $cyril = $table->rows[1];
        self::assertSame(2, $cyril->perRound[$this->soloRounds[0]]->rank);
        self::assertSame(StandingScoreStatus::NotInRound, $cyril->perRound[$this->soloRounds[1]]->status);

        // The organiser JSON names the division
        self::assertSame(['id' => $this->senior, 'name' => 'Senior 55+', 'shortName' => 'S'], $table->standing->toArray()['division'] ?? null);

        // The Regular standing: Bob and Dora, Cyril with his one Regular round
        $regularId = $this->create('Regular cup', RoundCategory::Solo, $this->soloRounds, $this->regular);
        self::assertSame(['Bob' => [1, 8100, true], 'Dora' => [null, 4000, false], 'Cyril' => [null, 1100, false]], self::rows($this->table($regularId)->rows));
    }

    public function testAnotherDivisionInARoundIsNoNoShow(): void
    {
        $this->setUpDivisions();
        $standingId = $this->create('Senior cup', RoundCategory::Solo, array_slice($this->soloRounds, 0, 3), $this->senior, new StandingRules(5, missing: StandingMissing::AsZeroPieces));

        $rows = $this->table($standingId)->rows;
        self::assertSame(['Alice' => [1, 3000, true], 'Cyril' => [null, 2200, false]], self::rows($rows));
        // Solo 2 is published (final) - Cyril played it in Regular, so it stays "not in the round"
        self::assertSame(StandingScoreStatus::NotInRound, $rows[1]->perRound[$this->soloRounds[1]]->status);
    }

    public function testTheWritesCheckTheDivision(): void
    {
        $this->setUpDivisions();

        // Not a division of the event
        $this->assertRefused('unknown_division', fn () => $this->create('X', RoundCategory::Solo, $this->soloRounds, Uuid::uuid7()->toString()));
        // The pair rounds use no division
        $this->assertRefused('division_not_in_round', fn () => $this->create('X', RoundCategory::Duo, $this->pairRounds, $this->senior));

        // A `stages` standing has no division - whatever the form sent is dropped (its no_steps refusal comes first here)
        $this->assertRefused('no_steps', fn () => $this->messageBus->dispatch(new CreateCompetitionStanding(Uuid::uuid7(), self::WS_COMPETITION, 'X', RoundCategory::Solo, [], new StandingRules(), StandingMode::Stages, divisionId: $this->senior)));

        // Edit: changed, kept (false), cleared (null)
        $standingId = $this->create('Senior cup', RoundCategory::Solo, $this->soloRounds, $this->senior);
        $this->messageBus->dispatch(new EditCompetitionStanding($standingId, self::WS_COMPETITION, 'Regular cup', RoundCategory::Solo, $this->soloRounds, new StandingRules(), divisionId: $this->regular));
        self::assertSame($this->regular, $this->divisionOf($standingId));
        $this->messageBus->dispatch(new EditCompetitionStanding($standingId, self::WS_COMPETITION, 'Regular cup', RoundCategory::Solo, $this->soloRounds, new StandingRules()));
        self::assertSame($this->regular, $this->divisionOf($standingId));
        $this->messageBus->dispatch(new EditCompetitionStanding($standingId, self::WS_COMPETITION, 'Everybody', RoundCategory::Solo, $this->soloRounds, new StandingRules(), divisionId: null));
        self::assertNull($this->divisionOf($standingId));
    }

    public function testADivisionAStandingCountsIsNeverRemoved(): void
    {
        $this->setUpDivisions();
        $this->create('Senior cup', RoundCategory::Solo, $this->soloRounds, $this->senior);

        // Removed from the list
        $this->assertDivisionsRefused(fn () => $this->messageBus->dispatch(new SetCompetitionDivisions(
            competitionId: self::WS_COMPETITION,
            divisions: [new DivisionInput($this->regular, 'Regular')],
            rounds: array_fill_keys($this->soloRounds, [$this->regular]),
            confirmClearing: true,
        )));

        // Removed from one round the standing counts
        $rounds = array_fill_keys($this->soloRounds, [$this->regular, $this->senior]);
        $rounds[$this->soloRounds[2]] = [$this->regular];
        $this->assertDivisionsRefused(fn () => $this->messageBus->dispatch(new SetCompetitionDivisions(
            competitionId: self::WS_COMPETITION,
            divisions: [new DivisionInput($this->regular, 'Regular'), new DivisionInput($this->senior, 'Senior 55+', 'S')],
            rounds: $rounds,
            confirmClearing: true,
        )));

        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM competition_division WHERE competition_id = :id', ['id' => self::WS_COMPETITION]));
    }

    public function testUntouchedStandingsStayByteIdentical(): void
    {
        $overallId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $this->messageBus->dispatch(new PublishCompetitionStanding($overallId, self::WS_COMPETITION));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $before = $this->snapshotOf($overallId);
        self::assertArrayNotHasKey('division', $this->table($overallId)->standing->toArray());

        $this->setUpDivisions();
        $seniorId = $this->create('Senior cup', RoundCategory::Solo, $this->soloRounds, $this->senior);
        $this->messageBus->dispatch(new PublishCompetitionStanding($seniorId, self::WS_COMPETITION));

        self::assertSame($before, $this->snapshotOf($overallId));
    }

    public function testThePublicPageAndJsonNameTheDivision(): void
    {
        $this->setUpDivisions();
        $standingId = $this->create('Senior cup', RoundCategory::Solo, $this->soloRounds, $this->senior);
        $this->messageBus->dispatch(new PublishCompetitionStanding($standingId, self::WS_COMPETITION));
        $overallId = $this->create('Overall - Solo', RoundCategory::Solo, $this->soloRounds);
        $this->messageBus->dispatch(new PublishCompetitionStanding($overallId, self::WS_COMPETITION));

        // Public: rounds 1-3 published - Alice 3000 (1st), Cyril 2200 incomplete (Solo 2 in Regular)
        $page = $this->browser->request('GET', '/en/events/world-series-test/standings/senior-cup');
        self::assertResponseIsSuccessful();
        self::assertSame('Senior 55+', trim($page->filter('[data-standing-title] [data-standing-division]')->text()));
        self::assertSame(['1', ''], $page->filter('[data-standing-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-standing-rank')));
        $table = $page->filter('[data-standing-table]')->text();
        self::assertStringContainsString('Alice', $table);
        self::assertStringContainsString('Cyril', $table);
        self::assertStringNotContainsString('Bob', $table);
        self::assertStringNotContainsString('Dora', $table);

        // The overall standing's page has no division badge
        $page = $this->browser->request('GET', '/en/events/world-series-test/standings/overall-solo');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-standing-division]'));

        $this->browser->request('GET', '/api/competition-standings/' . $standingId);
        self::assertResponseIsSuccessful();
        /** @var array{standing: array<string, mixed>, rows: list<array<string, mixed>>} $json */
        $json = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'Senior 55+', 'shortName' => 'S'], $json['standing']['division'] ?? null);
        self::assertSame(['Alice', 'Cyril'], array_column($json['rows'], 'name'));
        self::assertSame([1, null], array_column($json['rows'], 'rank'));

        $this->browser->request('GET', '/api/competition-standings/' . $overallId);
        /** @var array{standing: array<string, mixed>} $json */
        $json = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('division', $json['standing']);
    }

    public function testTheFormOffersTheDivisionsOnlyWhenTheEventHasThem(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $newUrl = '/en/manage-event-standings/' . self::WS_COMPETITION . '/new';

        $this->browser->request('GET', $newUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-standing-division-field]');

        $this->setUpDivisions();
        $crawler = $this->browser->request('GET', $newUrl);
        self::assertSelectorExists('[data-standing-division-field]');
        self::assertSame(['Everybody (no division)', 'Regular', 'Senior 55+'], $crawler->filter('[data-standing-division-field] option')->each(static fn (Crawler $option): string => trim($option->text())));

        // A division the pair rounds do not use: refused, 422, nothing saved
        $this->submitForm($crawler, ['name' => 'Senior pairs', 'category' => 'duo', 'roundIds' => $this->pairRounds, 'divisionId' => $this->senior]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-standing-form]', 'Every round of a division');

        $crawler = $this->browser->request('GET', $newUrl);
        $this->submitForm($crawler, ['name' => 'Senior cup', 'category' => 'solo', 'roundIds' => $this->soloRounds, 'divisionId' => $this->senior]);
        self::assertResponseRedirects('/en/manage-event-standings/' . self::WS_COMPETITION, 303);
        $standingId = $this->database->fetchOne('SELECT id FROM competition_standing WHERE competition_id = :id', ['id' => self::WS_COMPETITION]);
        self::assertIsString($standingId);
        self::assertSame($this->senior, $this->divisionOf($standingId));

        // The edit page keeps it chosen; the standings page shows it
        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION . '/' . $standingId . '/edit');
        self::assertSame($this->senior, $crawler->filter('[data-standing-division-field] option[selected]')->attr('value'));
        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);
        self::assertSame('Senior 55+', trim($crawler->filter('[data-standing="' . $standingId . '"] [data-standing-division]')->text()));
    }

    public function testOnePerCategoryAndDivision(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $listUrl = '/en/manage-event-standings/' . self::WS_COMPETITION;

        // Without divisions: no such offer
        $this->browser->request('GET', $listUrl);
        self::assertSelectorNotExists('[data-standings-per-division]');

        $this->setUpDivisions();
        $this->create('Senior cup', RoundCategory::Solo, $this->soloRounds, $this->senior);
        $crawler = $this->browser->request('GET', $listUrl);
        // The Senior one exists already; the pair rounds use no division
        self::assertStringContainsString('Overall - Solo - Regular.', $crawler->filter('[data-standings-per-division]')->text());
        self::assertStringNotContainsString('Senior', $crawler->filter('[data-standings-per-division]')->text());
        // The overall "one per category" still offers Solo - a division's standing is no overall one
        self::assertStringContainsString('Solo', $crawler->filter('[data-standings-per-category]')->text());

        $token = $crawler->filter('[data-standings-per-division] input[name="_token"]')->attr('value');
        $this->browser->request('POST', $listUrl . '/per-category', ['_token' => $token, 'per_division' => '1']);
        self::assertResponseRedirects($listUrl, 303);

        /** @var list<array{name: string, category: string, division_id: null|string, round_ids: string}> $rows */
        $rows = $this->database->fetchAllAssociative('SELECT name, category, division_id, round_ids FROM competition_standing WHERE competition_id = :id ORDER BY position', ['id' => self::WS_COMPETITION]);
        self::assertSame(['Senior cup', 'Overall - Solo - Regular'], array_column($rows, 'name'));
        self::assertSame($this->regular, $rows[1]['division_id']);
        self::assertSame($this->soloRounds, json_decode($rows[1]['round_ids'], true, flags: JSON_THROW_ON_ERROR));

        // Nothing more to create
        $this->browser->request('GET', $listUrl);
        self::assertSelectorNotExists('[data-standings-per-division]');
    }

    public function testDuplicatingTheEventMapsTheDivisionToTheCopys(): void
    {
        $this->setUpDivisions();
        $this->create('Senior cup', RoundCategory::Solo, $this->soloRounds, $this->senior);
        $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);

        $copyId = Uuid::uuid7();
        $this->messageBus->dispatch(new DuplicateCompetition(
            newCompetitionId: $copyId,
            sourceCompetitionId: self::WS_COMPETITION,
            actingPlayerId: self::WS_ORGANISER,
        ));

        $copies = self::getContainer()->get(GetCompetitionStandings::class)->ofCompetition($copyId->toString());
        self::assertSame(['Senior cup', 'World Series - Solo'], array_map(static fn ($standing): string => $standing->name, $copies));
        self::assertNull($copies[1]->division);
        self::assertNotNull($copies[0]->division);
        self::assertSame('Senior 55+', $copies[0]->division->name);
        self::assertNotSame($this->senior, $copies[0]->division->id);
        self::assertSame($copyId->toString(), $this->database->fetchOne('SELECT competition_id FROM competition_division WHERE id = :id', ['id' => $copies[0]->division->id]));
    }

    private function setUpDivisions(): void
    {
        $this->regular = Uuid::uuid7()->toString();
        $this->senior = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SetCompetitionDivisions(
            competitionId: self::WS_COMPETITION,
            divisions: [new DivisionInput($this->regular, 'Regular'), new DivisionInput($this->senior, 'Senior 55+', 'S')],
            rounds: array_fill_keys($this->soloRounds, [$this->regular, $this->senior]),
        ));

        foreach ($this->soloRounds as $index => $roundId) {
            $this->messageBus->dispatch(new SetRoundEntryDivisions(
                competitionId: self::WS_COMPETITION,
                roundId: $roundId,
                entries: [
                    new EntryDivisionInput($this->people['Alice'], null, 'S'),
                    new EntryDivisionInput($this->people['Bob'], null, 'Regular'),
                    new EntryDivisionInput($this->people['Cyril'], null, $index === 1 ? 'Regular' : 'S'),
                    new EntryDivisionInput($this->people['Dora'], null, 'Regular'),
                ],
            ));
        }
    }

    /**
     * @param list<string> $roundIds
     */
    private function create(string $name, RoundCategory $category, array $roundIds, null|string $divisionId = null, StandingRules $rules = new StandingRules()): string
    {
        $standingId = Uuid::uuid7();
        $this->messageBus->dispatch(new CreateCompetitionStanding($standingId, self::WS_COMPETITION, $name, $category, $roundIds, $rules, divisionId: $divisionId));

        return $standingId->toString();
    }

    private function table(string $standingId): StandingTable
    {
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($standingId);

        return self::getContainer()->get(GetStandingResults::class)->organiserView($standing);
    }

    private function divisionOf(string $standingId): null|string
    {
        $divisionId = $this->database->fetchOne('SELECT division_id FROM competition_standing WHERE id = :id', ['id' => $standingId]);

        return is_string($divisionId) ? $divisionId : null;
    }

    /**
     * Everything an untouched standing shows: the organiser JSON, the guest view, the signed-in table fragment.
     *
     * @return array{string, string, string}
     */
    private function snapshotOf(string $standingId): array
    {
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($standingId);
        $guest = self::getContainer()->get(GetStandingResults::class)->publicView($standing, null, asGuest: true);

        $this->browser->request('GET', '/en/standing-table/' . $standingId);
        self::assertResponseIsSuccessful();

        return [
            json_encode($this->table($standingId)->toArray(), JSON_THROW_ON_ERROR),
            json_encode([$guest->table->toArray(), array_map(static fn (PublicStandingRow $row): array => $row->row->toArray(), $guest->rows)], JSON_THROW_ON_ERROR),
            (string) $this->browser->getResponse()->getContent(),
        ];
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
     * @param list<StandingRow> $rows
     * @return array<string, array{null|int, int, bool}>
     */
    private static function rows(array $rows): array
    {
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->name] ??= [$row->rank, $row->totalSeconds, $row->complete];
        }

        return $byName;
    }

    private function assertRefused(string $reason, Closure $write): void
    {
        try {
            $write();
            self::fail('Expected the standing to be refused: ' . $reason);
        } catch (HandlerFailedException $failed) {
            $previous = $failed->getPrevious();
            self::assertInstanceOf(InvalidCompetitionStanding::class, $previous);
            self::assertSame($reason, $previous->reason);
        } catch (InvalidCompetitionStanding $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function assertDivisionsRefused(Closure $write): void
    {
        try {
            $write();
            self::fail('Expected the divisions to be refused');
        } catch (HandlerFailedException $failed) {
            $previous = $failed->getPrevious();
            self::assertInstanceOf(InvalidCompetitionDivisions::class, $previous);
            self::assertSame('used_by_standing', $previous->reason);
            self::assertSame('Senior cup', $previous->subject);
        } catch (InvalidCompetitionDivisions $refused) {
            self::assertSame('used_by_standing', $refused->reason);
        }
    }
}
