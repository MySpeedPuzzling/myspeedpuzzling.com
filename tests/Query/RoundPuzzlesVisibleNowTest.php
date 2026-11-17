<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Results\EditionRoundPuzzle;
use SpeedPuzzling\Web\Results\RoundPuzzlesNow;
use SpeedPuzzling\Web\Results\VisibleRoundPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The one rule of what of a round's puzzles anybody may see now (R-review-and-plan.md §1.5) - the event pages and every
 * competition toolkit surface read it. CompetitionApiFixture's future round has a puzzle hidden entirely, one hidden by
 * its picture, a public one and two under the site-wide embargo (hide_until, hide_image_until); its past round one
 * secret puzzle long revealed.
 */
final class RoundPuzzlesVisibleNowTest extends KernelTestCase
{
    public function testBeforeTheRevealOnlyWhatIsPublicComesOut(): void
    {
        $now = self::now();
        $round = $this->query()->forRound(CompetitionApiFixture::ROUND_FUTURE, $now);

        self::assertSame(['API Hidden Image', 'API Platform Image Hidden', 'API Visible'], self::names($round));
        self::assertTrue($round->anyRevealed());

        $byName = self::byName($round);
        // "Image only": the name, never the picture
        self::assertNull($byName['API Hidden Image']->puzzleImage);
        self::assertTrue($byName['API Hidden Image']->imageHidden);
        // The puzzle's own hide_image_until: no picture, no ratio
        self::assertNull($byName['API Platform Image Hidden']->puzzleImage);
        self::assertNull($byName['API Platform Image Hidden']->puzzleImageRatio);
        self::assertTrue($byName['API Platform Image Hidden']->imageHidden);
        self::assertSame(CompetitionApiFixture::IMAGE_VISIBLE, $byName['API Visible']->puzzleImage);
        self::assertFalse($byName['API Visible']->imageHidden);

        // The next moment anything comes out: the round's automatic reveal, before the +30 days embargoes
        self::assertSame(RoundClock::milliseconds($this->automaticRevealOfTheFutureRound()), $round->nextRevealAtMs);
    }

    public function testNothingOfAHiddenPuzzleIsInTheAnswer(): void
    {
        $round = $this->query()->forRound(CompetitionApiFixture::ROUND_FUTURE, self::now());
        $answer = serialize($round);

        foreach (['API Hidden Entirely', CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY, CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY, 'API Platform Hidden', CompetitionApiFixture::IMAGE_PLATFORM_HIDDEN, CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, CompetitionApiFixture::IMAGE_HIDDEN_IMAGE, CompetitionApiFixture::IMAGE_PLATFORM_IMAGE_HIDDEN] as $secret) {
            self::assertStringNotContainsString($secret, $answer);
        }
    }

    public function testAtTheRevealMomentTheSecretPuzzlesComeOut(): void
    {
        $revealAt = $this->automaticRevealOfTheFutureRound();

        $before = $this->query()->forRound(CompetitionApiFixture::ROUND_FUTURE, $revealAt->modify('-1 second'));
        self::assertNotContains('API Hidden Entirely', self::names($before));

        $at = $this->query()->forRound(CompetitionApiFixture::ROUND_FUTURE, $revealAt);
        self::assertSame(['API Hidden Entirely', 'API Hidden Image', 'API Platform Image Hidden', 'API Visible'], self::names($at));
        self::assertSame(CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY, self::byName($at)['API Hidden Entirely']->puzzleImage);
        self::assertSame(CompetitionApiFixture::IMAGE_HIDDEN_IMAGE, self::byName($at)['API Hidden Image']->puzzleImage);
        self::assertFalse(self::byName($at)['API Hidden Image']->imageHidden);
        // Next: the site-wide embargoes (fixture: +30 days from the frozen now)
        self::assertSame(RoundClock::milliseconds(self::now()->modify('+30 days')), $at->nextRevealAtMs);
    }

    public function testAManualRevealHasNoMomentAndKeepsThePuzzleSecret(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round_puzzle SET reveal_mode = 'manual' WHERE round_id = :round AND hide_until_round_starts = true",
            ['round' => CompetitionApiFixture::ROUND_FUTURE],
        );

        // Long after the round: still secret, and no moment of it
        $round = $this->query()->forRound(CompetitionApiFixture::ROUND_FUTURE, self::now()->modify('+6 days'));

        self::assertSame(['API Hidden Image', 'API Platform Image Hidden', 'API Visible'], self::names($round));
        self::assertSame(RoundClock::milliseconds(self::now()->modify('+30 days')), $round->nextRevealAtMs);
    }

    public function testARoundWithEverythingOutHasNoNextMoment(): void
    {
        $round = $this->query()->forRound(CompetitionApiFixture::ROUND_PAST, self::now());

        self::assertSame(['API Past'], self::names($round));
        self::assertSame(CompetitionApiFixture::IMAGE_PAST, $round->puzzles[0]->puzzleImage);
        self::assertNull($round->nextRevealAtMs);
    }

    public function testEveryAskedRoundIsAnsweredInOneStatement(): void
    {
        /** @var DebugDataHolder $debugData */
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $unknown = '018d0006-0000-0000-0000-00000000dead';
        $rounds = $this->query()->forRounds([CompetitionApiFixture::ROUND_FUTURE, CompetitionApiFixture::ROUND_PAST, $unknown], self::now());

        self::assertSame([CompetitionApiFixture::ROUND_FUTURE, CompetitionApiFixture::ROUND_PAST, $unknown], array_keys($rounds));
        self::assertSame([], $rounds[$unknown]->puzzles);
        self::assertFalse($rounds[$unknown]->anyRevealed());
        self::assertNull($rounds[$unknown]->nextRevealAtMs);
        self::assertSame(1, array_sum(array_map(static fn (mixed $queries): int => is_array($queries) ? count($queries) : 0, $debugData->getData())));
        self::assertSame([], $this->query()->forRounds([], self::now()));
    }

    /**
     * The event and edition pages show exactly what the rule lets out.
     */
    public function testTheEventPagesShowWhatTheRuleLetsOut(): void
    {
        $now = self::now();
        $visible = $this->query()->forRounds([CompetitionApiFixture::ROUND_FUTURE, CompetitionApiFixture::ROUND_PAST], $now);
        $connection = self::getContainer()->get(Connection::class);

        foreach (new GetEditionRounds($connection, new MockClock($now))->forCompetition(CompetitionApiFixture::COMPETITION_API) as $round) {
            self::assertSame(
                array_map(static fn (VisibleRoundPuzzle $puzzle): array => [$puzzle->puzzleId, $puzzle->puzzleImage, $puzzle->imageHidden], $visible[$round->id]->puzzles),
                array_map(static fn (EditionRoundPuzzle $puzzle): array => [$puzzle->puzzleId, $puzzle->puzzleImage, $puzzle->imageHidden], $round->puzzles),
            );
        }
    }

    private function query(): RoundPuzzlesVisibleNow
    {
        return self::getContainer()->get(RoundPuzzlesVisibleNow::class);
    }

    private static function now(): DateTimeImmutable
    {
        return self::getContainer()->get('clock')->now();
    }

    private function automaticRevealOfTheFutureRound(): DateTimeImmutable
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT starts_at, reveal_delay_minutes FROM competition_round WHERE id = :id',
            ['id' => CompetitionApiFixture::ROUND_FUTURE],
        );
        assert(is_array($row) && is_string($row['starts_at']) && (is_int($row['reveal_delay_minutes']) || is_string($row['reveal_delay_minutes'])));

        return RoundPuzzleReveal::automaticRevealAt(new DateTimeImmutable($row['starts_at']), (int) $row['reveal_delay_minutes']);
    }

    /**
     * @return list<string>
     */
    private static function names(RoundPuzzlesNow $round): array
    {
        return array_map(static fn (VisibleRoundPuzzle $puzzle): string => $puzzle->puzzleName, $round->puzzles);
    }

    /**
     * @return array<string, VisibleRoundPuzzle>
     */
    private static function byName(RoundPuzzlesNow $round): array
    {
        $byName = [];
        foreach ($round->puzzles as $puzzle) {
            $byName[$puzzle->puzzleName] = $puzzle;
        }

        return $byName;
    }
}
