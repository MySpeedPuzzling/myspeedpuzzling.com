<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Entity\SuspiciousTimeConfirmation;
use SpeedPuzzling\Web\Entity\SuspiciousTimeNotice;
use SpeedPuzzling\Web\Entity\SuspiciousTimeReference;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\PaceReference;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspicionPiecesRange;
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;
use SpeedPuzzling\Web\Value\TimePredictionSource;

/**
 * Suspicious time review (docs/features/suspicious-time-review.md) - on their own players and puzzles (not approved,
 * at piece counts without a brand page, so no catalogue list, brand page or leaderboard bucket sees them). See
 * .claude/fixtures.md.
 *
 * Sam's baseline is at 4000 pieces on purpose: the full insights recalculation computes direct baselines only for
 * piece counts some approved puzzle has (PuzzleFixture::PUZZLE_4000), the incremental one after a save for any - at
 * another count the two would disagree. Eda's history is 5 results on 3 puzzles: enough for the pace fallback, too
 * few first tries for a baseline.
 *
 * The test database is far too small for community references, so the fixture stores three
 * (suspicious_time_reference) for ranges the other fixtures do not fill: the scan keeps a stored reference whose
 * range has fewer than SuspiciousTimeClassifier::REFERENCE_MIN_SAMPLE results. Keep the 1201-2000 and 2001-5000
 * ranges below that minimum when adding results there.
 */
final class SuspiciousTimesFixture extends Fixture implements DependentFixtureInterface
{
    public const string PLAYER_STEADY = '018d0031-0000-0000-0000-000000000001';
    public const string PLAYER_EDITION = '018d0031-0000-0000-0000-000000000002';
    public const string PLAYER_GROUP = '018d0031-0000-0000-0000-000000000003';
    public const string PLAYER_MARKED = '018d0031-0000-0000-0000-000000000004';
    public const string PLAYER_FLAGGED = '018d0031-0000-0000-0000-000000000005';
    public const string PLAYER_PARTNER = '018d0031-0000-0000-0000-000000000006';

    // 4000 pieces (range 2001-5000)
    public const string PUZZLE_HARBOUR = '018d0031-0000-0000-0000-000000000110';
    // 520 pieces (range 500-750)
    public const string PUZZLE_ORCHARD = '018d0031-0000-0000-0000-000000000111';
    public const string PUZZLE_SILENT_PIER = '018d0031-0000-0000-0000-000000000112';
    public const string PUZZLE_GARDEN_GATE = '018d0031-0000-0000-0000-000000000113';
    // "Lighthouse Cove" in two editions of the same brand
    public const string PUZZLE_LIGHTHOUSE_1600 = '018d0031-0000-0000-0000-000000000130';
    public const string PUZZLE_LIGHTHOUSE_3000 = '018d0031-0000-0000-0000-000000000131';
    // 3000 pieces (range 2001-5000)
    public const string PUZZLE_MEADOW = '018d0031-0000-0000-0000-000000000140';
    public const string PUZZLE_MARATHON = '018d0031-0000-0000-0000-000000000141';

    // Sam Steady: 5 first tries of 4000-piece puzzles at ~9.5 h (7 PPM, baseline ~34200 s) ...
    public const array TIMES_STEADY_HISTORY = [
        '018d0031-0000-0000-0000-000000000201',
        '018d0031-0000-0000-0000-000000000202',
        '018d0031-0000-0000-0000-000000000203',
        '018d0031-0000-0000-0000-000000000204',
        '018d0031-0000-0000-0000-000000000205',
    ];
    // ... then 2:30:00 on Harbour Lights (4000): faster_than_usual (baseline) + hours_left_out 7:30:00 - a pending case
    public const string TIME_STEADY_FAST = '018d0031-0000-0000-0000-000000000210';
    public const int STEADY_FAST_SECONDS = 9000;
    // ... and 49:08:00 on Quiet Orchard (520) with a stored prediction of 1:00:00: slower_than_predicted +
    // minutes_in_hours_box 49:08 + includes_breaks - a pending case
    public const string TIME_STEADY_TYPO = '018d0031-0000-0000-0000-000000000211';
    public const int STEADY_TYPO_SECONDS = 176880;
    public const int STEADY_TYPO_PREDICTED_SECONDS = 3600;

    // Eda Edition: 5 solo results of 1600-piece puzzles (3 puzzles, two solved twice) at 7 PPM (pace 2.0 × the
    // 1201-2000 median) ...
    public const array TIMES_EDITION_HISTORY = [
        '018d0031-0000-0000-0000-000000000221',
        '018d0031-0000-0000-0000-000000000222',
        '018d0031-0000-0000-0000-000000000223',
        '018d0031-0000-0000-0000-000000000224',
        '018d0031-0000-0000-0000-000000000225',
    ];
    // ... then 2:40:00 on the 3000-piece Lighthouse Cove: faster_than_usual (pace) + other_edition (the 1600 one)
    public const string TIME_EDITION_FAST = '018d0031-0000-0000-0000-000000000230';

    // Gina Group, a new player: 25:00 solo on the 3000-piece Mountain Meadow ("together with my team"), confirmed
    // while saving; Pat and Fay saved a pair result of it the same day 1 minute apart - beyond_known_pace +
    // teammates_saved_group + comment_mentions_group + new_player + confirmed_while_saving
    public const string TIME_GROUP_SOLO = '018d0031-0000-0000-0000-000000000240';
    public const string TIME_PARTNERS_PAIR = '018d0031-0000-0000-0000-000000000241';
    // Pat + Fay: 150:00:00 for a 3000-piece pair - below_slow_floor (duo) + includes_breaks
    public const string TIME_SLOW_PAIR = '018d0031-0000-0000-0000-000000000242';

    // Mia Marked: flagged, a marked case (by PlayerFixture::PLAYER_ADMIN) and its unanswered notice (via run)
    public const string TIME_MARKED = '018d0031-0000-0000-0000-000000000250';
    // Fay + Pat pair, flagged by "SQL" - no case
    public const string TIME_SQL_FLAGGED = '018d0031-0000-0000-0000-000000000260';

    public const string CASE_PENDING_FAST = '018d0031-0000-0000-0000-000000000301';
    public const string CASE_PENDING_SLOW = '018d0031-0000-0000-0000-000000000302';
    public const string CASE_MARKED = '018d0031-0000-0000-0000-000000000303';
    public const string NOTICE_MARKED = '018d0031-0000-0000-0000-000000000401';
    public const string CONFIRMATION_GROUP_SOLO = '018d0031-0000-0000-0000-000000000501';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly PuzzlingTeamResolver $puzzlingTeamResolver,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        foreach (
            [
            new PaceReference(SuspicionPiecesRange::From1201, PuzzlingType::Solo, 3.5, 19.0, 500),
            new PaceReference(SuspicionPiecesRange::From2001, PuzzlingType::Solo, 2.8, 9.0, 400),
            new PaceReference(SuspicionPiecesRange::From2001, PuzzlingType::Duo, 4.0, 12.0, 100),
            ] as $reference
        ) {
            $manager->persist(SuspiciousTimeReference::of($reference, $now));
        }

        $steady = $this->player($manager, self::PLAYER_STEADY, 'steady1', 'Sam Steady');
        $edition = $this->player($manager, self::PLAYER_EDITION, 'edition1', 'Eda Edition');
        $group = $this->player($manager, self::PLAYER_GROUP, 'ginagr1', 'Gina Group');
        $marked = $this->player($manager, self::PLAYER_MARKED, 'marked1', 'Mia Marked');
        $flagged = $this->player($manager, self::PLAYER_FLAGGED, 'flagged1', 'Fay Flagged');
        $partner = $this->player($manager, self::PLAYER_PARTNER, 'partner1', 'Pat Partner');

        $steadyPuzzles = [];

        foreach (range(1, 5) as $number) {
            $steadyPuzzles[] = $this->puzzle($manager, sprintf('018d0031-0000-0000-0000-%012d', 100 + $number), 4000, "Steady Fields {$number}", $steady);
        }

        $harbour = $this->puzzle($manager, self::PUZZLE_HARBOUR, 4000, 'Harbour Lights', $steady);
        $orchard = $this->puzzle($manager, self::PUZZLE_ORCHARD, 520, 'Quiet Orchard', $steady);
        $silentPier = $this->puzzle($manager, self::PUZZLE_SILENT_PIER, 520, 'Silent Pier', $marked);
        $gardenGate = $this->puzzle($manager, self::PUZZLE_GARDEN_GATE, 520, 'Garden Gate', $flagged);

        $editionPuzzles = [];

        foreach (range(1, 3) as $number) {
            $editionPuzzles[] = $this->puzzle($manager, sprintf('018d0031-0000-0000-0000-%012d', 120 + $number), 1600, "Cove Study {$number}", $edition);
        }

        $this->puzzle($manager, self::PUZZLE_LIGHTHOUSE_1600, 1600, 'Lighthouse Cove', $edition);
        $lighthouse3000 = $this->puzzle($manager, self::PUZZLE_LIGHTHOUSE_3000, 3000, 'Lighthouse Cove', $edition);
        $meadow = $this->puzzle($manager, self::PUZZLE_MEADOW, 3000, 'Mountain Meadow', $group);
        $marathon = $this->puzzle($manager, self::PUZZLE_MARATHON, 3000, 'Marathon Mosaic', $partner);

        // PuzzlingTeamResolver inserts the pairs right away - their members must exist by then
        $manager->flush();

        $pair = new PuzzlersGroup(
            teamId: null,
            puzzlers: [
                new Puzzler(playerId: self::PLAYER_PARTNER, playerName: 'Pat Partner', playerCode: 'partner1', playerCountry: null, isPrivate: false),
                new Puzzler(playerId: self::PLAYER_FLAGGED, playerName: 'Fay Flagged', playerCode: 'flagged1', playerCountry: null, isPrivate: false),
            ],
        );
        $pairTrackedByFay = new PuzzlersGroup(teamId: null, puzzlers: array_reverse($pair->puzzlers));

        foreach (self::TIMES_STEADY_HISTORY as $index => $timeId) {
            $this->time($manager, $timeId, $steady, $steadyPuzzles[$index], [34000, 34200, 34286, 34400, 34600][$index], 20 + $index * 10);
        }

        $steadyFast = $this->time($manager, self::TIME_STEADY_FAST, $steady, $harbour, self::STEADY_FAST_SECONDS, 12);
        $steadyTypo = $this->time($manager, self::TIME_STEADY_TYPO, $steady, $orchard, self::STEADY_TYPO_SECONDS, 10);
        $steadyTypo->recordPrediction(
            SolvingTimePrediction::predicted(
                new TimePredictionResult(self::STEADY_TYPO_PREDICTED_SECONDS, 3000, 4300, 1.0),
                TimePredictionSource::Live,
            ),
            $steadyTypo->trackedAt,
        );

        foreach (self::TIMES_EDITION_HISTORY as $index => $timeId) {
            $this->time($manager, $timeId, $edition, $editionPuzzles[$index % 3], [13714, 13714, 13714, 13700, 13650][$index], 70 - $index * 10, firstAttempt: $index < 3);
        }

        $this->time($manager, self::TIME_EDITION_FAST, $edition, $lighthouse3000, 9600, 15);

        $groupSolo = $this->time($manager, self::TIME_GROUP_SOLO, $group, $meadow, 1500, 8, comment: 'Puzzled together with my team at the club');
        $this->time($manager, self::TIME_PARTNERS_PAIR, $partner, $meadow, 1560, 8, team: $pair);
        $this->time($manager, self::TIME_SLOW_PAIR, $partner, $marathon, 540000, 20, team: $pair);

        $markedTime = $this->time($manager, self::TIME_MARKED, $marked, $silentPier, 1300, 25, suspicious: true);
        $this->time($manager, self::TIME_SQL_FLAGGED, $flagged, $gardenGate, 2400, 18, team: $pairTrackedByFay, suspicious: true);

        $manager->persist(new SuspiciousTimeConfirmation(
            id: Uuid::fromString(self::CONFIRMATION_GROUP_SOLO),
            time: $groupSolo,
            player: $group,
            expectedSeconds: 64286,
            confirmedAt: $groupSolo->trackedAt,
        ));

        $manager->persist(SuspiciousTimeCase::detected(
            Uuid::fromString(self::CASE_PENDING_FAST),
            $steadyFast,
            new SuspicionAssessment(
                outcome: SuspicionCheckOutcome::Raised,
                tier: SuspiciousTimeTier::Strong,
                ratio: 3.8,
                expectedSeconds: 34200,
                expectedSource: ExpectedTimeSource::Baseline,
                reasons: [
                    new SuspiciousTimeReason(SuspiciousTimeReasonCode::FasterThanUsual, ['expected' => 34200, 'entered' => self::STEADY_FAST_SECONDS, 'ratio' => 3.8, 'pieces' => 4000, 'source' => 'baseline']),
                    new SuspiciousTimeReason(SuspiciousTimeReasonCode::HoursLeftOut, ['suggested' => 27000, 'hours' => 5, 'entered' => self::STEADY_FAST_SECONDS]),
                ],
                suggestedSeconds: 27000,
                score: 3.8,
            ),
            SuspicionFingerprint::ofTime($steadyFast),
            SuspiciousTimeClassifier::VERSION,
            $now->modify('-1 day'),
        ));

        $manager->persist(SuspiciousTimeCase::detected(
            Uuid::fromString(self::CASE_PENDING_SLOW),
            $steadyTypo,
            new SuspicionAssessment(
                outcome: SuspicionCheckOutcome::Raised,
                tier: SuspiciousTimeTier::Strong,
                ratio: round(self::STEADY_TYPO_PREDICTED_SECONDS / self::STEADY_TYPO_SECONDS, 4),
                expectedSeconds: self::STEADY_TYPO_PREDICTED_SECONDS,
                expectedSource: ExpectedTimeSource::Prediction,
                reasons: [
                    new SuspiciousTimeReason(SuspiciousTimeReasonCode::SlowerThanPredicted, ['expected' => 3600, 'entered' => self::STEADY_TYPO_SECONDS, 'ratio' => 49.13, 'pieces' => 520]),
                    new SuspiciousTimeReason(SuspiciousTimeReasonCode::MinutesInHoursBox, ['suggested' => 2948, 'entered' => self::STEADY_TYPO_SECONDS]),
                    new SuspiciousTimeReason(SuspiciousTimeReasonCode::IncludesBreaks, ['entered' => self::STEADY_TYPO_SECONDS]),
                ],
                suggestedSeconds: 2948,
                score: 49.13,
            ),
            SuspicionFingerprint::ofTime($steadyTypo),
            SuspiciousTimeClassifier::VERSION,
            $now->modify('-1 day'),
        ));

        $markedReasons = [
            new SuspiciousTimeReason(SuspiciousTimeReasonCode::FasterThanUsual, ['expected' => 3600, 'entered' => 1300, 'ratio' => 2.77, 'pieces' => 520, 'source' => 'pace']),
            new SuspiciousTimeReason(SuspiciousTimeReasonCode::HoursLeftOut, ['suggested' => 4900, 'hours' => 1, 'entered' => 1300]),
        ];
        $markedCase = SuspiciousTimeCase::detected(
            Uuid::fromString(self::CASE_MARKED),
            $markedTime,
            new SuspicionAssessment(
                outcome: SuspicionCheckOutcome::Raised,
                tier: SuspiciousTimeTier::Strong,
                ratio: 2.77,
                expectedSeconds: 3600,
                expectedSource: ExpectedTimeSource::Pace,
                reasons: $markedReasons,
                suggestedSeconds: 4900,
                score: 2.77,
            ),
            SuspicionFingerprint::ofTime($markedTime),
            SuspiciousTimeClassifier::VERSION,
            $now->modify('-3 days'),
        );
        $markedCase->mark($markedReasons, 'Please check the hours.', Uuid::fromString(PlayerFixture::PLAYER_ADMIN), SuspicionFingerprint::ofTime($markedTime), $now->modify('-2 days'));
        $manager->persist($markedCase);

        assert($markedCase->markedAt !== null);
        $manager->persist(new SuspiciousTimeNotice(
            id: Uuid::fromString(self::NOTICE_MARKED),
            case: $markedCase,
            player: $marked,
            markedAt: $markedCase->markedAt,
            notifiedAt: $now->modify('-1 day'),
            via: SuspiciousTimeNoticeVia::Run,
        ));

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ManufacturerFixture::class,
            PlayerFixture::class,
        ];
    }

    private function player(ObjectManager $manager, string $id, string $code, string $name): Player
    {
        $player = new Player(
            id: Uuid::fromString($id),
            code: $code,
            userId: 'auth0|' . $code,
            name: $name,
            registeredAt: $this->clock->now(),
        );
        $manager->persist($player);
        $this->addReference($id, $player);

        return $player;
    }

    private function puzzle(ObjectManager $manager, string $id, int $piecesCount, string $name, Player $addedBy, bool $approved = false): Puzzle
    {
        $puzzle = new Puzzle(
            id: Uuid::fromString($id),
            piecesCount: $piecesCount,
            name: $name,
            approved: $approved,
            image: null,
            manufacturer: $this->getReference(ManufacturerFixture::MANUFACTURER_TREFL, Manufacturer::class),
            addedByUser: $addedBy,
            addedAt: $this->clock->now(),
        );
        $manager->persist($puzzle);
        $this->addReference($id, $puzzle);

        return $puzzle;
    }

    private function time(
        ObjectManager $manager,
        string $id,
        Player $player,
        Puzzle $puzzle,
        int $seconds,
        int $daysAgo,
        null|PuzzlersGroup $team = null,
        null|string $comment = null,
        bool $suspicious = false,
        bool $firstAttempt = true,
    ): PuzzleSolvingTime {
        $day = $this->clock->now()->modify("-{$daysAgo} days")->setTime(0, 0);

        $time = new PuzzleSolvingTime(
            id: Uuid::fromString($id),
            secondsToSolve: $seconds,
            player: $player,
            puzzle: $puzzle,
            trackedAt: new DateTimeImmutable($day->format('Y-m-d') . ' 20:00:00'),
            verified: true,
            team: $team,
            finishedAt: $day,
            comment: $comment,
            finishedPuzzlePhoto: null,
            firstAttempt: $firstAttempt,
            unboxed: false,
            suspicious: $suspicious,
            puzzlingTeam: $this->puzzlingTeamResolver->resolve($team),
        );
        $manager->persist($time);
        $this->addReference($id, $time);

        return $time;
    }
}
