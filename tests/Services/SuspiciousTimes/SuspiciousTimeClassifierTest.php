<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SuspiciousTimes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\PaceReference;
use SpeedPuzzling\Web\Value\PaceReferences;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspicionEvidence;
use SpeedPuzzling\Web\Value\SuspicionInput;
use SpeedPuzzling\Web\Value\SuspicionPiecesRange;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * docs/features/suspicious-time-review.md, "Detection" - the classifier is pure, so every rule is pinned here with
 * input rows, first the kinds of times flagged by hand before the feature existed ("The times flagged by hand" -
 * made-up entries with the same piece counts, ratios and causes).
 */
final class SuspiciousTimeClassifierTest extends TestCase
{
    // Measured on the production copy: solo pieces per minute, median and 99.9th percentile per range
    private const float SOLO_500_MEDIAN = 7.71;
    private const float SOLO_500_P999 = 21.08;
    private const float SOLO_1000_MEDIAN = 5.09;
    private const float SOLO_1000_P999 = 14.59;

    private SuspiciousTimeClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new SuspiciousTimeClassifier();
    }

    public function testHoursLeftOutOnABaselineAndOftenInAGroup(): void
    {
        // A 500-piece solo 6.3× faster than the baseline; the player has 30 pair/team 500s at about 15 minutes
        $assessment = $this->classify(500, 760, baseline: 4800, evidence: new SuspicionEvidence(
            otherSoloResults: 120,
            groupResults: array_fill(0, 30, ['pieces' => 500, 'seconds' => 900]),
        ));

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
        self::assertEqualsWithDelta(6.32, $assessment->ratio, 0.01);
        self::assertSame(['faster_than_usual', 'hours_left_out', 'often_in_group'], $assessment->reasonCodes());
        self::assertSame(4360, $assessment->suggestedSeconds, '1:12:40');
    }

    public function testTheClosestMissingHoursWin(): void
    {
        // A 1000-piece solo 5.2× faster than the baseline - of 3:48:00 and 4:48:00 the closer one wins
        $assessment = $this->classify(1000, 2880, baseline: 15000, evidence: new SuspicionEvidence(otherSoloResults: 40));

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
        self::assertSame(['faster_than_usual', 'hours_left_out'], $assessment->reasonCodes());
        self::assertSame(13680, $assessment->suggestedSeconds, '3:48:00');
    }

    public function testTeamSavedAsSolo(): void
    {
        // A 1000-piece solo 5.4× faster than the pace; the comment says it was a team, the teammates saved it that day
        $assessment = $this->classify(1000, 3000, pace: self::paceFor(16200, 1000, self::SOLO_1000_MEDIAN), evidence: new SuspicionEvidence(
            comment: 'Team of 4 at the club night',
            otherSoloResults: 60,
            sameDayGroupResults: [['time_id' => 'team-result', 'seconds' => 3010, 'puzzling_type' => 'team']],
        ));

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Pace);
        self::assertSame(16200, $assessment->expectedSeconds);
        self::assertTrue($assessment->hasReason(SuspiciousTimeReasonCode::TeammatesSavedGroup));
        self::assertSame('Team', $assessment->reason(SuspiciousTimeReasonCode::CommentMentionsGroup)?->param('word'));
        // The rule also finds +4 hours fitting - an explanation, the moderator weighs them
        self::assertTrue($assessment->hasReason(SuspiciousTimeReasonCode::HoursLeftOut));
    }

    public function testHoursLeftOutAgainstABaseline(): void
    {
        // A 1000-piece solo 3.6× faster than the baseline - 2 hours more fit
        $assessment = $this->classify(1000, 2850, baseline: 10200, evidence: new SuspicionEvidence(otherSoloResults: 40));

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
        self::assertSame(10050, $assessment->suggestedSeconds, '2:47:30');
    }

    public function testStrongOnlyThanksToTheExplanation(): void
    {
        // A 1000-piece solo 2.7× faster than the baseline, below the 3.0 of a strong baseline raise - 2 hours more fit
        $assessment = $this->classify(1000, 3720, baseline: 10200, evidence: new SuspicionEvidence(otherSoloResults: 40));

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
        self::assertLessThan(SuspiciousTimeClassifier::USUAL_STRONG_RATIO, $assessment->ratio);
        self::assertSame(10920, $assessment->suggestedSeconds, '3:02:00');

        $withoutTheFit = $this->classify(1000, 3720, baseline: 10200 + 4000);
        self::assertSame(SuspiciousTimeTier::Strong, $withoutTheFit->tier, 'R 3.8 is strong by itself');
    }

    public function testStrongAgainstThePaceByTheRatioAlone(): void
    {
        // A 1000-piece solo 3.0× faster than the pace: strong by the ratio alone
        $assessment = $this->classify(1000, 3660, pace: self::paceFor(11000, 1000, self::SOLO_1000_MEDIAN), evidence: new SuspicionEvidence(otherSoloResults: 25));

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Pace);
        self::assertSame(11000, $assessment->expectedSeconds);
        // By the rule 2 hours more fit the pace (0.99×)
        self::assertSame(['faster_than_usual', 'hours_left_out'], $assessment->reasonCodes());
    }

    public function testFirstResultBeyondEveryKnownPace(): void
    {
        // A first and only result: 10:00 for 500 pieces = 50 PPM, the community's 99.9th percentile is 21
        $assessment = $this->classify(500, 600, evidence: new SuspicionEvidence(otherSoloResults: 0));

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, null);
        self::assertNull($assessment->ratio);
        self::assertSame(['beyond_known_pace', 'new_player'], $assessment->reasonCodes());
        self::assertEqualsWithDelta(50 / self::SOLO_500_P999, $assessment->score, 0.01);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function fastTimesInsideThePlayersOwnBand(): iterable
    {
        yield '1:10:00' => [4200, 4380];
        yield '1:11:00' => [4260, 4400];
        yield '1:12:00' => [4320, 4420];
    }

    #[DataProvider('fastTimesInsideThePlayersOwnBand')]
    public function testFastPlayerInsideTheirOwnBand(int $entered, int $predicted): void
    {
        // Fast 1000s within a few % of the prediction - the player's own history is the evidence
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(1000, $entered, predicted: $predicted)->outcome);
        // Against the usual 1:30:00 it is 1.3× - far below a baseline raise
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(1000, $entered, baseline: 5400)->outcome);
    }

    public function testNormalTimeAfterAPieceCountFixIsNotRaised(): void
    {
        // A 300-piece puzzle once catalogued as 1000 - the time is normal for 300 pieces
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(300, 1500, predicted: 1440)->outcome);
    }

    public function testExpectedTimeChain(): void
    {
        self::assertSame(ExpectedTimeSource::Prediction, $this->classify(500, 3000, predicted: 3600, baseline: 9000, pace: 0.1)->expectedSource);
        self::assertSame(ExpectedTimeSource::Baseline, $this->classify(500, 3000, baseline: 3600, pace: 0.1)->expectedSource);
        self::assertSame(ExpectedTimeSource::Pace, $this->classify(500, 3000, pace: 1.0)->expectedSource);
        self::assertSame(SuspicionCheckOutcome::NoData, $this->classify(500, 3000)->outcome);

        // The baseline × the puzzle's difficulty
        self::assertSame(5400, $this->classify(500, 3000, baseline: 3600, difficulty: 1.5)->expectedSeconds);
        // The pace: relative pace × the community median of the range
        self::assertSame((int) round(500 * 60 / (1.25 * self::SOLO_500_MEDIAN)), $this->classify(500, 3000, pace: 1.25)->expectedSeconds);
    }

    public function testFastAgainstAPredictionAtItsEdges(): void
    {
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(200, 1001, predicted: 2000)->outcome);

        $raised = $this->classify(200, 1000, predicted: 2000);
        $this->assertRaised($raised, SuspicionDirection::Fast, SuspiciousTimeTier::Possible, ExpectedTimeSource::Prediction);
        self::assertSame(['faster_than_predicted'], $raised->reasonCodes());

        $this->assertRaised($this->classify(200, 800, predicted: 2000), SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Prediction);
    }

    public function testFastAgainstABaselineOrPaceAtItsEdges(): void
    {
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(200, 1201, baseline: 3000)->outcome);
        $this->assertRaised($this->classify(200, 1200, baseline: 3000), SuspicionDirection::Fast, SuspiciousTimeTier::Possible, ExpectedTimeSource::Baseline);
        $this->assertRaised($this->classify(200, 1000, baseline: 3000), SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
    }

    public function testAFastRaiseNeedsAtLeastTheCommunityMedianPace(): void
    {
        // A history with days counted - a baseline of 40 hours for 200 pieces - makes an ordinary 42-minute solve 57×
        // "faster": slower than a typical puzzler of the range, so the history is off, not the time
        $ordinary = $this->classify(200, 2520, baseline: 144000);
        self::assertSame(SuspicionCheckOutcome::Clear, $ordinary->outcome);
        self::assertSame(ExpectedTimeSource::Baseline, $ordinary->expectedSource);
        self::assertSame(144000, $ordinary->expectedSeconds);
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(200, 2520, predicted: 144000)->outcome);
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(200, 2520, pace: 0.05)->outcome);

        // From the community median of the range (7.83 PPM for 200-499 pieces) it is judged as before
        $atTheMedian = (int) floor(200 * 60 / 7.83);
        $this->assertRaised($this->classify(200, $atTheMedian, baseline: 144000), SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(200, $atTheMedian + 1, baseline: 144000)->outcome);

        // Without a community reference for the range nothing is known - the rule does not apply
        $this->assertRaised(
            $this->classify(200, 2520, baseline: 144000, references: new PaceReferences()),
            SuspicionDirection::Fast,
            SuspiciousTimeTier::Strong,
            ExpectedTimeSource::Baseline,
        );

        // A slow raise is untouched by it
        $this->assertRaised($this->classify(200, 30000, baseline: 3000), SuspicionDirection::Slow, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
    }

    public function testBeyondKnownPaceAtItsEdge(): void
    {
        $atTheTop = (int) ceil(500 * 60 / self::SOLO_500_P999);

        self::assertSame(SuspicionCheckOutcome::NoData, $this->classify(500, $atTheTop)->outcome);
        self::assertSame(SuspicionCheckOutcome::Raised, $this->classify(500, $atTheTop - 1)->outcome);
        // Without a community reference for the range there is nothing to judge by
        self::assertSame(SuspicionCheckOutcome::NoData, $this->classify(6000, 60, references: new PaceReferences())->outcome);
    }

    public function testSlowerThanPredictedAtItsEdges(): void
    {
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(200, 2999, predicted: 1000)->outcome);

        $raised = $this->classify(200, 3000, predicted: 1000);
        $this->assertRaised($raised, SuspicionDirection::Slow, SuspiciousTimeTier::Possible, ExpectedTimeSource::Prediction);
        self::assertSame(['slower_than_predicted'], $raised->reasonCodes());
        self::assertSame(3.0, $raised->reason(SuspiciousTimeReasonCode::SlowerThanPredicted)?->param('ratio'));
        self::assertSame(3.0, $raised->score);

        $this->assertRaised($this->classify(200, 9999, predicted: 1000), SuspicionDirection::Slow, SuspiciousTimeTier::Possible, ExpectedTimeSource::Prediction);
        $this->assertRaised($this->classify(200, 10000, predicted: 1000), SuspicionDirection::Slow, SuspiciousTimeTier::Strong, ExpectedTimeSource::Prediction);
    }

    public function testSlowerThanUsualAtItsEdges(): void
    {
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(200, 4999, baseline: 1000)->outcome);

        $raised = $this->classify(200, 5000, baseline: 1000);
        $this->assertRaised($raised, SuspicionDirection::Slow, SuspiciousTimeTier::Possible, ExpectedTimeSource::Baseline);
        self::assertSame(['slower_than_usual'], $raised->reasonCodes());

        $this->assertRaised($this->classify(200, 10000, baseline: 1000), SuspicionDirection::Slow, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
    }

    public function testDaysCountedMakeASlowTimeStrong(): void
    {
        // 25 hours against a usual 3 hours = 8.3× - possible by the ratio, strong with the explanation
        $assessment = $this->classify(1000, 90001, baseline: 10800);

        $this->assertRaised($assessment, SuspicionDirection::Slow, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
        self::assertSame(['slower_than_usual', 'includes_breaks'], $assessment->reasonCodes());
    }

    public function testMinutesTypedIntoTheHoursBox(): void
    {
        // 49:08:00 for a usual hour: 49 minutes 8 seconds fits
        $assessment = $this->classify(500, 176880, predicted: 3600, evidence: new SuspicionEvidence(otherSoloResults: 30));

        $this->assertRaised($assessment, SuspicionDirection::Slow, SuspiciousTimeTier::Strong, ExpectedTimeSource::Prediction);
        self::assertSame(['slower_than_predicted', 'minutes_in_hours_box', 'includes_breaks'], $assessment->reasonCodes());
        self::assertSame(2948, $assessment->suggestedSeconds);
        self::assertFalse($assessment->hasReason(SuspiciousTimeReasonCode::HoursLeftOut));
    }

    public function testMinutesInTheHoursBoxRule(): void
    {
        self::assertSame(2948, SuspiciousTimeClassifier::minutesInHoursBox(176880, 3600, null));
        // Seconds in the entry - not a shifted H:M:00
        self::assertNull(SuspiciousTimeClassifier::minutesInHoursBox(176881, 3600, null));
        // Under an hour there is no hours box to blame
        self::assertNull(SuspiciousTimeClassifier::minutesInHoursBox(3540, 59, null));
        // The shifted reading must fit the expectation: 0.75-1.33×
        self::assertSame(2700, SuspiciousTimeClassifier::minutesInHoursBox(45 * 3600, 3600, null));
        self::assertNull(SuspiciousTimeClassifier::minutesInHoursBox(44 * 3600, 3600, null));
        self::assertSame(4788, SuspiciousTimeClassifier::minutesInHoursBox(79 * 3600 + 48 * 60, 3600, null));
        self::assertNull(SuspiciousTimeClassifier::minutesInHoursBox(80 * 3600, 3600, null));
        // Without an expectation: within 0.5-2× the community median time
        self::assertSame(2948, SuspiciousTimeClassifier::minutesInHoursBox(176880, null, 3891));
        self::assertNull(SuspiciousTimeClassifier::minutesInHoursBox(176880, null, 6000));
        self::assertNull(SuspiciousTimeClassifier::minutesInHoursBox(176880, null, null));
    }

    public function testBelowTheSlowFloorWithoutAnExpectation(): void
    {
        // A tenth of the 500-750 median: 0.771 PPM = 10 h 48 min for 500 pieces
        $floorSeconds = (int) floor(500 * 60 / (self::SOLO_500_MEDIAN * SuspiciousTimeClassifier::SLOW_FLOOR_SHARE));

        self::assertSame(SuspicionCheckOutcome::NoData, $this->classify(500, $floorSeconds)->outcome);

        $assessment = $this->classify(500, $floorSeconds + 1, evidence: new SuspicionEvidence(otherSoloResults: 1));
        $this->assertRaised($assessment, SuspicionDirection::Slow, SuspiciousTimeTier::Strong, null);
        self::assertSame(['below_slow_floor', 'new_player'], $assessment->reasonCodes());
        self::assertSame('solo', $assessment->reasons[0]->param('puzzling_type'));
    }

    public function testAConsistentlySlowPlayerIsJudgedByTheirOwnTimes(): void
    {
        // 13.9 h for 500 pieces is below the community floor, but this player predictably takes 5.5 h (2.5× slower)
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(500, 50000, predicted: 20000)->outcome);
        // ... and 4.5× their baseline is still below the 5× of a baseline
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(500, 90000, baseline: 20000)->outcome);
    }

    public function testThePairFloorIsTheirRangeAndPuzzlingType(): void
    {
        // Pairs at 1000 pieces: median 9 PPM, floor 0.9 PPM = 18.5 h; teams: median 12 PPM, floor 1.2 PPM = 13.9 h
        $pair = $this->classify(1000, 90000, type: PuzzlingType::Duo);
        $this->assertRaised($pair, SuspicionDirection::Slow, SuspiciousTimeTier::Strong, null);
        self::assertSame(['below_slow_floor', 'includes_breaks'], $pair->reasonCodes());
        self::assertSame('duo', $pair->reasons[0]->param('puzzling_type'));
        self::assertSame(6667, $pair->reasons[0]->param('median'));

        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(1000, 60000, type: PuzzlingType::Duo)->outcome);
        self::assertSame(SuspicionCheckOutcome::Raised, $this->classify(1000, 60000, type: PuzzlingType::Team)->outcome);
        // The solo floor of the range is lower still (0.509 PPM = 32.7 h)
        self::assertSame(SuspicionCheckOutcome::NoData, $this->classify(1000, 70000)->outcome);
        // No pair reference for the 500-750 range
        self::assertSame(SuspicionCheckOutcome::NoData, $this->classify(500, 999999, type: PuzzlingType::Duo)->outcome);
    }

    public function testPairAndTeamResultsAreNeverJudgedAsFast(): void
    {
        $assessment = $this->classify(1000, 60, type: PuzzlingType::Team, predicted: 3600, baseline: 3600, pace: 1.0);

        self::assertSame(SuspicionCheckOutcome::Clear, $assessment->outcome);
        self::assertNull($assessment->expectedSeconds, 'A pair/team result has no personal expectation');
    }

    public function testMinutesInTheHoursBoxOfAPairAgainstTheCommunity(): void
    {
        // 1000 pieces as a pair: 1:51:00 typed as 111:00:00 - the community median of pairs is 1:51:07
        $assessment = $this->classify(1000, 111 * 3600, type: PuzzlingType::Duo);

        self::assertSame(['below_slow_floor', 'minutes_in_hours_box', 'includes_breaks'], $assessment->reasonCodes());
        self::assertSame(6660, $assessment->suggestedSeconds);
    }

    public function testHoursLeftOutAtItsEdges(): void
    {
        self::assertSame(1, SuspiciousTimeClassifier::hoursLeftOut(900, 3600));
        // 0.75 of the expected time is in, just below it is out
        self::assertSame(1, SuspiciousTimeClassifier::hoursLeftOut(900, 6000));
        self::assertNull(SuspiciousTimeClassifier::hoursLeftOut(899, 6000));
        // 1.33 is in, just above it is out
        self::assertSame(1, SuspiciousTimeClassifier::hoursLeftOut(9700, 10000));
        self::assertNull(SuspiciousTimeClassifier::hoursLeftOut(9701, 10000));
        // The closest of several wins
        self::assertSame(3, SuspiciousTimeClassifier::hoursLeftOut(2880, 15000));
        // At most 5 hours
        self::assertNull(SuspiciousTimeClassifier::hoursLeftOut(600, 25000));
        self::assertSame(5, SuspiciousTimeClassifier::hoursLeftOut(600, 19000));
    }

    public function testTeammatesSavedAGroupWithinTwoMinutes(): void
    {
        $within = $this->classify(500, 600, baseline: 3600, evidence: new SuspicionEvidence(
            otherSoloResults: 30,
            sameDayGroupResults: [
                ['time_id' => 'far', 'seconds' => 721, 'puzzling_type' => 'duo'],
                ['time_id' => 'near', 'seconds' => 720, 'puzzling_type' => 'team'],
            ],
        ));

        $teammates = $within->reason(SuspiciousTimeReasonCode::TeammatesSavedGroup);
        self::assertNotNull($teammates);
        self::assertSame('near', $teammates->param('time_id'));
        self::assertSame('team', $teammates->param('puzzling_type'));

        $outside = $this->classify(500, 600, baseline: 3600, evidence: new SuspicionEvidence(
            otherSoloResults: 30,
            sameDayGroupResults: [['time_id' => 'far', 'seconds' => 721, 'puzzling_type' => 'duo']],
        ));

        self::assertFalse($outside->hasReason(SuspiciousTimeReasonCode::TeammatesSavedGroup));
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function comments(): iterable
    {
        yield 'English team' => ['Team of 4, regional speed puzzling championship', 'Team'];
        yield 'teammates' => ['with my teammates', 'teammates'];
        yield 'partner' => ['Solved with my partner', 'partner'];
        yield 'pair' => ['We did it as a pair', 'pair'];
        yield 'together' => ['done together', 'together'];
        yield 'Czech team' => ['Týmová výzva v klubu', 'Týmová'];
        yield 'Czech pair' => ['skládali jsme ve dvou', 've dvou'];
        yield 'Czech dvojice' => ['ve dvojici s manželem', 'dvojici'];
        yield 'German pair' => ['Zu zweit gelegt', 'Zu zweit'];
        yield 'German Paar' => ['Als Paar gelegt', 'Paar'];
        yield 'Spanish' => ['con mi pareja', 'pareja'];
        yield 'Spanish team' => ['en equipo', 'equipo'];
        yield 'French' => ['fait en équipe', 'équipe'];
        yield 'French à deux' => ['fait à deux', 'à deux'];
        yield 'French binôme' => ['en binôme', 'binôme'];
        yield 'Japanese team' => ['チームで完成', 'チーム'];
        yield 'Japanese pair' => ['ペアで完成', 'ペア'];
        yield 'German a few' => ['Ein paar Teile fehlten', null];
        yield 'steam' => ['Steam engine puzzle, loved it', null];
        yield 'repair' => ['Had to repair the box', null];
        yield 'Japanese spare' => ['スペアのピース', null];
        yield 'nothing' => ['Fast one today!', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('comments')]
    public function testCommentMentionsAGroup(string $comment, null|string $word): void
    {
        self::assertSame($word, SuspiciousTimeClassifier::groupWordIn($comment));
    }

    public function testOftenInAGroupNeedsThreeResultsThatFit(): void
    {
        $twoFitting = $this->classify(500, 900, baseline: 3600, evidence: new SuspicionEvidence(
            otherSoloResults: 30,
            groupResults: array_fill(0, 2, ['pieces' => 500, 'seconds' => 1000]),
        ));
        self::assertFalse($twoFitting->hasReason(SuspiciousTimeReasonCode::OftenInGroup));

        $threeFitting = $this->classify(500, 900, baseline: 3600, evidence: new SuspicionEvidence(
            otherSoloResults: 30,
            groupResults: array_fill(0, 3, ['pieces' => 500, 'seconds' => 1000]),
        ));
        self::assertSame(1000, $threeFitting->reason(SuspiciousTimeReasonCode::OftenInGroup)?->param('group_expected'));
        // A moderator hint, never a fitting explanation
        self::assertFalse(SuspiciousTimeReasonCode::OftenInGroup->isShownToPlayer());

        $notFitting = $this->classify(500, 900, baseline: 3600, evidence: new SuspicionEvidence(
            otherSoloResults: 30,
            groupResults: array_fill(0, 3, ['pieces' => 500, 'seconds' => 2000]),
        ));
        self::assertFalse($notFitting->hasReason(SuspiciousTimeReasonCode::OftenInGroup));
    }

    public function testOtherEdition(): void
    {
        // 1:10:00 on a 1000-piece puzzle for a player who takes 3:30:00 there - fits their 500-piece edition
        $editions = [
            ['puzzle_id' => 'five-hundred', 'name' => 'Lighthouse Cove', 'pieces' => 500, 'similarity' => 0.6],
            ['puzzle_id' => 'fifteen-hundred', 'name' => 'Lighthouse Cove', 'pieces' => 1500, 'similarity' => 1.0],
        ];

        $assessment = $this->classify(1000, 4200, baseline: 12600, evidence: new SuspicionEvidence(otherSoloResults: 30, otherEditions: $editions));

        $edition = $assessment->reason(SuspiciousTimeReasonCode::OtherEdition);
        self::assertNotNull($edition);
        self::assertSame('five-hundred', $edition->param('puzzle_id'));
        self::assertSame(500, $edition->param('pieces'));
        self::assertSame(SuspiciousTimeTier::Strong, $assessment->tier);

        $notSimilar = $this->classify(1000, 4200, baseline: 12600, evidence: new SuspicionEvidence(otherSoloResults: 30, otherEditions: [
            ['puzzle_id' => 'five-hundred', 'name' => 'Lighthouse', 'pieces' => 500, 'similarity' => 0.59],
        ]));
        self::assertFalse($notSimilar->hasReason(SuspiciousTimeReasonCode::OtherEdition));

        $noReference = $this->classify(1000, 4200, baseline: 12600, evidence: new SuspicionEvidence(otherSoloResults: 30, otherEditions: [
            ['puzzle_id' => 'six-thousand', 'name' => 'Lighthouse Cove', 'pieces' => 6000, 'similarity' => 1.0],
        ]));
        self::assertFalse($noReference->hasReason(SuspiciousTimeReasonCode::OtherEdition));
    }

    public function testFastestOnThePuzzle(): void
    {
        $fewOthers = $this->classify(500, 900, baseline: 3600, evidence: new SuspicionEvidence(otherSoloResults: 30, otherResultsOnPuzzle: 4, fastestOtherSeconds: 1500));
        self::assertFalse($fewOthers->hasReason(SuspiciousTimeReasonCode::FastestOnPuzzle));

        $fastest = $this->classify(500, 900, baseline: 3600, evidence: new SuspicionEvidence(otherSoloResults: 30, otherResultsOnPuzzle: 5, fastestOtherSeconds: 1500));
        self::assertSame(1500, $fastest->reason(SuspiciousTimeReasonCode::FastestOnPuzzle)?->param('fastest'));

        $equal = $this->classify(500, 900, baseline: 3600, evidence: new SuspicionEvidence(otherSoloResults: 30, otherResultsOnPuzzle: 5, fastestOtherSeconds: 900));
        self::assertFalse($equal->hasReason(SuspiciousTimeReasonCode::FastestOnPuzzle));
    }

    public function testNewPlayerAndConfirmedWhileSaving(): void
    {
        $assessment = $this->classify(500, 900, baseline: 3600, evidence: new SuspicionEvidence(otherSoloResults: 4, confirmedExpectedSeconds: 3500));

        self::assertSame(4, $assessment->reason(SuspiciousTimeReasonCode::NewPlayer)?->param('results'));
        self::assertSame(3500, $assessment->reason(SuspiciousTimeReasonCode::ConfirmedWhileSaving)?->param('expected'));
        self::assertSame(['faster_than_usual', 'hours_left_out'], array_map(
            static fn ($reason): string => $reason->code->value,
            $assessment->reasonsShownToPlayer(),
        ));

        self::assertFalse($this->classify(500, 900, baseline: 3600, evidence: new SuspicionEvidence(otherSoloResults: 5))->hasReason(SuspiciousTimeReasonCode::NewPlayer));
    }

    public function testExplanationsOfOneDirectionNeverComeWithTheOther(): void
    {
        foreach (SuspiciousTimeReasonCode::cases() as $code) {
            if ($code->isTrigger()) {
                self::assertNotNull($code->direction());
            }
        }

        $fast = $this->classify(500, 900, baseline: 3600);
        $slow = $this->classify(500, 176880, baseline: 3600);

        self::assertFalse($fast->hasReason(SuspiciousTimeReasonCode::MinutesInHoursBox));
        self::assertFalse($slow->hasReason(SuspiciousTimeReasonCode::HoursLeftOut));
        self::assertSame(SuspicionDirection::Fast, $fast->direction());
        self::assertSame(SuspicionDirection::Slow, $slow->direction());
    }

    public function testHonestSecondAttemptAfterASlowTypoIsNotRaised(): void
    {
        // 99 pieces: the first attempt went in as 9:10:00, so the personal prediction of the second is 9:25:07 - the
        // honest 6:20 is no faster than the player's usual 6:40
        $assessment = $this->classify(99, 380, predicted: 33907, baseline: 400, previous: 33000);

        self::assertSame(SuspicionCheckOutcome::Clear, $assessment->outcome);
        self::assertSame(ExpectedTimeSource::Baseline, $assessment->expectedSource);
        self::assertSame(400, $assessment->expectedSeconds);
    }

    public function testGenuinelyFastSecondAttemptAfterASlowTypoIsStillRaised(): void
    {
        $assessment = $this->classify(99, 100, predicted: 33907, baseline: 400, previous: 33000);

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
        self::assertSame(['faster_than_usual', 'prediction_from_slow_attempt'], $assessment->reasonCodes());
        self::assertSame(
            ['predicted' => 33907, 'previous' => 33000, 'raised_slow' => false],
            $assessment->reason(SuspiciousTimeReasonCode::PredictionFromSlowAttempt)?->params,
        );
        self::assertFalse(SuspiciousTimeReasonCode::PredictionFromSlowAttempt->isShownToPlayer());
        self::assertSame(['faster_than_usual'], array_map(
            static fn ($reason): string => $reason->code->value,
            $assessment->reasonsShownToPlayer(),
        ));
    }

    public function testSecondAttemptAfterASlowTypoMayStillBeTooSlow(): void
    {
        // 4:26:40 is twice as fast as the inflated prediction, but 40× the usual 6:40
        $assessment = $this->classify(99, 16000, predicted: 33907, baseline: 400, previous: 33000);

        $this->assertRaised($assessment, SuspicionDirection::Slow, SuspiciousTimeTier::Strong, ExpectedTimeSource::Baseline);
        self::assertSame(['slower_than_usual', 'prediction_from_slow_attempt'], $assessment->reasonCodes());
    }

    public function testPlausibleEarlierAttemptKeepsThePrediction(): void
    {
        // The attempt before took 10:00 - below 5× the usual 6:40, so the prediction stands
        $assessment = $this->classify(99, 380, predicted: 900, baseline: 400, previous: 600);

        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Possible, ExpectedTimeSource::Prediction);
        self::assertSame(['faster_than_predicted'], $assessment->reasonCodes());

        // Exactly 5× the usual is too slow
        self::assertSame(SuspicionCheckOutcome::Clear, $this->classify(99, 380, predicted: 900, baseline: 400, previous: 2000)->outcome);
    }

    public function testEarlierAttemptWithASlowCaseSendsTheTimeToThePace(): void
    {
        $pace = self::paceFor(500, 99, 12.71);

        $honest = $this->classify(99, 550, predicted: 1200, pace: $pace, previous: 1500, previousRaisedSlow: true);
        self::assertSame([SuspicionCheckOutcome::Clear, ExpectedTimeSource::Pace], [$honest->outcome, $honest->expectedSource]);

        $fast = $this->classify(99, 120, predicted: 1200, pace: $pace, previous: 1500, previousRaisedSlow: true);
        $this->assertRaised($fast, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Pace);
        self::assertTrue($fast->reason(SuspiciousTimeReasonCode::PredictionFromSlowAttempt)?->param('raised_slow'));

        // Without the case, 1500 against a usual 500 is only 3× - the prediction stands (7:40 is faster than the
        // community median of the range, so it can be raised as fast at all)
        $trusted = $this->classify(99, 460, predicted: 1100, pace: $pace, previous: 1500);
        $this->assertRaised($trusted, SuspicionDirection::Fast, SuspiciousTimeTier::Possible, ExpectedTimeSource::Prediction);
    }

    public function testWithoutOtherExpectationTheCommunityFloorJudgesTheEarlierAttempt(): void
    {
        // A first attempt of 10 hours for 99 pieces is below the community's slow floor (1.27 PPM): the honest 6:20
        // is judged like a new player's - inside the community's range, nothing to judge by
        self::assertSame(SuspicionCheckOutcome::NoData, $this->classify(99, 380, predicted: 33907, previous: 36000)->outcome);

        // A first attempt of 50 minutes is not: the prediction stands
        $assessment = $this->classify(99, 380, predicted: 3600, previous: 3000);
        $this->assertRaised($assessment, SuspicionDirection::Fast, SuspiciousTimeTier::Strong, ExpectedTimeSource::Prediction);
    }

    public function testThePaceIsNeededWhenAPersonalPredictionMayBeReplaced(): void
    {
        self::assertTrue(SuspiciousTimeClassifier::needsPace(null, null));
        self::assertFalse(SuspiciousTimeClassifier::needsPace(3600, null));
        self::assertFalse(SuspiciousTimeClassifier::needsPace(null, 3000));
        self::assertTrue(SuspiciousTimeClassifier::needsPace(3600, null, 1800, 900));
        self::assertFalse(SuspiciousTimeClassifier::needsPace(3600, null, 1801, 900), 'Not raised against the prediction anyway');
        self::assertFalse(SuspiciousTimeClassifier::needsPace(3600, null, 100, null), 'Not a personal prediction');
        self::assertFalse(SuspiciousTimeClassifier::needsPace(3600, 3000, 100, 900), 'The baseline comes first');
    }

    private function classify(
        int $piecesCount,
        int $seconds,
        PuzzlingType $type = PuzzlingType::Solo,
        null|int $predicted = null,
        null|int $baseline = null,
        null|float $difficulty = null,
        null|float $pace = null,
        null|SuspicionEvidence $evidence = null,
        null|PaceReferences $references = null,
        null|int $previous = null,
        bool $previousRaisedSlow = false,
    ): SuspicionAssessment {
        return $this->classifier->classify(new SuspicionInput(
            piecesCount: $piecesCount,
            seconds: $seconds,
            puzzlingType: $type,
            predictedSeconds: $predicted,
            baselineSeconds: $baseline,
            difficultyScore: $difficulty,
            paceFactor: $pace,
            references: $references ?? self::references(),
            evidence: $evidence,
            previousAttemptSeconds: $previous,
            previousAttemptRaisedSlow: $previousRaisedSlow,
        ));
    }

    private static function references(): PaceReferences
    {
        return new PaceReferences([
            new PaceReference(SuspicionPiecesRange::UpTo199, PuzzlingType::Solo, 12.71, 31.54, 28322),
            new PaceReference(SuspicionPiecesRange::From200, PuzzlingType::Solo, 7.83, 18.97, 30666),
            new PaceReference(SuspicionPiecesRange::From500, PuzzlingType::Solo, self::SOLO_500_MEDIAN, self::SOLO_500_P999, 343000),
            new PaceReference(SuspicionPiecesRange::From999, PuzzlingType::Solo, self::SOLO_1000_MEDIAN, self::SOLO_1000_P999, 18670),
            new PaceReference(SuspicionPiecesRange::From1201, PuzzlingType::Solo, 3.53, 19.2, 900),
            new PaceReference(SuspicionPiecesRange::From999, PuzzlingType::Duo, 9.0, 30.0, 4000),
            new PaceReference(SuspicionPiecesRange::From999, PuzzlingType::Team, 12.0, 40.0, 1500),
        ]);
    }

    /**
     * The relative pace that makes the pace fallback expect exactly these seconds.
     */
    private static function paceFor(int $expectedSeconds, int $piecesCount, float $medianPpm): float
    {
        return $piecesCount * 60 / ($expectedSeconds * $medianPpm);
    }

    private function assertRaised(
        SuspicionAssessment $assessment,
        SuspicionDirection $direction,
        SuspiciousTimeTier $tier,
        null|ExpectedTimeSource $source,
    ): void {
        self::assertSame(SuspicionCheckOutcome::Raised, $assessment->outcome);
        self::assertSame($direction, $assessment->direction());
        self::assertSame($tier, $assessment->tier);
        self::assertSame($source, $assessment->expectedSource);
    }
}
