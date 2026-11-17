<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetRoundStandingRules;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\PublicStandingRow;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Results\StandingTable;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;
use SpeedPuzzling\Web\Value\StandingScoreStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The World Series no-show (world-series-rules.md §4 + review amendments W1-a/b/c): `missing_rule = as_zero_pieces` in
 * the organiser's preview and on the public page (same ranks once everything is published), the form, the substitutes
 * warning, the results desk's publish line and help line, and the round state's `penalisedMissing`.
 *
 * World Series fixture (WorldSeriesSetup): rounds 1-3 of each category published; Dora did not start solo round 4; solo
 * round 6 has a 500-piece puzzle, the other rounds none (unknown pieces = no penalty part); 60 minutes each.
 */
final class NoShowStandingTest extends WebTestCase
{
    use WorldSeriesSetup;

    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testTheOrganiserPreviewScoresNoShowsOnceTheRoundIsPublished(): void
    {
        $standingId = $this->create('Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(2, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces));

        // Dora's explicit "did not start" in round 4 (unpublished) is a no-show at once: 800 × 5 + 3600
        $table = $this->table($standingId);
        self::assertSame(['Alice' => [1, 6000], 'Cyril' => [2, 6600], 'Dora' => [3, 7600], 'Bob' => [4, 8200]], self::rows($table->rows));
        self::assertSame(StandingScoreStatus::NoShow, self::row($table, 'Dora')->perRound[$this->soloRounds[3]]->status);

        // Nothing recorded for Alice in published round 2 = a no-show; nothing for Cyril in unpublished round 5 = not yet
        $this->clearResult($this->soloRounds[1], 'Alice');
        $this->clearResult($this->soloRounds[4], 'Cyril');
        $table = $this->table($standingId);
        self::assertSame(['Dora' => [1, 7600], 'Bob' => [2, 8200], 'Alice' => [3, 8600], 'Cyril' => [null, 5500]], self::rows($table->rows));
        self::assertSame(StandingScoreStatus::NoShow, self::row($table, 'Alice')->perRound[$this->soloRounds[1]]->status);
        self::assertSame(StandingScoreStatus::NoResult, self::row($table, 'Cyril')->perRound[$this->soloRounds[4]]->status);
        self::assertTrue($table->noShowsWaitForPublishing());

        // The pick-free 500-piece round 6: a no-show there is the limit + 2 s × 500
        $this->clearResult($this->soloRounds[5], 'Alice');
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $this->soloRounds[5]]);
        $noShow = self::row($this->table($standingId), 'Alice')->perRound[$this->soloRounds[5]];
        self::assertSame([StandingScoreStatus::NoShow, 3600, 500, 1000, 4600], [$noShow->status, $noShow->timeSeconds, $noShow->missingPieces, $noShow->penaltySeconds, $noShow->scoreSeconds]);
    }

    public function testPublicRanksEqualThePreviewAndSubstitutesAreFlagged(): void
    {
        $soloId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(2, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces));
        $pairsId = $this->create('World Series - Pairs', RoundCategory::Duo, $this->pairRounds, new StandingRules(2, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces));
        // An absentee in a published round: nothing recorded for Cyril in solo round 2
        $this->clearResult($this->soloRounds[1], 'Cyril');
        $this->messageBus->dispatch(new PublishCompetitionStanding($soloId, self::WS_COMPETITION));
        $this->messageBus->dispatch(new PublishCompetitionStanding($pairsId, self::WS_COMPETITION));
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE competition_id = :id', ['id' => self::WS_COMPETITION]);

        $standings = self::getContainer()->get(GetCompetitionStandings::class);
        $results = self::getContainer()->get(GetStandingResults::class);

        foreach ([$soloId, $pairsId] as $standingId) {
            $standing = $standings->byId($standingId);
            $organiser = $results->organiserView($standing);
            $public = $results->publicView($standing, null);

            self::assertSame(
                array_map(static fn (StandingRow $row): array => [$row->key, $row->rank, $row->totalSeconds, $row->complete], $organiser->rows),
                array_map(static fn (PublicStandingRow $row): array => [$row->row->key, $row->row->rank, $row->row->totalSeconds, $row->row->complete], $public->rows),
            );
        }

        // Solo: Cyril 1100 × 5 + 3600, Dora 800 × 5 + 3600, Bob 4500 + 3600 + 50 × 2 - all ranked
        $solo = $results->organiserView($standings->byId($soloId));
        self::assertSame(['Alice' => [1, 6000], 'Dora' => [2, 7600], 'Bob' => [3, 8200], 'Cyril' => [4, 9100]], self::rows($solo->rows));
        self::assertSame(0, $solo->lineupsInSomeRounds());
        self::assertFalse($solo->noShowsWaitForPublishing());

        // Pairs: Cyril + Dora 1800 × 5 + 3600; the substitute line-up Cyril + Eve 1700 + 5 × 3600 - both flagged
        $pairs = $results->organiserView($standings->byId($pairsId));
        self::assertSame([[1, 12000], [2, 12600], [3, 19700]], array_map(static fn (StandingRow $row): array => [$row->rank, $row->totalSeconds], $pairs->rows));
        self::assertSame(2, $pairs->lineupsInSomeRounds());

        // The public page: the no-show cell and the rule line
        $crawler = $this->browser->request('GET', '/en/events/world-series-test/standings/' . $this->slug($soloId));
        self::assertResponseIsSuccessful();
        self::assertSame(['1', '2', '3', '4'], $crawler->filter('[data-standing-row]')->each(static fn (Crawler $row): string => trim((string) $row->attr('data-standing-rank'))));
        self::assertCount(2, $crawler->filter('td[data-standing-score="no_show"]'));
        self::assertSelectorTextContains('[data-standing-rules]', 'a round without a result counts as the time limit + 2 s × all pieces');

        // The organiser's page: the substitutes warning on the pairs standing only
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-standing="' . $pairsId . '"] [data-standing-substitutes="2"]'));
        self::assertCount(0, $crawler->filter('[data-standing="' . $soloId . '"] [data-standing-substitutes]'));
        self::assertSelectorTextContains('[data-standing="' . $pairsId . '"] [data-standing-substitutes]', '2 pairs or teams appear in only some rounds');
        self::assertCount(2, $crawler->filter('[data-standing="' . $soloId . '"] [data-standing-score="no_show"]'));
    }

    public function testAStandingWithTheDefaultRuleRendersNoNoShowAnywhere(): void
    {
        $this->clearResult($this->soloRounds[1], 'Cyril');
        $standingId = $this->create('Solo', RoundCategory::Solo, $this->soloRounds);

        $table = $this->table($standingId);
        self::assertNull(self::row($table, 'Cyril')->rank);
        self::assertSame(0, $table->lineupsInSomeRounds());
        self::assertFalse($table->noShowsWaitForPublishing());

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);
        self::assertCount(0, $crawler->filter('[data-standing-score="no_show"], [data-standing-rule-no-show], [data-standing-no-shows-wait]'));
    }

    public function testTheFormKeepsTheMissingRule(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION . '/new');
        self::assertResponseIsSuccessful();
        // The default is the old rule
        self::assertSame('incomplete', $crawler->filter('[data-standing-missing-rule] input:checked')->attr('value'));
        $this->submitForm($crawler, [
            'name' => 'Solo overall',
            'category' => 'solo',
            'penaltySecondsPerPiece' => '5',
            'orderRule' => 'total_only',
            'missingRule' => 'as_zero_pieces',
            'roundIds' => $this->soloRounds,
        ]);
        self::assertResponseRedirects('/en/manage-event-standings/' . self::WS_COMPETITION, 303);

        /** @var array{id: string, missing_rule: string} $row */
        $row = $this->database->fetchAssociative('SELECT id, missing_rule FROM competition_standing WHERE competition_id = :id', ['id' => self::WS_COMPETITION]);
        self::assertSame('as_zero_pieces', $row['missing_rule']);

        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION . '/' . $row['id'] . '/edit');
        self::assertResponseIsSuccessful();
        self::assertSame('as_zero_pieces', $crawler->filter('[data-standing-missing-rule] input:checked')->attr('value'));
        // Unpublished rounds 4-6 count already: the preview says no-shows wait for publishing
        self::assertSelectorExists('[data-standing-no-shows-wait]');

        $this->submitForm($crawler, ['missingRule' => 'incomplete']);
        self::assertResponseRedirects();
        self::assertSame('incomplete', $this->database->fetchOne('SELECT missing_rule FROM competition_standing WHERE id = :id', ['id' => $row['id']]));
    }

    public function testTheRoundToolsKnowTheStandingsCountingTheRound(): void
    {
        $rules = self::getContainer()->get(GetRoundStandingRules::class);
        $round = $this->soloRounds[3];

        // No standing: the defaults, and the round state and desk keep their shape
        self::assertFalse($rules->forRound($round)->penalisedMissing);
        self::assertSame([], $rules->forRound($round)->noShowStandings);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $state = $this->roundState($round);
        self::assertArrayNotHasKey('penalisedMissing', $state['round']);
        self::assertArrayNotHasKey('noShowStandings', $this->deskState($round)['round']);

        // A standing penalising finished-missing pieces, another one scoring no-shows; a pairs standing counts nothing here
        $this->create('Penalised', RoundCategory::Solo, $this->soloRounds, new StandingRules(5, penaliseFinishedMissing: true));
        $this->create('World Series - Solo', RoundCategory::Solo, [$round], new StandingRules(5, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces));
        $this->create('Other rounds', RoundCategory::Solo, [$this->soloRounds[0]], new StandingRules(5, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces));
        $this->create('Pairs', RoundCategory::Duo, $this->pairRounds, new StandingRules(5, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces, true));

        $known = $rules->forRound(strtoupper($round));
        self::assertTrue($known->penalisedMissing);
        self::assertSame(['World Series - Solo'], $known->noShowStandings);

        // The judge's state: only `penalisedMissing`; the desk: both (the publish readiness line, the help line)
        $state = $this->roundState($round);
        self::assertTrue($state['round']['penalisedMissing']);
        self::assertArrayNotHasKey('noShowStandings', $state['round']);
        $desk = $this->deskState($round);
        self::assertTrue($desk['round']['penalisedMissing']);
        self::assertSame(['World Series - Solo'], $desk['round']['noShowStandings']);
        self::assertArrayHasKey('color', $desk['round']);
        $texts = $this->browser->getCrawler()->filter('[data-controller="results-desk"]')->attr('data-results-desk-texts-value');
        self::assertIsString($texts);
        self::assertStringContainsString('Only pieces that cost a penalty under your rules.', $texts);
        self::assertStringContainsString('will count as no-shows in %standing%', $texts);

        // A penalty of 0 penalises nothing: no help line
        $this->database->executeStatement('UPDATE competition_standing SET unfinished_penalty_seconds_per_piece = 0 WHERE name = :name', ['name' => 'Penalised']);
        self::assertFalse($rules->forRound($round)->penalisedMissing);
    }

    /**
     * @return array{round: array<string, mixed>}
     */
    private function roundState(string $roundId): array
    {
        $this->browser->request('GET', '/en/official-results/rounds/' . $roundId);
        self::assertResponseIsSuccessful();
        /** @var array{round: array<string, mixed>} $state */
        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $state;
    }

    /**
     * @return array{round: array<string, mixed>}
     */
    private function deskState(string $roundId): array
    {
        $crawler = $this->browser->request('GET', '/en/manage-round-results/' . $roundId);
        self::assertResponseIsSuccessful();
        /** @var array{round: array<string, mixed>} $state */
        $state = json_decode((string) $crawler->filter('[data-controller="results-desk"]')->attr('data-results-desk-state-value'), true, flags: JSON_THROW_ON_ERROR);

        return $state;
    }

    private function clearResult(string $roundId, string $name): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = NULL, result_pieces_placed = NULL, result_did_not_start = false WHERE round_id = :round AND participant_id = :participant',
            ['round' => $roundId, 'participant' => $this->people[$name]],
        );
    }

    /**
     * @param list<string> $roundIds
     */
    private function create(string $name, RoundCategory $category, array $roundIds, StandingRules $rules = new StandingRules()): string
    {
        $standingId = Uuid::uuid7();
        $this->messageBus->dispatch(new CreateCompetitionStanding($standingId, self::WS_COMPETITION, $name, $category, $roundIds, $rules));

        return $standingId->toString();
    }

    private function table(string $standingId): StandingTable
    {
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($standingId);

        return self::getContainer()->get(GetStandingResults::class)->organiserView($standing);
    }

    private static function row(StandingTable $table, string $name): StandingRow
    {
        foreach ($table->rows as $row) {
            if ($row->name === $name) {
                return $row;
            }
        }

        self::fail('No row of ' . $name);
    }

    /**
     * @param list<StandingRow> $rows
     * @return array<string, array{null|int, int}>
     */
    private static function rows(array $rows): array
    {
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->name] ??= [$row->rank, $row->totalSeconds];
        }

        return $byName;
    }

    /**
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

    private function slug(string $standingId): string
    {
        $slug = $this->database->fetchOne('SELECT slug FROM competition_standing WHERE id = :id', ['id' => $standingId]);
        assert(is_string($slug));

        return $slug;
    }
}
