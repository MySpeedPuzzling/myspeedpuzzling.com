<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\OfficialEntrantsView;
use SpeedPuzzling\Web\Results\OfficialRoundEntrants;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The public round page of a `pick` round (competition toolkit A5, A-round-formats.md §2.7-2.8): per-puzzle chips by
 * the public reveal rule and "Add to my profile" per puzzle, on the Results Cup's Group B as a pick round -
 * SECRET (A, hidden with a manual reveal), PUBLIC (B, Puzzle 14, 2000 pieces), the fixture's Puzzle 17 (C, 5000).
 * Gina (the private player) 3900 s, Hugo (PLAYER_REGULAR) 4500 s, Ivan (nobody's) 5000 s; a 90-minute limit.
 */
final class PickRoundPublicPageTest extends WebTestCase
{
    use QueryCountAssertions;
    use RoundPuzzleFormatSetup;

    private const string URL = '/en/events/results-cup/results/group-b';

    private KernelBrowser $browser;
    private string $big;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->big = self::addRoundPuzzles($this->database());
        $this->database()->executeStatement(
            'UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ', results_first_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => self::ROUND],
        );
    }

    public function testEachRowShowsItsPuzzlesInSolvingOrderAndAHiddenOneByItsLabelOnly(): void
    {
        self::makePick($this->database(), 2);
        $this->record(OfficialResultsFixture::ENTRY_B_GINA, [self::done(self::PUBLIC, 1500), self::done(self::SECRET, 3900)]);
        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [self::done($this->big, 2700), self::done(self::PUBLIC, 4500)]);

        $crawler = $this->browser->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-official-results] tbody tr');
        self::assertSame(['1', '2', '3'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-official-rank')));

        self::assertSame(['B Puzzle 14 00:25:00', 'A 01:05:00'], [self::chipText($rows->eq(0), 0), self::chipText($rows->eq(0), 1)]);
        self::assertCount(1, $rows->eq(0)->filter('[data-official-entry-puzzle-hidden]'));
        self::assertSame(['C Puzzle 17 00:45:00', 'B Puzzle 14 01:15:00'], [self::chipText($rows->eq(1), 0), self::chipText($rows->eq(1), 1)]);
        // Ivan has no record: no chips, the row as on any round
        self::assertCount(0, $rows->eq(2)->filter('[data-official-entry-puzzles]'));

        // Nothing of the hidden puzzle - its name, its catalogue id, its round puzzle id
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString(self::PUBLIC_NAME, $content);
        self::assertStringNotContainsString(self::SECRET_NAME, $content);
        self::assertStringNotContainsString(PuzzleFixture::PUZZLE_1500_01, $content);
        self::assertStringNotContainsString(self::SECRET, $content);
        // A guest is offered nothing
        $this->assertSelectorNotExists('[data-official-add-to-profile]');
        $this->assertSelectorNotExists('[data-official-splits-missing]');

        // Once revealed, the name comes
        $this->database()->executeStatement("UPDATE competition_round_puzzle SET reveal_mode = 'scheduled', reveal_at = " . TestClock::SQL_NOW . " - INTERVAL '1 minute' WHERE id = :id", ['id' => self::SECRET]);
        $crawler = $this->browser->request('GET', self::URL);
        self::assertSame('A Puzzle 12 01:05:00', self::chipText($crawler->filter('[data-official-results] tbody tr')->eq(0), 1));
    }

    public function testUnfinishedPuzzlesShowTheirPiecesAndTheRowItsOwnPieceTotal(): void
    {
        self::makePick($this->database(), 2);
        $this->database()->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = NULL, result_pieces_placed = 2400 WHERE id = :id',
            ['id' => OfficialResultsFixture::ENTRY_B_IVAN],
        );
        $this->record(OfficialResultsFixture::ENTRY_B_IVAN, [self::done(self::PUBLIC, 3000), ['roundPuzzleId' => $this->big, 'done' => false, 'piecesPlaced' => 400]]);

        $crawler = $this->browser->request('GET', self::URL);

        $ivan = $crawler->filter('[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN . '"]');
        self::assertSame('C Puzzle 17 400 pcs', self::chipText($ivan, 1));
        // Its own puzzles: 2000 + 5000 pieces
        self::assertSame('2400 / 7000 pcs', $ivan->filter('.lb-time-value')->text());

        // A hidden chosen puzzle keeps its piece count to itself
        $this->record(OfficialResultsFixture::ENTRY_B_IVAN, [self::done(self::SECRET, 3000), ['roundPuzzleId' => $this->big, 'done' => false, 'piecesPlaced' => 900]]);
        $this->database()->executeStatement('UPDATE competition_participant_round SET result_pieces_placed = 2400 WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_IVAN]);
        $crawler = $this->browser->request('GET', self::URL);
        self::assertSame('2400 pcs', $crawler->filter('[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN . '"] .lb-time-value')->text());
    }

    public function testPickTwoOffersEachFinishedPuzzleWithItsOwnTime(): void
    {
        self::makePick($this->database(), 2);
        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [self::done($this->big, 2700), self::done(self::PUBLIC, 4500)]);
        $this->record(OfficialResultsFixture::ENTRY_B_GINA, [self::done(self::PUBLIC, 1500), self::done(self::SECRET, 3900)]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', self::URL);

        $offers = $crawler->filter('[data-official-add-to-profile]');
        self::assertSame(['Add Puzzle 17 · 00:45:00', 'Add Puzzle 14 · 00:30:00'], $offers->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame(
            sprintf('/en/puzzle-add/%s?competition=%s&official_entry=participant_round:%s&round_puzzle=%s', PuzzleFixture::PUZZLE_2000, OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ENTRY_B_HUGO, self::PUBLIC),
            $offers->eq(1)->attr('href'),
        );
        // Only on Hugo's own row
        self::assertCount(2, $crawler->filter('[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO . '"] [data-official-add-to-profile]'));

        // The add form fills in that puzzle and its own time
        $form = $this->browser->request('GET', (string) $offers->eq(1)->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSame(PuzzleFixture::PUZZLE_2000, self::value($form, 'puzzle'));
        self::assertSame(['0', '30', '0'], [self::value($form, 'timeHours'), self::value($form, 'timeMinutes'), self::value($form, 'timeSeconds')]);
        self::assertSame(self::PUBLIC, $form->filter('input[type="hidden"][name="round_puzzle"]')->attr('value'));
        $this->assertSelectorExists('[data-official-entry-notice]');

        // Once that time is on his profile, that puzzle says so - the other is still offered
        $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_2000, '00:30:00');
        $crawler = $this->browser->request('GET', self::URL);
        self::assertSame(['Add Puzzle 17 · 00:45:00'], $crawler->filter('[data-official-add-to-profile]')->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame(self::PUBLIC, $crawler->filter('[data-official-on-profile]')->attr('data-official-round-puzzle'));
    }

    public function testAHiddenPuzzleIsNeverOffered(): void
    {
        self::makePick($this->database(), 2);
        $this->record(OfficialResultsFixture::ENTRY_B_GINA, [self::done(self::PUBLIC, 1500), self::done(self::SECRET, 3900)]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $this->browser->request('GET', self::URL);

        self::assertSame(['Add Puzzle 14 · 00:25:00'], $crawler->filter('[data-official-add-to-profile]')->each(static fn (Crawler $link): string => trim($link->text())));

        // A link naming the hidden one fills nothing in
        $form = $this->browser->request('GET', sprintf('/en/puzzle-add?competition=%s&official_entry=participant_round:%s&round_puzzle=%s', OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ENTRY_B_GINA, self::SECRET));
        self::assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-official-entry-notice]');
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());
        self::assertCount(0, $form->filter('input[name="round_puzzle"]'));
    }

    public function testWithoutSplitsNothingIsOfferedAndOnlyTheEntrantIsToldWhy(): void
    {
        self::makePick($this->database(), 2);
        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [['roundPuzzleId' => $this->big, 'done' => true], ['roundPuzzleId' => self::PUBLIC, 'done' => true]]);

        $this->browser->request('GET', self::URL);
        $this->assertSelectorNotExists('[data-official-splits-missing]');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', self::URL);
        $this->assertSelectorNotExists('[data-official-add-to-profile]');
        $this->assertSelectorExists('[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO . '"] [data-official-splits-missing]');

        // A link made up anyway fills nothing in
        $this->browser->request('GET', sprintf('/en/puzzle-add?competition=%s&official_entry=participant_round:%s&round_puzzle=%s', OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ENTRY_B_HUGO, self::PUBLIC));
        $this->assertSelectorNotExists('[data-official-entry-notice]');
    }

    public function testPickOneOffersTheChosenPuzzleLikeAOnePuzzleRound(): void
    {
        self::makePick($this->database(), 1);
        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [['roundPuzzleId' => self::PUBLIC, 'done' => true]]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', self::URL);

        $offer = $crawler->filter('[data-official-add-to-profile]');
        self::assertSame(['Add Puzzle 14 · 01:15:00'], $offer->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame('B Puzzle 14', self::chipText($crawler->filter('[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO . '"]'), 0));

        $form = $this->browser->request('GET', (string) $offer->attr('href'));
        self::assertSame(PuzzleFixture::PUZZLE_2000, self::value($form, 'puzzle'));
        self::assertSame(['1', '15', '0'], [self::value($form, 'timeHours'), self::value($form, 'timeMinutes'), self::value($form, 'timeSeconds')]);

        // Without round_puzzle a pick round's entry fills nothing in
        $this->browser->request('GET', sprintf('/en/puzzle-add?competition=%s&official_entry=participant_round:%s', OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ENTRY_B_HUGO));
        $this->assertSelectorNotExists('[data-official-entry-notice]');

        // On the profile: like a one-puzzle round, "On your profile"
        $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_2000, '01:15:00');
        $crawler = $this->browser->request('GET', self::URL);
        $this->assertSelectorNotExists('[data-official-add-to-profile]');
        self::assertSame(self::PUBLIC, $crawler->filter('[data-official-on-profile]')->attr('data-official-round-puzzle'));
    }

    public function testPickOneOffersAnUnfinishedResultWithItsPieces(): void
    {
        self::makePick($this->database(), 1);
        $this->database()->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = NULL, result_pieces_placed = 1200 WHERE id = :id',
            ['id' => OfficialResultsFixture::ENTRY_B_HUGO],
        );
        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [['roundPuzzleId' => self::PUBLIC, 'done' => false, 'piecesPlaced' => 1200]]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', self::URL);

        $hugo = $crawler->filter('[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO . '"]');
        self::assertSame('1200 / 2000 pcs', $hugo->filter('.lb-time-value')->text());
        $offer = $hugo->filter('[data-official-add-to-profile]');
        self::assertSame('Add Puzzle 14 to my profile', trim($offer->text()));

        $form = $this->browser->request('GET', (string) $offer->attr('href'));
        self::assertSame('checked', $form->filter('input[name="puzzle_add_form[unfinished]"]')->attr('checked'));
        self::assertSame('1200', $form->filter('input[name="puzzle_add_form[piecesPlaced]"]')->attr('value'));
    }

    public function testPickOneOfAHiddenPuzzleOffersNothing(): void
    {
        self::makePick($this->database(), 1);
        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [['roundPuzzleId' => self::SECRET, 'done' => true]]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', self::URL);

        $this->assertSelectorNotExists('[data-official-add-to-profile]');
        self::assertSame('A', self::chipText($crawler->filter('[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO . '"]'), 0));
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());
    }

    /**
     * A pick round costs the page no statement more than the same round as `total`: its puzzles ride on the rounds
     * statement, its records on the entries statement. Only a puzzle offering "Add to my profile" asks for the viewer's
     * own times in the round - the one statement a one-puzzle round's offer costs too.
     */
    public function testAPickRoundCostsNoStatementMoreThanATotalRound(): void
    {
        // Without splits Hugo is offered nothing
        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [['roundPuzzleId' => $this->big, 'done' => true], ['roundPuzzleId' => self::PUBLIC, 'done' => true]]);

        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            if ($viewer !== null) {
                TestingLogin::asPlayer($this->browser, $viewer);
            }

            [$total, $pick] = $this->totalThenPick();
            self::assertSame($total, $pick, sprintf('Viewer %s: a pick round costs %d statements more.', $viewer ?? 'guest', $pick - $total));
        }

        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [self::done($this->big, 2700), self::done(self::PUBLIC, 4500)]);
        [$total, $pick] = $this->totalThenPick();
        $this->assertSelectorExists('[data-official-add-to-profile]');
        self::assertSame($total + 1, $pick, 'An offer costs the viewer\'s own times in the round, nothing else.');
    }

    /**
     * @return array{int, int}
     */
    private function totalThenPick(): array
    {
        $this->database()->executeStatement("UPDATE competition_round SET puzzle_format = 'total', puzzles_to_solve = NULL WHERE id = :id", ['id' => self::ROUND]);
        $total = $this->countedRequest();
        self::makePick($this->database(), 2);

        return [$total, $this->countedRequest()];
    }

    /**
     * The view is the competitor pages' and standings' read too (R-review-and-plan.md §2.3): several rounds at once,
     * published only, ranks kept when rows are hidden from the viewer.
     */
    public function testTheEntrantsViewReadsSeveralRoundsAndPublishedOnesOnly(): void
    {
        $view = self::getContainer()->get(OfficialEntrantsView::class);
        $rounds = [
            OfficialResultsFixture::ROUND_GROUP_A => RoundCategory::Solo,
            self::ROUND => RoundCategory::Solo,
            OfficialResultsFixture::ROUND_PAIRS => RoundCategory::Duo,
        ];

        $entrants = $view->forRounds($rounds, null);

        self::assertSame(['1', '2', '2', '4'], array_map(static fn (PublishedRoundEntry $entry): string => (string) $entry->rank, $entrants[OfficialResultsFixture::ROUND_GROUP_A]->entries));
        self::assertSame(3, $entrants[self::ROUND]->rankedCount);
        // Pairs is not published: nothing of it
        self::assertTrue($entrants[OfficialResultsFixture::ROUND_PAIRS]->isEmpty());
        self::assertSame([], $entrants[OfficialResultsFixture::ROUND_PAIRS]->entries);
        self::assertSame(2, self::rankOf($entrants[self::ROUND], OfficialResultsFixture::ENTRY_B_HUGO));
        // The private player keeps the organiser's name only
        $gina = $entrants[self::ROUND]->entries[0];
        self::assertSame('Gina Quick', $gina->entrants[0]->playerName);
        self::assertNull($gina->entrants[0]->playerId);
    }

    private static function rankOf(OfficialRoundEntrants $entrants, string $entryId): null|int
    {
        return $entrants->rankOf(RoundEntryRef::participantRound($entryId));
    }

    private function countedRequest(): int
    {
        // Warm-up: whatever the page caches, the counted request finds it cached
        $this->browser->request('GET', self::URL);
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-official-results]');

        return $this->queryCount($this->browser);
    }

    /**
     * @param list<array<string, mixed>> $puzzles
     */
    private function record(string $entryId, array $puzzles): void
    {
        $this->database()->executeStatement(
            'UPDATE competition_participant_round SET result_puzzles = :puzzles WHERE id = :id',
            ['puzzles' => json_encode($puzzles, JSON_THROW_ON_ERROR), 'id' => $entryId],
        );
    }

    /**
     * @return array{roundPuzzleId: string, done: true, atSeconds: int}
     */
    private static function done(string $roundPuzzleId, int $atSeconds): array
    {
        return ['roundPuzzleId' => $roundPuzzleId, 'done' => true, 'atSeconds' => $atSeconds];
    }

    private static function chipText(Crawler $row, int $index): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $row->filter('[data-official-entry-puzzle]')->eq($index)->text()));
    }

    private static function value(Crawler $crawler, string $field): string
    {
        $input = $crawler->filter(sprintf('[name="puzzle_add_form[%s]"]', $field));
        self::assertCount(1, $input, $field);

        return (string) $input->attr('value');
    }

    private function addTime(string $userId, string $puzzleId, string $time): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
