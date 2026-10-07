<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspicious;
use SpeedPuzzling\Web\Query\GetSuspiciousTimesOverview;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/suspicious-time-review.md, "Moderator queue": the Numbers and Decision log tabs.
 */
final class GetSuspiciousTimesOverviewTest extends KernelTestCase
{
    private GetSuspiciousTimesOverview $overview;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->overview = self::getContainer()->get(GetSuspiciousTimesOverview::class);
    }

    public function testNumbersByVersionReasonAndWhatPlayersDid(): void
    {
        $numbers = $this->overview->numbers();

        // Pending fast + Mia's marked one (raised fast by v1), the pending slow one
        self::assertSame([
            ['version' => 1, 'direction' => 'fast', 'raised' => 2, 'pending' => 1, 'marked' => 1, 'trusted' => 0, 'corrected' => 0, 'gone' => 0],
            ['version' => 1, 'direction' => 'slow', 'raised' => 1, 'pending' => 1, 'marked' => 0, 'trusted' => 0, 'corrected' => 0, 'gone' => 0],
        ], $numbers->byVersion);

        $byCode = array_column($numbers->byReason, null, 'code');
        self::assertSame(['code' => 'faster_than_usual', 'trigger' => true, 'shown_to_player' => true, 'raised' => 2, 'pending' => 1, 'marked' => 1, 'trusted' => 0, 'precision' => 100], $byCode['faster_than_usual']);
        self::assertSame(1, $byCode['minutes_in_hours_box']['raised']);
        self::assertNull($byCode['minutes_in_hours_box']['precision']);
        self::assertSame(0, $byCode['new_player']['raised']);

        self::assertSame([['via' => 'run', 'notices' => 1, 'fixed' => 0, 'says_correct' => 0, 'left_as_is' => 0, 'no_reaction' => 1, 'answered_trusted' => 0, 'answered_kept' => 0]], $numbers->players);
        self::assertSame(0, $numbers->decisions['marked']);
    }

    public function testTheLogShowsDecisionsNewestFirst(): void
    {
        $time = self::getContainer()->get(PuzzleSolvingTimeRepository::class)->get(SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MarkSolvingTimeSuspicious(
            SuspiciousTimesFixture::CASE_PENDING_FAST,
            PlayerFixture::PLAYER_ADMIN,
            ['hours_left_out'],
            'Hours?',
            SuspicionFingerprint::ofTime($time),
        ));

        $log = $this->overview->log(1);

        self::assertCount(1, $log);
        self::assertSame(SuspiciousTimeDecisionKind::Marked, $log[0]->decision);
        self::assertSame('Needs verification', $log[0]->label());
        self::assertSame('Harbour Lights', $log[0]->puzzleName);
        self::assertSame(4000, $log[0]->piecesCount);
        self::assertSame(SuspiciousTimesFixture::STEADY_FAST_SECONDS, $log[0]->seconds);
        self::assertTrue($log[0]->timeExists);
        self::assertTrue($log[0]->puzzleExists);
        self::assertSame('Sam Steady', $log[0]->trackerName);
        self::assertSame('Admin User', $log[0]->decidedByName);
        self::assertSame('Hours?', $log[0]->note);
        self::assertCount(1, $log[0]->reasonsShown);
        self::assertFalse($log[0]->puzzleSecret);
        self::assertSame(1, $this->overview->numbers()->decisions['marked']);

        // Harbour Lights becomes a competition's secret puzzle: the log keeps the decision, not what would give it away
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = NOW() + INTERVAL '30 days' WHERE id = :id",
            ['id' => SuspiciousTimesFixture::PUZZLE_HARBOUR],
        );

        $log = $this->overview->log(1);
        self::assertTrue($log[0]->puzzleSecret);
        self::assertNull($log[0]->puzzleName);
        self::assertNull($log[0]->piecesCount);
        self::assertSame([], $log[0]->reasonsShown);
    }
}
