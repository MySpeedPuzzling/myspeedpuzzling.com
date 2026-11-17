<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The analytics studio's JSON costs a fixed number of statements (analytics-studio.md §2.1, R11 - pinned at what the
 * implementation runs). Every answer reads the round and its visible puzzles (2 - its cache keys are made of them);
 * analytics, entrant and predictions also the entrants (3). Cold:
 *
 * - state: + entrants + the published round's official rows (OfficialEntrantsView) - 3 unpublished / 4 published;
 * - analytics: + the puzzle statistics (distributions, top tens, times logged, difficulty - 4) + the history on the
 *   round's puzzles, the members' facts, the similar summary (3) = 10; pair/team rounds + the line-ups when a line-up is
 *   all public; warm or cold + who the record holders are now (1, when a visible puzzle has solvers);
 * - entrant (the round, its puzzles, this entry): the analytics' 7 for one entry + the similar solves and the recent
 *   form; warm or cold the earlier published rounds (1 per category kind of them) and GetPlayerPredictions' own per
 *   member without a cached prediction;
 * - predictions: GetPlayerPredictions' own per member without a cached prediction.
 */
final class AnalyticsStudioQueryBudgetTest extends WebTestCase
{
    use AnalyticsStudioTesting;
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, bool, int}>
     */
    public static function provideAnswers(): iterable
    {
        $groupA = '/analytics-studio/' . F::ROUND_GROUP_A;
        $groupB = '/analytics-studio/' . F::ROUND_GROUP_B;

        yield 'state, unpublished, cold' => [$groupB . '/state', false, 3];
        yield 'state, published, cold' => [$groupA . '/state', false, 4];
        yield 'state, within 2 s' => [$groupA . '/state', true, 2];
        yield 'analytics, cold' => [$groupB . '/analytics', false, 10];
        yield 'analytics, warm' => [$groupB . '/analytics', true, 3];
        // The Puzzle Sharks (Anna + Ben, both public here) have a line-up: + 1
        yield 'analytics, pairs, cold' => ['/analytics-studio/' . F::ROUND_PAIRS . '/analytics', false, 12];
        // Who the record holders are is read on every answer (PUZZLE_2000 has solvers; Group B's PUZZLE_5000 none)
        yield 'analytics, pairs, warm' => ['/analytics-studio/' . F::ROUND_PAIRS . '/analytics', true, 4];
        yield 'entrant, solo, cold' => [$groupB . '/entrant/' . F::ENTRY_B_HUGO, false, 17];
        // The earlier published rounds are read on every answer (Group A: one solo statement)
        yield 'entrant, warm' => [$groupB . '/entrant/' . F::ENTRY_B_HUGO, true, 4];
        yield 'predictions, 1 entry, cold' => [$groupB . '/predictions?entries=' . F::ENTRY_B_HUGO, false, 7];
        yield 'predictions, 4 entries, cold' => [$groupA . '/predictions?entries=' . implode(',', [F::ENTRY_A_ANNA, F::ENTRY_A_BEN, F::ENTRY_A_CARA, F::ENTRY_A_DAN]), false, 19];
        yield 'predictions, 4 entries, warm' => [$groupA . '/predictions?entries=' . implode(',', [F::ENTRY_A_ANNA, F::ENTRY_A_BEN, F::ENTRY_A_CARA, F::ENTRY_A_DAN]), true, 3];
    }

    #[DataProvider('provideAnswers')]
    public function testTheAnswerCost(string $url, bool $warm, int $statements): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        // Four analysable entrants in Group A for the compare set (Anna is PLAYER_ADMIN)
        foreach ([F::PARTICIPANT_BEN => PlayerFixture::PLAYER_REGULAR, F::PARTICIPANT_CARA => PlayerFixture::PLAYER_WITH_FAVORITES, F::PARTICIPANT_DAN => PlayerFixture::PLAYER_WITH_STRIPE] as $participant => $player) {
            $database->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', ['player' => $player, 'id' => $participant]);
        }
        $this->clearAnalyticsStudioCache();

        if ($warm) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
        }

        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        self::assertSame($statements, $this->queryCount($browser), $url . "\n" . implode("\n", $this->executedSql($browser)));
    }

    /**
     * The page (S3, §1.1): a data-free shell - the event (MapEntity) + GetAnalyticsStudioRound::bySlug() (the round, the
     * switcher's rounds, the visibility condition) = 2; an edition: the edition by its series' and its own slug + its
     * series (the draft check reads it) + the round = 3. A guest; the id-based organiser link: the round only = 1.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function provideShells(): iterable
    {
        yield 'event' => ['/en/events/results-cup/analytics-studio/group-a', 2];
        yield 'edition' => ['/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/analytics-studio/main-round', 3];
        yield 'organisers\' link by id' => ['/en/analytics-studio/' . F::ROUND_GROUP_A, 1];
    }

    #[DataProvider('provideShells')]
    public function testTheShellCost(string $url, int $statements): void
    {
        $browser = self::createClient();

        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertLessThan(400, $browser->getResponse()->getStatusCode(), $url);

        self::assertSame($statements, $this->queryCount($browser), $url . "\n" . implode("\n", $this->executedSql($browser)));
    }

    /**
     * Wall time of a cold analytics answer on the fixture round - printed, never asserted (machines differ).
     */
    public function testTheColdAnalyticsTime(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();

        $start = hrtime(true);
        $browser->request('GET', '/analytics-studio/' . F::ROUND_GROUP_A . '/analytics');
        $milliseconds = (hrtime(true) - $start) / 1e6;

        self::assertResponseIsSuccessful();
        fwrite(STDERR, sprintf("\nanalytics studio: cold analytics of the fixture round %.1f ms\n", $milliseconds));
    }
}
