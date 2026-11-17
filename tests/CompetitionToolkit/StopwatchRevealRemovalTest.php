<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\CancelRoundStart;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\DeleteCompetitionSeries;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\ScheduleRoundStart;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use SpeedPuzzling\Web\Tests\Controller\InternalApi\InternalApiRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A countdown started with "Reveal the puzzles" writes its moment into the waiting rows (RoundPuzzleReveal::Stopwatch)
 * and into the puzzle's site-wide hide dates. Every writer that removes those rows before the moment - remove a round
 * puzzle, replace the list (incl. the internal API PUT), delete the round, the event or the series - takes the reveal back
 * first (RoundStopwatchReveal::withdrawBeforeRemoving()): with the rows gone the dates stay as they are, so they must say
 * "hidden", never the countdown's moment - no start happened (review of A1).
 *
 * Rounds without a secret puzzle keep the statements they ran before (pinned).
 */
final class StopwatchRevealRemovalTest extends WebTestCase
{
    use InternalApiRequests;
    use ReadsRoundAutomaticReveal;

    private const string ROUND = CompetitionApiFixture::ROUND_FUTURE;
    private const string COMPETITION = CompetitionApiFixture::COMPETITION_API;
    private const string NEVER = CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED;

    private KernelBrowser $browser;
    private MessageBusInterface $messageBus;
    private Connection $database;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->clock = self::getContainer()->get(ClockInterface::class);
    }

    public function testDeletingTheRoundKeepsThePuzzleHidden(): void
    {
        [$puzzleId, $moment] = $this->pendingCountdown();

        $this->dispatch(new DeleteCompetitionRound(self::ROUND, self::COMPETITION));

        self::assertSame(0, $this->rowsOfPuzzle($puzzleId));
        $this->assertStillHiddenAt($puzzleId, $moment);
    }

    public function testDeletingTheEventKeepsThePuzzleHidden(): void
    {
        [$puzzleId, $moment] = $this->pendingCountdown();

        $this->dispatch(new DeleteCompetition(self::COMPETITION));

        self::assertSame(0, $this->rowsOfPuzzle($puzzleId));
        $this->assertStillHiddenAt($puzzleId, $moment);
    }

    public function testDeletingTheSeriesKeepsThePuzzleHidden(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET series_id = :series WHERE id = :id',
            ['series' => CompetitionSeriesFixture::SERIES_EJJ, 'id' => self::COMPETITION],
        );
        [$puzzleId, $moment] = $this->pendingCountdown();

        $this->dispatch(new DeleteCompetitionSeries(CompetitionSeriesFixture::SERIES_EJJ));

        self::assertSame(0, $this->rowsOfPuzzle($puzzleId));
        $this->assertStillHiddenAt($puzzleId, $moment);
    }

    public function testRemovingThePuzzleKeepsItHiddenAndTheOtherRowsKeepTheirReveal(): void
    {
        [$puzzleId, $moment, $roundPuzzleId, $otherPuzzleId] = $this->pendingCountdown(twoPuzzles: true);

        $this->dispatch(new RemovePuzzleFromCompetitionRound($roundPuzzleId));

        self::assertSame(0, $this->rowsOfPuzzle($puzzleId));
        $this->assertStillHiddenAt($puzzleId, $moment);
        // The row staying in the round keeps the countdown's reveal
        self::assertSame($moment->format('Y-m-d H:i:s'), $this->hideDates($otherPuzzleId)['hide_until']);
        self::assertTrue($this->visibleOnTheRoundAt($otherPuzzleId, $moment));
    }

    public function testReplacingTheListKeepsTheRemovedPuzzleHidden(): void
    {
        [$puzzleId, $moment] = $this->pendingCountdown();

        $this->dispatch(new SetCompetitionRoundPuzzles(self::ROUND, $this->otherPuzzlesOfRound($puzzleId)));

        self::assertSame(0, $this->rowsOfPuzzle($puzzleId));
        $this->assertStillHiddenAt($puzzleId, $moment);
    }

    public function testReplacingTheListThroughTheInternalApiKeepsTheRemovedPuzzleHidden(): void
    {
        [$puzzleId, $moment] = $this->pendingCountdown();

        self::callInternalApi($this->browser, 'PUT', '/internal-api/rounds/' . self::ROUND . '/puzzles', [
            'puzzleIds' => $this->otherPuzzlesOfRound($puzzleId),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->rowsOfPuzzle($puzzleId));
        $this->assertStillHiddenAt($puzzleId, $moment);
    }

    /**
     * A reveal whose moment passed is out for good - a removal afterwards hides nothing again
     */
    public function testARevealAlreadyOutStaysOut(): void
    {
        [$puzzleId, $moment] = $this->pendingCountdown();
        $this->advanceTo($moment->modify('+1 minute'));

        $this->dispatch(new DeleteCompetitionRound(self::ROUND, self::COMPETITION));

        self::assertSame($moment->format('Y-m-d H:i:s'), $this->hideDates($puzzleId)['hide_until']);
        $this->browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful();
    }

    /**
     * Rounds without a secret puzzle run exactly the statements they ran before the fix (counted on the code before it)
     */
    public function testRoundsWithoutASecretPuzzleKeepTheirStatements(): void
    {
        $round = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;
        self::assertFalse($this->database->fetchOne(
            'SELECT 1 FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = :id AND crp.hide_until_round_starts',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        ));

        $counts = [];
        $counts['schedule'] = $this->statementsOf(new ScheduleRoundStart($round, $this->wholeSecond('+10 minutes'), PlayerFixture::PLAYER_ADMIN));
        $counts['cancel'] = $this->statementsOf(new CancelRoundStart($round, PlayerFixture::PLAYER_ADMIN));
        $counts['start'] = $this->statementsOf(new StartRoundStopwatch($round));
        $counts['stop'] = $this->statementsOf(new StopRoundStopwatch($round));

        /** @var list<string> $rowIds */
        $rowIds = $this->database->fetchFirstColumn('SELECT id FROM competition_round_puzzle WHERE round_id = :id ORDER BY id', ['id' => $round]);
        self::assertGreaterThanOrEqual(2, count($rowIds));
        $counts['remove'] = $this->statementsOf(new RemovePuzzleFromCompetitionRound($rowIds[0]));
        $counts['set'] = $this->statementsOf(new SetCompetitionRoundPuzzles($round, []));
        $counts['delete round'] = $this->statementsOf(new DeleteCompetitionRound($round, CompetitionFixture::COMPETITION_WJPC_2024));
        $counts['delete event'] = $this->statementsOf(new DeleteCompetition(CompetitionFixture::COMPETITION_WJPC_2024));

        self::assertSame([
            'schedule' => 8,
            'cancel' => 7,
            'start' => 8,
            'stop' => 8,
            'remove' => 20,
            'set' => 16,
            // 20 + the read of the event's standings, which lose the round (competition toolkit A6)
            'delete round' => 21,
            // 16 + the read of its displays' uploaded pictures, deleted after the commit (D7)
            'delete event' => 17,
        ], $counts);
    }

    /**
     * A secret puzzle waiting for the stopwatch, and a countdown with the tick: hidden until its moment, out at it.
     *
     * @return array{string, DateTimeImmutable, string, string} puzzle id, the countdown's moment, its round puzzle id, the
     *     second puzzle's id ('' without one)
     */
    private function pendingCountdown(bool $twoPuzzles = false): array
    {
        [$roundPuzzleId, $puzzleId] = $this->stopwatchSecretPuzzle('One');
        $otherPuzzleId = $twoPuzzles ? $this->stopwatchSecretPuzzle('Two')[1] : '';
        $moment = $this->wholeSecond('+10 minutes');

        $this->dispatch(new ScheduleRoundStart(
            self::ROUND,
            $moment,
            PlayerFixture::PLAYER_ADMIN,
            revealPuzzles: true,
            revealListHash: $this->shownHash(),
        ));

        self::assertSame($moment->format('Y-m-d H:i:s'), $this->hideDates($puzzleId)['hide_until']);
        self::assertSame($moment->format('Y-m-d H:i:s'), $this->hideDates($puzzleId)['hide_image_until']);
        self::assertTrue($this->visibleOnTheRoundAt($puzzleId, $moment), 'the countdown reveals it at its moment');

        return [$puzzleId, $moment, $roundPuzzleId, $otherPuzzleId];
    }

    private function assertStillHiddenAt(string $puzzleId, DateTimeImmutable $moment): void
    {
        self::assertSame(['hide_until' => self::NEVER, 'hide_image_until' => self::NEVER], $this->hideDates($puzzleId));
        self::assertFalse($this->visibleOnTheRoundAt($puzzleId, $moment));

        $this->advanceTo($moment->modify('+1 minute'));
        self::assertFalse($this->visibleOnTheRoundAt($puzzleId, $this->clock->now()));

        // A guest at (after) the old moment: no puzzle page
        $this->browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{string, string} round puzzle id, puzzle id
     */
    private function stopwatchSecretPuzzle(string $name): array
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: self::ROUND,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Countdown Secret ' . $name . ' ' . $roundPuzzleId->toString(),
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            shownAutomaticRevealAt: self::automaticRevealOf(self::ROUND),
        ));
        $this->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));

        $puzzleId = $this->database->fetchOne('SELECT puzzle_id FROM competition_round_puzzle WHERE id = :id', ['id' => $roundPuzzleId->toString()]);
        self::assertIsString($puzzleId);

        return [$roundPuzzleId->toString(), $puzzleId];
    }

    /**
     * What the control page shows and sends with the tick
     */
    private function shownHash(): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $round = $entityManager->find(CompetitionRound::class, self::ROUND);
        self::assertNotNull($round);
        $revealed = self::getContainer()->get(SecretRevealPreview::class)->byStopwatchStart($round);
        self::assertNotSame([], $revealed);
        $hash = SecretRevealPreview::stopwatchStartHash($revealed, $round);
        $entityManager->clear();

        return $hash;
    }

    /**
     * @return list<string>
     */
    private function otherPuzzlesOfRound(string $puzzleId): array
    {
        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->database->fetchFirstColumn(
            'SELECT puzzle_id FROM competition_round_puzzle WHERE round_id = :round AND puzzle_id <> :puzzle ORDER BY puzzle_id',
            ['round' => self::ROUND, 'puzzle' => $puzzleId],
        );

        return $puzzleIds;
    }

    private function visibleOnTheRoundAt(string $puzzleId, DateTimeImmutable $moment): bool
    {
        foreach (self::getContainer()->get(RoundPuzzlesVisibleNow::class)->forRound(self::ROUND, $moment)->puzzles as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{hide_until: null|string, hide_image_until: null|string}
     */
    private function hideDates(string $puzzleId): array
    {
        /** @var array{hide_until: null|string, hide_image_until: null|string} $dates */
        $dates = $this->database->fetchAssociative('SELECT hide_until, hide_image_until FROM puzzle WHERE id = :id', ['id' => $puzzleId]);

        return $dates;
    }

    private function rowsOfPuzzle(string $puzzleId): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM competition_round_puzzle WHERE puzzle_id = :id', ['id' => $puzzleId]);
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function statementsOf(object $message): int
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $this->dispatch($message);

        $statements = $debugDataHolder->getData()['default'] ?? [];
        self::assertIsArray($statements);

        return count($statements);
    }

    /**
     * The test container's clock is a MockClock (config/services_test.php)
     */
    private function advanceTo(DateTimeImmutable $moment): void
    {
        $seconds = $moment->getTimestamp() - $this->clock->now()->getTimestamp();
        self::assertGreaterThan(0, $seconds);
        self::assertInstanceOf(MockClock::class, $this->clock);
        $this->clock->sleep($seconds);
    }

    private function wholeSecond(string $modifier): DateTimeImmutable
    {
        $moment = $this->clock->now()->modify($modifier);

        return $moment->setTimestamp($moment->getTimestamp());
    }

    private function dispatch(object $message): void
    {
        $this->messageBus->dispatch($message);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
