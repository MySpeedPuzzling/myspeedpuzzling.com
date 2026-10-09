<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseNotFound;
use SpeedPuzzling\Web\Message\ConfirmDuplicateIsReal;
use SpeedPuzzling\Web\Message\KeepDuplicateCopy;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\DuplicateResolvedVia;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Keep this one" / "Delete my copy" / "Both are real" (docs/features/duplicate-results.md, "Review page").
 * The fixtures come with Dana Twin's four open cases (stored by the detection at save time) and Tom Twin's
 * case of the pair they both saved.
 */
final class DuplicateCaseActionsTest extends KernelTestCase
{
    private const string DANA = DuplicateResultsFixture::PLAYER_TWINS;
    private const string TOM = DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testKeepingACopyDeletesTheOtherAndTakesOverWhatOnlyItHad(): void
    {
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET comment = 'Second copy', finished_puzzle_photo = 'players/twins/photo.jpg', first_attempt = true WHERE id = :id",
            ['id' => DuplicateResultsFixture::TIME_STRONG_B],
        );
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);

        $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_STRONG_A, self::DANA));

        self::assertFalse($this->exists(DuplicateResultsFixture::TIME_STRONG_B));

        /** @var array{comment: null|string, finished_puzzle_photo: null|string, first_attempt: bool} $kept */
        $kept = $this->database->fetchAssociative(
            'SELECT comment, finished_puzzle_photo, first_attempt FROM puzzle_solving_time WHERE id = :id',
            ['id' => DuplicateResultsFixture::TIME_STRONG_A],
        );
        self::assertSame('Second copy', $kept['comment']);
        self::assertSame('players/twins/photo.jpg', $kept['finished_puzzle_photo']);
        self::assertTrue($kept['first_attempt']);

        self::assertSame(['copy_deleted', 'review_page'], $this->statusOf($caseId));
    }

    /**
     * P10 (docs/features/events-page/high-frequency-series.md): the copy's whole event link moves - its series pick,
     * the edition it was matched to and how - to a kept copy without one.
     */
    public function testKeepingACopyTakesOverTheOthersSeriesPickWhole(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $series = $scenario->series();
        $edition = $scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET competition_series_id = :series, competition_id = :edition, series_edition_match = 'date' WHERE id = :id",
            ['series' => $series, 'edition' => $edition, 'id' => DuplicateResultsFixture::TIME_STRONG_B],
        );
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);

        $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_STRONG_A, self::DANA));

        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => $series, 'series_edition_match' => 'date', 'competition_round_id' => null],
            $scenario->link(DuplicateResultsFixture::TIME_STRONG_A),
        );
    }

    /**
     * P10: a kept copy with an explicit edition keeps it - another series' pick never mixes into it.
     */
    public function testAKeptExplicitLinkDoesNotTakeASeriesPick(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $explicit = $scenario->edition($scenario->series('Moonlit Puzzle Sprint'), 'Sprint No. 1', '2026-03-02');
        $series = $scenario->series();
        $this->database->executeStatement('UPDATE puzzle_solving_time SET competition_id = :edition WHERE id = :id', ['edition' => $explicit, 'id' => DuplicateResultsFixture::TIME_STRONG_A]);
        $this->database->executeStatement('UPDATE puzzle_solving_time SET competition_series_id = :series WHERE id = :id', ['series' => $series, 'id' => DuplicateResultsFixture::TIME_STRONG_B]);
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);

        $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_STRONG_A, self::DANA));

        self::assertSame(
            ['competition_id' => $explicit, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $scenario->link(DuplicateResultsFixture::TIME_STRONG_A),
        );
    }

    public function testKeptCopyKeepsItsOwnComment(): void
    {
        $this->database->executeStatement("UPDATE puzzle_solving_time SET comment = 'Mine' WHERE id = :id", ['id' => DuplicateResultsFixture::TIME_STRONG_A]);
        $this->database->executeStatement("UPDATE puzzle_solving_time SET comment = 'Other' WHERE id = :id", ['id' => DuplicateResultsFixture::TIME_STRONG_B]);
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);

        $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_STRONG_A, self::DANA, DuplicateResolvedVia::Recap));

        self::assertSame('Mine', $this->database->fetchOne('SELECT comment FROM puzzle_solving_time WHERE id = :id', ['id' => DuplicateResultsFixture::TIME_STRONG_A]));
        self::assertSame(['copy_deleted', 'recap'], $this->statusOf($caseId));
    }

    public function testOnlyTheTrackerCanDeleteACopy(): void
    {
        // The pair both saved: keeping her own copy would delete Tom's
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_TEAMMATE_A);

        try {
            $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_TEAMMATE_A, self::DANA));
            self::fail('Deleting somebody else\'s copy must be refused');
        } catch (CanNotModifyOtherPlayersTime) {
        }

        self::assertTrue($this->exists(DuplicateResultsFixture::TIME_TEAMMATE_B));
        self::assertSame(['open', null], $this->statusOf($caseId));
    }

    public function testTheFirstDeletionOfATeammateCopyClosesItForBoth(): void
    {
        $danasCase = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_TEAMMATE_A);
        $tomsCase = $this->caseId(self::TOM, DuplicateResultsFixture::TIME_TEAMMATE_A);

        // "Delete my copy": Dana keeps Tom's copy
        $this->messageBus->dispatch(new KeepDuplicateCopy($danasCase, DuplicateResultsFixture::TIME_TEAMMATE_B, self::DANA));

        self::assertFalse($this->exists(DuplicateResultsFixture::TIME_TEAMMATE_A));
        self::assertSame(['copy_deleted', 'review_page'], $this->statusOf($danasCase));
        self::assertSame(['copy_deleted', 'review_page'], $this->statusOf($tomsCase));
    }

    public function testSomebodyElsesCaseDoesNotExistForThePlayer(): void
    {
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);

        try {
            $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_STRONG_A, self::TOM));
            self::fail('Another player\'s case must be refused');
        } catch (DuplicateCaseNotFound) {
        }

        try {
            $this->messageBus->dispatch(new ConfirmDuplicateIsReal($caseId, self::TOM));
            self::fail('Another player\'s case must be refused');
        } catch (DuplicateCaseNotFound) {
        }

        self::assertTrue($this->exists(DuplicateResultsFixture::TIME_STRONG_B));
        self::assertSame(['open', null], $this->statusOf($caseId));
    }

    public function testACopyOutsideTheCaseCannotBeKept(): void
    {
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);

        $this->expectException(DuplicateCaseNotFound::class);

        $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_PRACTICE_A, self::DANA));
    }

    public function testBothRealClosesTheCaseOfThatPersonOnly(): void
    {
        $danasCase = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_TEAMMATE_A);
        $tomsCase = $this->caseId(self::TOM, DuplicateResultsFixture::TIME_TEAMMATE_A);

        $this->messageBus->dispatch(new ConfirmDuplicateIsReal($tomsCase, self::TOM));

        self::assertSame(['both_real', 'review_page'], $this->statusOf($tomsCase));
        self::assertSame(['open', null], $this->statusOf($danasCase));
        self::assertTrue($this->exists(DuplicateResultsFixture::TIME_TEAMMATE_A));
        self::assertTrue($this->exists(DuplicateResultsFixture::TIME_TEAMMATE_B));
    }

    public function testADecidedCaseAnsweredAgainSaysItChanged(): void
    {
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_PRACTICE_A);
        $this->messageBus->dispatch(new ConfirmDuplicateIsReal($caseId, self::DANA));

        self::assertInstanceOf(DuplicateCaseChanged::class, $this->refusalOf(fn () => $this->messageBus->dispatch(new ConfirmDuplicateIsReal($caseId, self::DANA))));
        self::assertInstanceOf(DuplicateCaseChanged::class, $this->refusalOf(fn () => $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_PRACTICE_A, self::DANA))));
        self::assertTrue($this->exists(DuplicateResultsFixture::TIME_PRACTICE_B));
    }

    public function testNothingIsDeletedWhenTheKeptCopyIsGone(): void
    {
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE id = :id', ['id' => DuplicateResultsFixture::TIME_STRONG_A]);

        self::assertInstanceOf(DuplicateCaseChanged::class, $this->refusalOf(fn () => $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, DuplicateResultsFixture::TIME_STRONG_A, self::DANA))));
        self::assertTrue($this->exists(DuplicateResultsFixture::TIME_STRONG_B));
    }

    private function caseId(string $playerId, string $timeAId): string
    {
        $caseId = $this->database->fetchOne(
            'SELECT id FROM result_duplicate_case WHERE player_id = :playerId AND time_a_id = :timeAId',
            ['playerId' => $playerId, 'timeAId' => $timeAId],
        );
        assert(is_string($caseId));

        return $caseId;
    }

    /**
     * @return array{0: string, 1: null|string} status, resolved_via
     */
    private function statusOf(string $caseId): array
    {
        /** @var array{status: string, resolved_via: null|string} $row */
        $row = $this->database->fetchAssociative('SELECT status, resolved_via FROM result_duplicate_case WHERE id = :id', ['id' => $caseId]);

        return [$row['status'], $row['resolved_via']];
    }

    private function exists(string $timeId): bool
    {
        return $this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]) !== false;
    }

    private function refusalOf(\Closure $dispatch): null|\Throwable
    {
        try {
            $dispatch();
        } catch (HandlerFailedException $exception) {
            return $exception->getPrevious();
        }

        self::fail('The action was expected to be refused');
    }
}
