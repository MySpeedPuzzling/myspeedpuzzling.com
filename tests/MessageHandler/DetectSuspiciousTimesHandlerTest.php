<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\DetectSuspiciousTimes;
use SpeedPuzzling\Web\Message\SetPuzzleSlowThreshold;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Results\SuspiciousTimeRaisedRow;
use SpeedPuzzling\Web\Results\SuspiciousTimeScanSummary;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\HoldsSuspiciousTimeCaseLock;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/suspicious-time-review.md, "Detection" and "Checks and versions" - on SuspiciousTimesFixture.
 */
final class DetectSuspiciousTimesHandlerTest extends KernelTestCase
{
    use ClonesSolvingTimes;
    use HoldsSuspiciousTimeCaseLock;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFirstScanOpensCasesForTheRaisedTimes(): void
    {
        $summary = $this->scan();

        self::assertFalse($summary->dryRun);
        self::assertGreaterThanOrEqual(3, $summary->newCases);
        self::assertGreaterThanOrEqual(2, $summary->refreshedCases);

        // Pace on her 1600s, the 3000-piece edition - the 1600-piece one fits
        $edition = $this->caseOf(SuspiciousTimesFixture::TIME_EDITION_FAST);
        self::assertSame(['pending', 'detector', 'fast', 'strong', 'pace'], [$edition['status'], $edition['origin'], $edition['direction'], $edition['tier'], $edition['expected_source']]);
        self::assertSame(['faster_than_usual', 'hours_left_out', 'other_edition'], $this->codes($edition['reasons']));
        self::assertSame(SuspiciousTimesFixture::PUZZLE_LIGHTHOUSE_1600, $this->reason($edition['reasons'], 'other_edition')['puzzle_id']);
        self::assertSame(SuspiciousTimeClassifier::VERSION, $edition['detector_version']);

        // A new player, no expectation: beyond the community, with every hint of a group
        $group = $this->caseOf(SuspiciousTimesFixture::TIME_GROUP_SOLO);
        self::assertSame(['pending', 'fast', 'strong', null], [$group['status'], $group['direction'], $group['tier'], $group['expected_source']]);
        self::assertSame(['beyond_known_pace', 'teammates_saved_group', 'comment_mentions_group', 'new_player', 'confirmed_while_saving'], $this->codes($group['reasons']));
        self::assertSame(SuspiciousTimesFixture::TIME_PARTNERS_PAIR, $this->reason($group['reasons'], 'teammates_saved_group')['time_id']);

        // A pair result below the community's slow floor
        $pair = $this->caseOf(SuspiciousTimesFixture::TIME_SLOW_PAIR);
        self::assertSame(['pending', 'slow', 'strong'], [$pair['status'], $pair['direction'], $pair['tier']]);
        self::assertSame(['below_slow_floor', 'includes_breaks'], $this->codes($pair['reasons']));

        // The fixture's pending cases, checked again and still raised
        $fast = $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::assertSame(['pending', 'fast', 'baseline', $this->baselineOf(SuspiciousTimesFixture::PLAYER_STEADY, 4000)], [$fast['status'], $fast['direction'], $fast['expected_source'], $fast['expected_seconds']]);
        self::assertSame(['faster_than_usual', 'hours_left_out'], $this->codes($fast['reasons']));
        self::assertSame(SuspiciousTimesFixture::STEADY_FAST_SECONDS + 5 * 3600, $this->reason($fast['reasons'], 'hours_left_out')['suggested']);
        self::assertGreaterThan($fast['detected_at'], $fast['last_checked_at']);

        $typo = $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_TYPO);
        self::assertSame(['pending', 'slow', 'prediction'], [$typo['status'], $typo['direction'], $typo['expected_source']]);
        self::assertSame(['slower_than_predicted', 'minutes_in_hours_box', 'includes_breaks'], $this->codes($typo['reasons']));
        self::assertSame(2948, $this->reason($typo['reasons'], 'minutes_in_hours_box')['suggested']);

        // Every checked time has a check of this version, of exactly this entry
        $check = $this->checkOf(SuspiciousTimesFixture::TIMES_STEADY_HISTORY[0]);
        self::assertSame(['clear', SuspiciousTimeClassifier::VERSION], [$check['outcome'], $check['version']]);
        self::assertSame($this->fingerprintOf(SuspiciousTimesFixture::TIMES_STEADY_HISTORY[0]), $check['fingerprint']);
        self::assertSame('raised', $this->checkOf(SuspiciousTimesFixture::TIME_SLOW_PAIR)['outcome']);
        self::assertSame('clear', $this->checkOf(SuspiciousTimesFixture::TIME_PARTNERS_PAIR)['outcome'], 'A fast pair is never judged as fast');

        // A flagged time is not classified - its case is the reconciliation's
        self::assertFalse($this->checkOrFalse(SuspiciousTimesFixture::TIME_MARKED));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function editionsNobodyMayRead(): iterable
    {
        // A puzzle created for a round: unapproved and hidden until the round starts
        yield 'kept secret by a competition' => ["UPDATE puzzle SET approved = false, hide_until = NOW() + INTERVAL '30 days' WHERE id = :id"];
        // ... its picture hidden is enough - the strict reading of PuzzleSecrecy
        yield 'a competition puzzle with its picture hidden' => ["UPDATE puzzle SET approved = false, hide_image_until = NOW() + INTERVAL '30 days' WHERE id = :id"];
        // An approved placeholder hidden by hand (Ravensburger Puzzle Month)
        yield 'hidden' => ["UPDATE puzzle SET hide_until = NOW() + INTERVAL '30 days' WHERE id = :id"];
    }

    #[DataProvider('editionsNobodyMayRead')]
    public function testAnotherEditionIsNeverAPuzzleKeptSecretOrHidden(string $hide): void
    {
        $this->database->executeStatement($hide, ['id' => SuspiciousTimesFixture::PUZZLE_LIGHTHOUSE_1600]);

        $this->scan();

        // Still raised against her pace, but no reason names the 1600-piece edition
        $edition = $this->caseOf(SuspiciousTimesFixture::TIME_EDITION_FAST);
        self::assertSame(['pending', 'fast', 'pace'], [$edition['status'], $edition['direction'], $edition['expected_source']]);
        self::assertSame(['faster_than_usual', 'hours_left_out'], $this->codes($edition['reasons']));
    }

    /**
     * H12 scenario 14 (docs/features/events-page/high-frequency-series.md): the scan reads no event column - a
     * series-level time (a series pick no edition holds) is judged exactly like the same time without an event
     */
    public function testASeriesLevelTimeIsJudgedLikeAnyOther(): void
    {
        $seriesId = new SeriesEditionScenario(self::getContainer())->series();
        // The fixture's time made a series pick of a series without editions - what a pick stays when no edition is found
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_series_id = :series WHERE id = :id',
            ['series' => $seriesId, 'id' => SuspiciousTimesFixture::TIME_EDITION_FAST],
        );

        $this->scan();

        $edition = $this->caseOf(SuspiciousTimesFixture::TIME_EDITION_FAST);
        self::assertSame(['pending', 'detector', 'fast', 'strong', 'pace'], [$edition['status'], $edition['origin'], $edition['direction'], $edition['tier'], $edition['expected_source']]);
        self::assertSame(['faster_than_usual', 'hours_left_out', 'other_edition'], $this->codes($edition['reasons']));
        self::assertSame(
            [null, $seriesId],
            array_values((array) $this->database->fetchAssociative(
                'SELECT competition_id, competition_series_id FROM puzzle_solving_time WHERE id = :id',
                ['id' => SuspiciousTimesFixture::TIME_EDITION_FAST],
            )),
            'The scan leaves the link alone',
        );
    }

    public function testAGroupResultOfSomebodyTheTrackerBlockedIsNoEvidence(): void
    {
        // Gina blocked Fay - the pair Pat saved with Fay that day must not end up in a reason Gina reads
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (gen_random_uuid(), :blocker, :blocked, NOW(), 'self')",
            ['blocker' => SuspiciousTimesFixture::PLAYER_GROUP, 'blocked' => SuspiciousTimesFixture::PLAYER_FLAGGED],
        );

        $this->scan();

        $group = $this->caseOf(SuspiciousTimesFixture::TIME_GROUP_SOLO);
        self::assertSame(['beyond_known_pace', 'comment_mentions_group', 'new_player', 'confirmed_while_saving'], $this->codes($group['reasons']));
    }

    public function testFlagSetBySqlBecomesAMarkedCaseAndStatisticsFollow(): void
    {
        self::assertFalse($this->caseOrFalse(SuspiciousTimesFixture::TIME_SQL_FLAGGED));

        // Cove Study 3 - solved once
        $historyTime = SuspiciousTimesFixture::TIMES_EDITION_HISTORY[2];
        self::assertSame(1, $this->solvedTimesCountOf($historyTime));
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => $historyTime]);

        $summary = $this->scan();

        self::assertSame(2, $summary->markedOutsideApp);

        $case = $this->caseOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED);
        self::assertSame(['marked', 'manual', null, '[]', null], [$case['status'], $case['origin'], $case['direction'], $case['reasons_shown'], $case['decided_by_id']]);
        self::assertNotNull($case['marked_at']);
        self::assertSame($this->fingerprintOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED), $case['fingerprint']);
        self::assertSame(['marked_outside_app'], $this->decisionsOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED));

        // The statistics follow the flag (PuzzleSolvingTimeModified)
        self::assertSame(0, $this->solvedTimesCountOf($historyTime));
        self::assertSame('marked', $this->caseOf($historyTime)['status']);

        // Nothing left to reconcile
        $this->clearEntityManager();
        self::assertSame(0, $this->scan()->markedOutsideApp);
    }

    public function testFlagClearedBySqlTrustsTheCase(): void
    {
        self::assertSame(0, $this->solvedTimesCountOf(SuspiciousTimesFixture::TIME_MARKED));
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = false WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]);

        $summary = $this->scan();

        self::assertSame(1, $summary->unmarkedOutsideApp);

        $case = $this->caseOf(SuspiciousTimesFixture::TIME_MARKED);
        self::assertSame(['trusted', null], [$case['status'], $case['decided_by_id']]);
        self::assertSame(['unmarked_outside_app'], $this->decisionsOf(SuspiciousTimesFixture::TIME_MARKED));
        self::assertSame(1, $this->solvedTimesCountOf(SuspiciousTimesFixture::TIME_MARKED));

        // Trusted for this very entry - never checked again
        $this->clearEntityManager();
        $this->scan();
        self::assertFalse($this->checkOrFalse(SuspiciousTimesFixture::TIME_MARKED));
        self::assertSame('trusted', $this->caseOf(SuspiciousTimesFixture::TIME_MARKED)['status']);
    }

    public function testAMarkWhoseEntryChangedOutsideTheEditFormIsUnmarkedWhenTheNewEntryIsClear(): void
    {
        $this->markSamsFastTime();

        // Repaired by SQL to his usual 9:30:00 - the hours box was left empty
        $this->setSeconds(SuspiciousTimesFixture::TIME_STEADY_FAST, 34200);
        $summary = $this->scan();

        self::assertSame([1, 0], [$summary->changedMarksUnmarked, $summary->changedMarksToModerators]);
        $case = $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::assertSame(['corrected', null], [$case['status'], $case['player_edited_at']]);
        self::assertSame($this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_FAST), $case['fingerprint']);
        self::assertFalse($this->database->fetchOne('SELECT suspicious FROM puzzle_solving_time WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_STEADY_FAST]));
        self::assertSame(['marked', 'corrected_automatically'], $this->decisionsOf(SuspiciousTimesFixture::TIME_STEADY_FAST));

        $this->clearEntityManager();
        self::assertSame([0, 0], [$this->scan()->changedMarksUnmarked, $this->scan()->changedMarksToModerators]);
    }

    public function testAMarkWhoseEntryChangedOutsideTheEditFormAndStillLooksOffGoesBackToTheModerators(): void
    {
        // Silent Pier's piece count fixed from 520 to 1600: Mia's 21:40 is now far beyond anybody's pace
        $before = $this->caseOf(SuspiciousTimesFixture::TIME_MARKED);
        $this->database->executeStatement('UPDATE puzzle SET pieces_count = 1600 WHERE id = :id', ['id' => SuspiciousTimesFixture::PUZZLE_SILENT_PIER]);

        $summary = $this->scan();

        self::assertSame([0, 1], [$summary->changedMarksUnmarked, $summary->changedMarksToModerators]);
        $case = $this->caseOf(SuspiciousTimesFixture::TIME_MARKED);
        self::assertSame(['marked', $before['marked_at']], [$case['status'], $case['marked_at']], 'The same mark - nobody is told again');
        self::assertNotNull($case['player_edited_at'], 'On the "Player replied" tab');
        self::assertSame($this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED), $case['fingerprint']);
        self::assertSame('beyond_known_pace', $this->codes($case['reasons'])[0], 'The card shows what the detector says about the new entry');
        self::assertTrue($this->database->fetchOne('SELECT suspicious FROM puzzle_solving_time WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]));
        self::assertSame([], $this->decisionsOf(SuspiciousTimesFixture::TIME_MARKED));

        // Judged once per entry
        $this->clearEntityManager();
        self::assertSame(0, $this->scan()->changedMarksToModerators);
    }

    public function testAManualMarkWhoseEntryChangedNeverUnmarksItself(): void
    {
        // The pair flagged by SQL: a marked case of origin manual
        $this->scan();
        $this->database->executeStatement('UPDATE puzzle_solving_time SET seconds_to_solve = 3000 WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_SQL_FLAGGED]);
        $this->clearEntityManager();

        $summary = $this->scan();

        self::assertSame([0, 1], [$summary->changedMarksUnmarked, $summary->changedMarksToModerators]);
        $case = $this->caseOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED);
        self::assertSame(['marked', 'manual'], [$case['status'], $case['origin']]);
        self::assertNotNull($case['player_edited_at']);
    }

    public function testTheScanWaitsForADecisionInFlightBeforeItWritesACase(): void
    {
        // A moderator's "Mark" of Sam's fast time holds its case, not committed yet
        $otherRequest = $this->holdCaseInAnotherRequest($this->database, SuspiciousTimesFixture::CASE_PENDING_FAST);

        $waited = null;

        try {
            $this->scan();
        } catch (\Throwable $exception) {
            $waited = self::statementThatWaited($exception);
        } finally {
            $otherRequest->rollBack();
        }

        // The scan reads the case again under its lock before refreshing it - it never writes over the decision
        self::assertIsString($waited, 'The scan must wait for the case');
        self::assertStringStartsWith('SELECT', $waited);
        self::assertStringContainsString('suspicious_time_case', $waited);
        self::assertStringContainsString('FOR UPDATE', $waited);
    }

    public function testCaseNoLongerRaisedIsGoneAndReopensWhenRaisedAgain(): void
    {
        $this->setSeconds(SuspiciousTimesFixture::TIME_STEADY_FAST, 34000);

        $summary = $this->scan();

        self::assertGreaterThanOrEqual(1, $summary->goneCases);
        self::assertSame('gone', $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_FAST)['status']);

        $this->setSeconds(SuspiciousTimesFixture::TIME_STEADY_FAST, 9500);
        $this->clearEntityManager();
        $again = $this->scan();

        self::assertSame(1, $again->reopenedCases);
        $case = $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::assertSame(['pending', SuspiciousTimesFixture::CASE_PENDING_FAST], [$case['status'], $case['id']]);
    }

    public function testAHardPuzzlesThresholdClosesItsSlowCasesAtOnceAndTouchesNothingElse(): void
    {
        $this->scan();
        $this->clearEntityManager();
        $this->checkedAnHourAgo();
        $fastCheckedAt = $this->checkOf(SuspiciousTimesFixture::TIME_STEADY_FAST)['checked_at'];

        // 49:08:00 against a predicted hour is 49× - a puzzle that takes everybody up to 60× longer
        $this->messageBus->dispatch(new SetPuzzleSlowThreshold(SuspiciousTimesFixture::PUZZLE_ORCHARD, PlayerFixture::PLAYER_ADMIN, 520, 60.0));
        $this->clearEntityManager();
        $summary = $this->scan(onlyPuzzleId: SuspiciousTimesFixture::PUZZLE_ORCHARD);

        self::assertSame(1, $summary->goneCases);
        self::assertSame(0, $summary->raised);
        self::assertSame(['gone', 'clear'], [$this->caseOf(SuspiciousTimesFixture::TIME_STEADY_TYPO)['status'], $this->checkOf(SuspiciousTimesFixture::TIME_STEADY_TYPO)['outcome']]);
        // Another puzzle's time was not looked at
        self::assertSame($fastCheckedAt, $this->checkOf(SuspiciousTimesFixture::TIME_STEADY_FAST)['checked_at']);
        self::assertSame('pending', $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_FAST)['status']);

        // Taken away: the next run of the puzzle raises it again
        $this->messageBus->dispatch(new SetPuzzleSlowThreshold(SuspiciousTimesFixture::PUZZLE_ORCHARD, PlayerFixture::PLAYER_ADMIN, 520, null));
        $this->clearEntityManager();
        $again = $this->scan(onlyPuzzleId: SuspiciousTimesFixture::PUZZLE_ORCHARD);

        self::assertSame(1, $again->reopenedCases);
        self::assertSame('pending', $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_TYPO)['status']);
    }

    public function testTheFullScanJudgesAPuzzleAgainWhenItsThresholdChanged(): void
    {
        $this->scan();
        $this->clearEntityManager();
        $this->checkedAnHourAgo();

        // Saved, but the run of the puzzle never happened (it failed) - the next full run catches up
        $this->messageBus->dispatch(new SetPuzzleSlowThreshold(SuspiciousTimesFixture::PUZZLE_ORCHARD, PlayerFixture::PLAYER_ADMIN, 520, 60.0));
        $this->clearEntityManager();
        $summary = $this->scan();

        self::assertSame(1, $summary->goneCases);
        self::assertSame('gone', $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_TYPO)['status']);

        // ... once: a check after the threshold is current (one in the very same second is not - see the query)
        $this->database->executeStatement("UPDATE suspicious_time_puzzle_confirmation SET confirmed_at = confirmed_at - INTERVAL '1 minute'");
        $this->clearEntityManager();
        $again = $this->scan();
        self::assertSame($again->noData, $again->checked);
    }

    public function testPredictionBuiltOnASlowAttemptIsNotTrusted(): void
    {
        // Two later attempts of Quiet Orchard, whose 49:08:00 has a pending slow case - both predicted from it (41:40:00)
        $prediction = ['predictable' => true, 'prediction_method' => 'personal', 'predicted_seconds' => 150000, 'prediction_last_time_seconds' => SuspiciousTimesFixture::STEADY_TYPO_SECONDS, 'days_ago' => 5];
        $honest = $this->cloneSolvingTime(SuspiciousTimesFixture::TIME_STEADY_TYPO, ['seconds_to_solve' => 800, ...$prediction]);
        $fast = $this->cloneSolvingTime(SuspiciousTimesFixture::TIME_STEADY_TYPO, ['seconds_to_solve' => 150, ...$prediction]);

        $this->scan();

        // 13:20 is about Sam's pace for 520 pieces
        self::assertSame('clear', $this->checkOf($honest)['outcome']);
        self::assertFalse($this->caseOrFalse($honest));

        // 2:30 is not - judged against his pace, with the reason the prediction was left out
        $case = $this->caseOf($fast);
        self::assertSame(['pending', 'fast', 'pace'], [$case['status'], $case['direction'], $case['expected_source']]);
        self::assertSame('faster_than_usual', $this->codes($case['reasons'])[0]);
        $reason = $this->reason($case['reasons'], 'prediction_from_slow_attempt');
        ksort($reason);
        self::assertSame(['predicted' => 150000, 'previous' => SuspiciousTimesFixture::STEADY_TYPO_SECONDS, 'raised_slow' => true], $reason);
    }

    public function testCaseWhoseTimeHasNoSecondsAnyMoreIsGone(): void
    {
        $this->database->executeStatement('UPDATE puzzle_solving_time SET seconds_to_solve = NULL WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_STEADY_TYPO]);

        $this->scan();

        self::assertSame('gone', $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_TYPO)['status']);
    }

    public function testDecidedEntryIsNeverCheckedAgainButAnUndecidedOneIsOnAVersionBump(): void
    {
        $this->scan();

        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->findByTime(SuspiciousTimesFixture::TIME_EDITION_FAST);
        self::assertNotNull($case);
        $case->trust(null, SuspicionFingerprint::ofTime($case->time), new \DateTimeImmutable());
        $this->entityManager->flush();

        // A new detector version: every check is old
        $this->database->executeStatement('UPDATE suspicious_time_check SET version = 0');
        $this->clearEntityManager();
        $this->scan();

        self::assertSame(0, $this->checkOf(SuspiciousTimesFixture::TIME_EDITION_FAST)['version'], 'A trusted entry is not checked again');
        self::assertSame('trusted', $this->caseOf(SuspiciousTimesFixture::TIME_EDITION_FAST)['status']);
        self::assertSame(SuspiciousTimeClassifier::VERSION, $this->checkOf(SuspiciousTimesFixture::TIME_STEADY_FAST)['version'], 'An undecided one is');
        self::assertSame('pending', $this->caseOf(SuspiciousTimesFixture::TIME_STEADY_FAST)['status']);

        // Trust belongs to the entry: another time is another entry, checked and raised again
        $this->setSeconds(SuspiciousTimesFixture::TIME_EDITION_FAST, 8000);
        $this->clearEntityManager();
        $summary = $this->scan();

        self::assertSame(1, $summary->reopenedCases);
        self::assertSame('pending', $this->caseOf(SuspiciousTimesFixture::TIME_EDITION_FAST)['status']);
    }

    public function testNoDataIsCheckedAgainOnlyWhileTheTimeIsRecent(): void
    {
        // Gina has no level yet and 19 h 27 min is within the community's range: nothing to judge by
        $recent = $this->cloneSolvingTime(SuspiciousTimesFixture::TIME_GROUP_SOLO, ['seconds_to_solve' => 70000, 'days_ago' => 10, 'comment' => '']);
        $old = $this->cloneSolvingTime(SuspiciousTimesFixture::TIME_GROUP_SOLO, ['seconds_to_solve' => 70001, 'days_ago' => 200, 'comment' => '']);

        $this->scan();

        self::assertSame('no_data', $this->checkOf($recent)['outcome']);
        self::assertSame('no_data', $this->checkOf($old)['outcome']);

        $this->database->executeStatement("UPDATE suspicious_time_check SET checked_at = '2000-01-01 00:00:00' WHERE time_id IN (:recent, :old)", ['recent' => $recent, 'old' => $old]);
        $this->clearEntityManager();
        $this->scan();

        self::assertNotSame('2000-01-01 00:00:00', $this->checkOf($recent)['checked_at'], 'Solved in the last 180 days: checked again');
        self::assertSame('2000-01-01 00:00:00', $this->checkOf($old)['checked_at'], 'Older: final for the version');
    }

    public function testDryRunWritesNothing(): void
    {
        $before = $this->rowCounts();

        $summary = $this->scan(dryRun: true);

        self::assertTrue($summary->dryRun);
        self::assertSame(0, $summary->markedOutsideApp, 'No reconciliation in a dry run');
        self::assertSame($before, $this->rowCounts());

        $raised = array_map(static fn (SuspiciousTimeRaisedRow $row): string => $row->timeId, $summary->raisedRows);
        self::assertContains(SuspiciousTimesFixture::TIME_EDITION_FAST, $raised);
        self::assertContains(SuspiciousTimesFixture::TIME_SLOW_PAIR, $raised);
        self::assertSame(count($summary->raisedRows), $summary->raised);
        self::assertSame($summary->raised, array_sum($summary->raisedByDirection));
    }

    public function testRunningAgainChangesNothing(): void
    {
        $this->scan();
        $this->clearEntityManager();
        $again = $this->scan();

        self::assertSame(
            [0, 0, 0, 0, 0, 0, 0],
            [$again->raised, $again->newCases, $again->refreshedCases, $again->reopenedCases, $again->goneCases, $again->markedOutsideApp, $again->unmarkedOutsideApp],
        );
        self::assertSame($again->noData, $again->checked, 'Only recent no_data times are checked again');
    }

    /**
     * A moderator marks Sam's 2:30:00 on Harbour Lights (the pending detector case of the fixture) with its reasons.
     */
    private function markSamsFastTime(): void
    {
        $this->clearEntityManager();
        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->findByTime(SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::assertNotNull($case);
        $case->time->markSuspicious();
        $case->mark($case->reasons(), null, Uuid::fromString(PlayerFixture::PLAYER_ADMIN), SuspicionFingerprint::ofTime($case->time), new \DateTimeImmutable('-1 day'));
        self::getContainer()->get(SuspiciousTimeDecisionRecorder::class)->recordAboutTime(SuspiciousTimeDecisionKind::Marked, $case->time, $case, null);
        $this->entityManager->flush();
        $this->clearEntityManager();
    }

    private function scan(bool $dryRun = false, null|string $onlyPuzzleId = null): SuspiciousTimeScanSummary
    {
        $summary = $this->messageBus->dispatch(new DetectSuspiciousTimes($dryRun, $onlyPuzzleId))->last(HandledStamp::class)?->getResult();
        assert($summary instanceof SuspiciousTimeScanSummary);

        return $summary;
    }

    /**
     * As if the last scan ran an hour ago - a check of this run is told apart from it.
     */
    private function checkedAnHourAgo(): void
    {
        $this->database->executeStatement("UPDATE suspicious_time_check SET checked_at = checked_at - INTERVAL '1 hour'");
    }

    /**
     * Like SuspiciousTimeScan between messages - rows changed by SQL are read again.
     */
    private function clearEntityManager(): void
    {
        $this->entityManager->clear();
    }

    private function setSeconds(string $timeId, int $seconds): void
    {
        $this->database->executeStatement('UPDATE puzzle_solving_time SET seconds_to_solve = :seconds WHERE id = :id', ['seconds' => $seconds, 'id' => $timeId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function caseOf(string $timeId): array
    {
        $case = $this->caseOrFalse($timeId);
        self::assertIsArray($case, "A case of {$timeId}");

        return $case;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function caseOrFalse(string $timeId): array|false
    {
        return $this->database->fetchAssociative('SELECT * FROM suspicious_time_case WHERE time_id = :id', ['id' => $timeId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkOf(string $timeId): array
    {
        $check = $this->checkOrFalse($timeId);
        self::assertIsArray($check, "A check of {$timeId}");

        return $check;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function checkOrFalse(string $timeId): array|false
    {
        return $this->database->fetchAssociative('SELECT * FROM suspicious_time_check WHERE time_id = :id', ['id' => $timeId]);
    }

    /**
     * @return list<string>
     */
    private function decisionsOf(string $timeId): array
    {
        /** @var list<string> $decisions */
        $decisions = $this->database->fetchFirstColumn('SELECT decision FROM suspicious_time_decision WHERE time_id = :id ORDER BY decided_at', ['id' => $timeId]);

        return $decisions;
    }

    private function fingerprintOf(string $timeId): string
    {
        $fingerprint = $this->database->fetchOne(
            'SELECT ' . SuspicionFingerprint::sql('pst', 'p') . ' FROM puzzle_solving_time pst INNER JOIN puzzle p ON p.id = pst.puzzle_id WHERE pst.id = :id',
            ['id' => $timeId],
        );
        assert(is_string($fingerprint));

        return $fingerprint;
    }

    private function baselineOf(string $playerId, int $piecesCount): int
    {
        $baseline = $this->database->fetchOne(
            'SELECT baseline_seconds FROM player_baseline WHERE player_id = :playerId AND pieces_count = :piecesCount',
            ['playerId' => $playerId, 'piecesCount' => $piecesCount],
        );
        assert(is_numeric($baseline));

        return (int) $baseline;
    }

    private function solvedTimesCountOf(string $timeId): int
    {
        $count = $this->database->fetchOne(
            'SELECT s.solved_times_count FROM puzzle_statistics s INNER JOIN puzzle_solving_time pst ON pst.puzzle_id = s.puzzle_id WHERE pst.id = :id',
            ['id' => $timeId],
        );

        return is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @return list<string>
     */
    private function codes(mixed $reasons): array
    {
        return array_map(static fn (array $reason): string => $reason['code'], self::decodedReasons($reasons));
    }

    /**
     * @return array<string, mixed>
     */
    private function reason(mixed $reasons, string $code): array
    {
        foreach (self::decodedReasons($reasons) as $reason) {
            if ($reason['code'] === $code) {
                return $reason['params'];
            }
        }

        self::fail("No reason {$code}");
    }

    /**
     * @return list<array{code: string, params: array<string, mixed>}>
     */
    private static function decodedReasons(mixed $reasons): array
    {
        assert(is_string($reasons));

        /** @var list<array{code: string, params: array<string, mixed>}> $decoded */
        $decoded = json_decode($reasons, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (['suspicious_time_check', 'suspicious_time_case', 'suspicious_time_decision', 'suspicious_time_notice', 'suspicious_time_reference'] as $table) {
            $count = $this->database->fetchOne("SELECT COUNT(*) FROM {$table}");
            $counts[$table] = is_numeric($count) ? (int) $count : -1;
        }

        $flagged = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE suspicious = true');
        $counts['flagged'] = is_numeric($flagged) ? (int) $flagged : -1;

        return $counts;
    }
}
