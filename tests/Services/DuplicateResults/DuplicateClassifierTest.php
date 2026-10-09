<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DuplicateResults;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\DuplicateCandidate;
use SpeedPuzzling\Web\Results\DuplicateCandidateTime;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateClassifier;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;

final class DuplicateClassifierTest extends TestCase
{
    private const string PERSON = 'person';
    private const string TEAMMATE = 'teammate';
    private const string OTHER = 'other';
    private const string PAIR = 'pair-team';
    private const string TRIO = 'trio-team';

    public function testSameFormSentAgainWithinTenSecondsIsCertain(): void
    {
        $this->assertClassified(DuplicateTier::Certain, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00'),
            newer: $this->time(savedAt: '10:00:10'),
        ));
    }

    public function testElevenSecondsApartIsOnlyStrong(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00'),
            newer: $this->time(savedAt: '10:00:11'),
            savedInBetween: null,
        ));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideAnyDifferenceInFields(): iterable
    {
        yield 'finish date' => [['finishedAt' => new DateTimeImmutable('2026-09-01 00:00:00')]];
        yield 'competition' => [['competitionId' => 'competition']];
        yield 'round' => [['competitionRoundId' => 'round']];
        yield 'first try' => [['firstAttempt' => true]];
        yield 'unboxed' => [['unboxed' => true]];
        yield 'comment' => [['comment' => 'Fun one']];
        yield 'photo' => [['hasPhoto' => true]];
    }

    /**
     * @param array<string, mixed> $difference
     */
    #[DataProvider('provideAnyDifferenceInFields')]
    public function testAnyDifferenceInFieldsIsOnlyStrong(array $difference): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00'),
            newer: $this->time(savedAt: '10:00:05', overrides: $difference),
        ));
    }

    /**
     * P23 (docs/features/events-page/high-frequency-series.md): a series pick's edition and round are derived - two
     * copies of one series pick are one event whichever edition each was matched to, or none yet
     *
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function provideCopiesOfOneSeriesPick(): iterable
    {
        yield 'matched and series-level' => [
            ['competitionSeriesId' => 'series', 'competitionId' => 'edition', 'competitionRoundId' => 'round'],
            ['competitionSeriesId' => 'series'],
        ];
        yield 'matched to different editions' => [
            ['competitionSeriesId' => 'series', 'competitionId' => 'edition', 'competitionRoundId' => 'round'],
            ['competitionSeriesId' => 'series', 'competitionId' => 'other edition'],
        ];
        yield 'both series-level' => [['competitionSeriesId' => 'series'], ['competitionSeriesId' => 'series']];
    }

    /**
     * @param array<string, mixed> $older
     * @param array<string, mixed> $newer
     */
    #[DataProvider('provideCopiesOfOneSeriesPick')]
    public function testCopiesOfOneSeriesPickAreTheSameEvent(array $older, array $newer): void
    {
        $this->assertClassified(DuplicateTier::Certain, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: $older),
            newer: $this->time(savedAt: '10:00:05', overrides: $newer),
        ));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function provideDifferentEvents(): iterable
    {
        // The player picked the edition once and the series once - they decide which copy is right
        yield 'explicit edition and a series pick matched to it' => [
            ['competitionId' => 'edition', 'competitionRoundId' => 'round'],
            ['competitionSeriesId' => 'series', 'competitionId' => 'edition', 'competitionRoundId' => 'round'],
        ];
        yield 'two series' => [['competitionSeriesId' => 'series'], ['competitionSeriesId' => 'other series']];
        yield 'a series pick and no event' => [['competitionSeriesId' => 'series'], []];
    }

    /**
     * @param array<string, mixed> $older
     * @param array<string, mixed> $newer
     */
    #[DataProvider('provideDifferentEvents')]
    public function testDifferentEventsAreOnlyStrong(array $older, array $newer): void
    {
        $candidate = $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: $older),
            newer: $this->time(savedAt: '10:00:05', overrides: $newer),
        );

        self::assertSame([DuplicateCandidate::DIFFERENCE_COMPETITION], $candidate->differences());
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SameTracker, $candidate);
    }

    public function testEmptyAndMissingCommentAreTheSame(): void
    {
        $this->assertClassified(DuplicateTier::Certain, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['comment' => null]),
            newer: $this->time(savedAt: '10:00:05', overrides: ['comment' => '  ']),
        ));
    }

    public function testSomethingSavedInBetweenIsOnlyStrong(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00'),
            newer: $this->time(savedAt: '10:00:05'),
            savedInBetween: true,
        ));
    }

    public function testIdenticalTwinsInsidePracticeSessionArePossible(): void
    {
        $this->assertClassified(DuplicateTier::Possible, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00'),
            newer: $this->time(savedAt: '10:00:05'),
            practiceSession: true,
        ));
    }

    public function testSameDaySoloTwinsOutsidePracticeSessionAreStrong(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00'),
            newer: $this->time(savedAt: '18:00:00'),
            savedInBetween: null,
        ));
    }

    public function testSameDaySoloTwinsInsidePracticeSessionArePossible(): void
    {
        $this->assertClassified(DuplicateTier::Possible, DuplicateKind::SameTracker, $this->candidate(
            older: $this->time(savedAt: '10:00:00'),
            newer: $this->time(savedAt: '18:00:00'),
            practiceSession: true,
            savedInBetween: null,
        ));
    }

    public function testSoloOnDifferentDaysSavedWithinAnHourIsPossible(): void
    {
        $this->assertClassified(DuplicateTier::Possible, DuplicateKind::SavedWithinHour, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['solvedDay' => '2026-08-31']),
            newer: $this->time(savedAt: '10:59:59'),
        ));
    }

    public function testSoloOnDifferentDaysSavedLaterIsNotFlagged(): void
    {
        self::assertNull((new DuplicateClassifier())->classify($this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['solvedDay' => '2026-08-31']),
            newer: $this->time(savedAt: '11:00:00'),
            savedInBetween: null,
        )));
    }

    public function testTeammateCopyIsStrongOnAnyDay(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::TeammateCopy, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR, 'solvedDay' => '2026-07-01']),
            newer: $this->time(savedAt: '10:00:00', tracker: self::TEAMMATE, overrides: ['teamId' => self::PAIR], day: '2026-09-20'),
            savedInBetween: null,
        ));
    }

    public function testTeammateCopyWithOverlappingPeopleIsStrong(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::TeammateCopy, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR]),
            newer: $this->time(savedAt: '19:00:00', tracker: self::TEAMMATE, overrides: ['teamId' => self::TRIO]),
            savedInBetween: null,
        ));
    }

    public function testSoloAndGroupOnTheSameDayIsStrong(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SoloAndGroup, $this->candidate(
            older: $this->time(savedAt: '10:00:00'),
            newer: $this->time(savedAt: '19:00:00', tracker: self::TEAMMATE, overrides: ['teamId' => self::PAIR]),
            savedInBetween: null,
        ));
    }

    public function testOwnSoloAndOwnGroupOnTheSameDayIsStrong(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SoloAndGroup, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR]),
            newer: $this->time(savedAt: '10:00:03'),
        ));
    }

    public function testSoloAndGroupOnDifferentDaysSavedLaterIsNotFlagged(): void
    {
        self::assertNull((new DuplicateClassifier())->classify($this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['solvedDay' => '2026-08-01']),
            newer: $this->time(savedAt: '19:00:00', tracker: self::TEAMMATE, overrides: ['teamId' => self::PAIR]),
            savedInBetween: null,
        )));
    }

    public function testSameTrackerSameGroupResentIsCertain(): void
    {
        $this->assertClassified(DuplicateTier::Certain, DuplicateKind::SameTrackerGroup, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR]),
            newer: $this->time(savedAt: '10:00:04', overrides: ['teamId' => self::PAIR]),
        ));
    }

    public function testSameTrackerSameGroupOnTheSameDayIsStrong(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SameTrackerGroup, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR]),
            newer: $this->time(savedAt: '15:00:00', overrides: ['teamId' => self::PAIR]),
            practiceSession: true,
            savedInBetween: null,
        ));
    }

    public function testSameTrackerSameGroupOnDifferentDaysIsPossible(): void
    {
        $this->assertClassified(DuplicateTier::Possible, DuplicateKind::SameTrackerGroup, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR, 'solvedDay' => '2026-05-01']),
            newer: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR], day: '2026-09-01'),
            savedInBetween: null,
        ));
    }

    public function testSameTrackerTwoDifferentGroupsOnTheSameDayIsStrong(): void
    {
        $this->assertClassified(DuplicateTier::Strong, DuplicateKind::SameTrackerGroup, $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR]),
            newer: $this->time(savedAt: '10:00:04', overrides: ['teamId' => self::TRIO]),
        ));
    }

    public function testSnapshotKeepsWhatDiffersAndWhoElseTookPart(): void
    {
        $candidate = $this->candidate(
            older: $this->time(savedAt: '10:00:00', overrides: ['teamId' => self::PAIR]),
            newer: $this->time(savedAt: '10:00:30', tracker: self::TEAMMATE, overrides: ['teamId' => self::PAIR, 'firstAttempt' => true]),
        );

        $snapshot = $candidate->snapshot();

        self::assertSame(30, $snapshot['gap_seconds']);
        self::assertSame(['first_try'], $snapshot['differences']);
        self::assertSame(self::PERSON, $snapshot['tracker_a']['id']);
        self::assertSame(self::TEAMMATE, $snapshot['tracker_b']['id']);
        self::assertSame([self::TEAMMATE], array_column($snapshot['others'], 'id'));
    }

    private function assertClassified(DuplicateTier $tier, DuplicateKind $kind, DuplicateCandidate $candidate): void
    {
        $classification = (new DuplicateClassifier())->classify($candidate);

        self::assertNotNull($classification);
        self::assertSame($tier, $classification->tier);
        self::assertSame($kind, $classification->kind);
    }

    private function candidate(
        DuplicateCandidateTime $older,
        DuplicateCandidateTime $newer,
        bool $practiceSession = false,
        null|bool $savedInBetween = false,
    ): DuplicateCandidate {
        return new DuplicateCandidate(
            personId: self::PERSON,
            puzzleId: 'puzzle',
            puzzleName: 'Puzzle',
            secondsToSolve: 1800,
            older: $older,
            newer: $newer,
            practiceSession: $practiceSession,
            savedInBetween: $savedInBetween,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function time(string $savedAt, string $tracker = self::PERSON, array $overrides = [], string $day = '2026-09-01'): DuplicateCandidateTime
    {
        $teamId = $overrides['teamId'] ?? null;
        $people = [['id' => $tracker, 'name' => null, 'code' => $tracker]];

        if ($teamId !== null) {
            $people = [
                ['id' => self::PERSON, 'name' => null, 'code' => self::PERSON],
                ['id' => self::TEAMMATE, 'name' => null, 'code' => self::TEAMMATE],
            ];

            if ($teamId === self::TRIO) {
                $people[] = ['id' => self::OTHER, 'name' => null, 'code' => self::OTHER];
            }
        }

        $values = array_merge([
            'teamId' => null,
            'solvedDay' => $day,
            'finishedAt' => null,
            'comment' => null,
            'hasPhoto' => false,
            'firstAttempt' => false,
            'unboxed' => false,
            'competitionId' => null,
            'competitionRoundId' => null,
            'competitionSeriesId' => null,
        ], $overrides);

        /** @var array{teamId: null|string, solvedDay: string, finishedAt: null|DateTimeImmutable, comment: null|string, hasPhoto: bool, firstAttempt: bool, unboxed: bool, competitionId: null|string, competitionRoundId: null|string, competitionSeriesId: null|string} $values */
        return new DuplicateCandidateTime(
            timeId: $tracker . $savedAt . ($values['teamId'] ?? ''),
            trackerId: $tracker,
            trackerName: null,
            trackerCode: $tracker,
            teamId: $values['teamId'],
            people: $people,
            solvedDay: $values['solvedDay'],
            finishedAt: $values['finishedAt'],
            trackedAt: new DateTimeImmutable($day . ' ' . $savedAt),
            comment: $values['comment'],
            hasPhoto: $values['hasPhoto'],
            firstAttempt: $values['firstAttempt'],
            unboxed: $values['unboxed'],
            competitionId: $values['competitionId'],
            competitionRoundId: $values['competitionRoundId'],
            competitionSeriesId: $values['competitionSeriesId'],
        );
    }
}
