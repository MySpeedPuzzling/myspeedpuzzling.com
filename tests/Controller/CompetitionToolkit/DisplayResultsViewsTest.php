<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions\DivisionsSetup;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\SolveAllBlockSetup;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A display's results views (real events L2b, displays.md "Division and challenge views"): a leaderboard / ticker with
 * a `division` shows that division's entries with their division rank (A13), a leaderboard with a `challenge` ranks a
 * solve-all block by that puzzle's own time (W4's ChallengeRanking) - only a puzzle shown to everybody. Each view rides
 * in `results.views[element id]`; a design without one answers exactly as before (no `views` key, same statements).
 *
 * St. Paul's divisions (DivisionsSetup) on Group A (published): Anna Regular 1., Ben + Cara Senior 2. (tie), Dan Senior
 * unfinished 4., Eva Junior did not start. The block (SolveAllBlockSetup) on Group B (published): challenge 1 Hugo 20:00,
 * Gina 25:00, Ivan 30:00; challenge 2 Gina 40:00, Ivan 53:20, Hugo 55:00; the round Gina 1., Hugo 2., Ivan 3.
 */
final class DisplayResultsViewsTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use DivisionsSetup;
    use QueryCountAssertions;

    private const array PLAIN = ['type' => 'leaderboard', 'props' => ['rows' => 10]];

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testADivisionViewShowsItsEntriesWithTheirDivisionRank(): void
    {
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class));
        $token = self::createDisplay(F::COMPETITION_RESULTS_CUP, [
            self::PLAIN,
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'division' => strtoupper($this->senior)]],
            ['type' => 'ticker', 'props' => ['rows' => 2, 'division' => $this->senior]],
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'division' => $this->junior]],
        ], F::ROUND_GROUP_A);

        [$raw, $state] = self::displayState($this->browser, $token);

        // The plain leaderboard keeps the whole round with the overall ranks
        self::assertSame([[1, 'Anna Fast'], [2, 'Ben Steady'], [2, 'Cara Tied'], [4, 'Dan Unfinished']], self::ranksAndNames(self::at($state, 'results.top')));
        // Senior: the tie shares 1., Dan's unfinished result is 3. in the division
        self::assertSame([[1, 'Ben Steady'], [1, 'Cara Tied'], [3, 'Dan Unfinished']], self::ranksAndNames(self::at($state, 'results.views.el000001')));
        self::assertCount(2, (array) self::at($state, 'results.views.el000002'));
        // Junior: Eva did not start - nobody to show
        self::assertSame([], self::at($state, 'results.views.el000003'));
        // The views need no whole-round ticker
        self::assertSame([], self::at($state, 'results.latest'));
        self::assertStringNotContainsString('Eva Noshow', $raw);
        self::assertStringNotContainsString($this->senior, $raw);
    }

    public function testADivisionTheRoundDoesNotUseShowsTheWholeRound(): void
    {
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class));
        // Group B uses no division (live, not published)
        $token = self::createDisplay(F::COMPETITION_RESULTS_CUP, [
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'source' => 'live', 'division' => $this->senior]],
        ], F::ROUND_GROUP_B);

        [, $state] = self::displayState($this->browser, $token);

        self::assertSame([[1, 'Gina Quick'], [2, 'Hugo Slow'], [3, 'Ivan Last']], self::ranksAndNames(self::at($state, 'results.views.el000000')));
        self::assertSame([], self::at($state, 'results.top'));
    }

    public function testAChallengeViewRanksTheBlockByThatPuzzlesOwnTime(): void
    {
        SolveAllBlockSetup::seed($this->database());
        $token = self::createDisplay(F::COMPETITION_RESULTS_CUP, [
            self::PLAIN,
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'challenge' => 1]],
            ['type' => 'leaderboard', 'props' => ['rows' => 2, 'challenge' => 2]],
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'challenge' => 7]],
        ], F::ROUND_GROUP_B);

        [$raw, $state] = self::displayState($this->browser, $token);

        self::assertSame([[1, 'Gina Quick'], [2, 'Hugo Slow'], [3, 'Ivan Last']], self::ranksAndNames(self::at($state, 'results.top')));
        self::assertSame([[1, 'Hugo Slow'], [2, 'Gina Quick'], [3, 'Ivan Last']], self::ranksAndNames(self::at($state, 'results.views.el000001')));
        self::assertSame([1200, 1500, 1800], array_column((array) self::at($state, 'results.views.el000001'), 'resultSeconds'));
        self::assertSame([false, false, false], array_column((array) self::at($state, 'results.views.el000001'), 'didNotFinish'));
        // Challenge 2, cut to the element's 2 rows: Gina 40:00, Ivan 53:20
        self::assertSame([[1, 'Gina Quick'], [2, 'Ivan Last']], self::ranksAndNames(self::at($state, 'results.views.el000002')));
        self::assertSame([2400, 3200], array_column((array) self::at($state, 'results.views.el000002'), 'resultSeconds'));
        // No 7th puzzle - the whole round
        self::assertSame([[1, 'Gina Quick'], [2, 'Hugo Slow'], [3, 'Ivan Last']], self::ranksAndNames(self::at($state, 'results.views.el000003')));
        // Nothing of the round's puzzles comes with the views (pick rounds' slots are never sent)
        self::assertStringNotContainsString(SolveAllBlockSetup::CHALLENGE_ONE, $raw);
    }

    public function testAHiddenPuzzleNeverGetsARanking(): void
    {
        SolveAllBlockSetup::seed($this->database());
        $this->database()->executeStatement(
            "UPDATE competition_round_puzzle SET hide_until_round_starts = true, hide_mode = 'entirely', reveal_mode = 'manual' WHERE id = :id",
            ['id' => SolveAllBlockSetup::CHALLENGE_ONE],
        );
        $token = self::createDisplay(F::COMPETITION_RESULTS_CUP, [
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'challenge' => 1]],
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'challenge' => 2]],
        ], F::ROUND_GROUP_B);

        [, $state] = self::displayState($this->browser, $token);

        // The hidden challenge 1 shows the round's ranking, never its own times
        self::assertSame([[1, 'Gina Quick'], [2, 'Hugo Slow'], [3, 'Ivan Last']], self::ranksAndNames(self::at($state, 'results.views.el000000')));
        self::assertSame([3900, 4500, 5000], array_column((array) self::at($state, 'results.views.el000000'), 'resultSeconds'));
        self::assertSame([2400, 3200, 3300], array_column((array) self::at($state, 'results.views.el000001'), 'resultSeconds'));
    }

    public function testAChallengeOnARoundThatIsNoBlockShowsTheWholeRoundAndWinsOverADivision(): void
    {
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class));
        $token = self::createDisplay(F::COMPETITION_RESULTS_CUP, [
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'challenge' => 1]],
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'challenge' => 1, 'division' => $this->senior]],
        ], F::ROUND_GROUP_A);

        [, $state] = self::displayState($this->browser, $token);

        // Group A is no block: the challenge means nothing - the whole round, or the division when one is chosen
        self::assertSame([[1, 'Anna Fast'], [2, 'Ben Steady'], [2, 'Cara Tied'], [4, 'Dan Unfinished']], self::ranksAndNames(self::at($state, 'results.views.el000000')));
        self::assertSame([[1, 'Ben Steady'], [1, 'Cara Tied'], [3, 'Dan Unfinished']], self::ranksAndNames(self::at($state, 'results.views.el000001')));

        // On the block the challenge wins - every entry, the public page's challenge view
        SolveAllBlockSetup::seed($this->database());
        $block = self::createDisplay(F::COMPETITION_RESULTS_CUP, [
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'challenge' => 1, 'division' => $this->senior]],
        ], F::ROUND_GROUP_B);
        [, $state] = self::displayState($this->browser, $block);
        self::assertSame([[1, 'Hugo Slow'], [2, 'Gina Quick'], [3, 'Ivan Last']], self::ranksAndNames(self::at($state, 'results.views.el000000')));
    }

    public function testBeforePublishingAViewShowsOnlyWhenItsElementIsLive(): void
    {
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class));
        $this->database()->executeStatement('UPDATE competition_round SET results_published_at = NULL WHERE id = :id', ['id' => F::ROUND_GROUP_A]);

        $waiting = self::createDisplay(F::COMPETITION_RESULTS_CUP, [
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'division' => $this->senior]],
        ], F::ROUND_GROUP_A);
        [$raw, $state] = self::displayState($this->browser, $waiting);
        self::assertSame(['published' => false, 'finished' => null, 'total' => null, 'top' => [], 'latest' => [], 'views' => []], $state['results']);
        self::assertStringContainsString('"views":{}', $raw);
        self::assertStringNotContainsString('Ben Steady', $raw);

        $live = self::createDisplay(F::COMPETITION_RESULTS_CUP, [
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'division' => $this->senior]],
            ['type' => 'leaderboard', 'props' => ['rows' => 10, 'division' => $this->regular, 'source' => 'live']],
        ], F::ROUND_GROUP_A);
        [, $state] = self::displayState($this->browser, $live);
        self::assertSame(['el000001'], array_keys((array) self::at($state, 'results.views')));
        self::assertSame([[1, 'Anna Fast']], self::ranksAndNames(self::at($state, 'results.views.el000001')));
    }

    public function testADesignWithoutViewsAnswersAsBeforeAndAViewCostsOneStatement(): void
    {
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class));
        $plain = self::createDisplay(F::COMPETITION_RESULTS_CUP, [self::PLAIN], F::ROUND_GROUP_A);
        $withView = self::createDisplay(F::COMPETITION_RESULTS_CUP, [self::PLAIN, ['type' => 'leaderboard', 'props' => ['division' => $this->senior]]], F::ROUND_GROUP_A);

        [, $state] = self::displayState($this->browser, $plain);
        self::assertIsArray($state['results']);
        self::assertArrayNotHasKey('views', $state['results']);

        foreach ([[$plain, 4], [$withView, 5]] as [$token, $statements]) {
            $this->startCountingQueries($this->browser);
            self::displayState($this->browser, $token);
            self::assertSame($statements, $this->queryCount($this->browser));
        }
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    /**
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function ranksAndNames(mixed $rows): array
    {
        self::assertIsArray($rows);

        return array_values(array_map(static fn (mixed $row): array => is_array($row) ? [$row['rank'] ?? null, $row['name'] ?? null] : [null, null], $rows));
    }
}
