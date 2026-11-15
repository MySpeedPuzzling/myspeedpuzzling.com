<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A result may know only the month or the year it was solved in (docs/features/data-import/date-precision.md):
 * `puzzle_solving_time.finished_at` is then the FIRST DAY of that period - an invented day - and
 * `finished_at_precision` says so. Whatever needs a real day (a calendar day, a streak, a weekday, "the same day", a
 * week, matching an event by its date, "which solve was first") must not take that day as one.
 *
 * So every file whose SQL (comments aside) reads `finished_at` of `puzzle_solving_time` either names
 * `finished_at_precision` / a `DatePrecision::sql…()` fragment - it made its decision in code - or is listed here with
 * the reason the first day of the period is right for it. A new reader fails this test until its author has decided.
 * The table of every reader and its decision is in the doc.
 */
final class DatePrecisionCoverageTest extends TestCase
{
    private const string ORDER = 'Chronological order of a player\'s results (the insights order): a result known to its month or year stands at the start of its period - the earliest moment it can have happened, so nothing reads data from after it. Only "which solve of a puzzle was first" is decided otherwise (DatePrecision::sqlLatestPossibleMoment(), PlayerBaselineCalculator).';
    private const string LONG_WINDOW = 'A window of months or years back from now (recent form, activity, a rating window): whether a result of "March 2021" is inside is the same question on any day of that month, give or take the month itself.';
    private const string FIRST_LAST = 'The first / last time a puzzle or a person was solved with, as a moment to sort by or to word roughly ("3 years ago"): the start of the period.';
    private const string INSIGHTS = 'The Insights page (not public yet, INSIGHTS_PUBLIC): reads the solve moment to order results and for long windows; its weekday and "n-th solve of the day" come from InsightsRowsPreparer, which leaves results without a day out.';

    /** Files reading the solve day in SQL without a word about its precision */
    private const array ANCHOR_IS_RIGHT = [
        'MessageHandler/SnapshotPlayerInsightsHandler.php' => self::INSIGHTS,
        'Query/AchievementSqlRules.php' => 'Night Owl: the logging day equals the solve day - never a spreadsheet import (`created_via`), and only imports store a precision.',
        'Query/FindSimilarSpeedPuzzler.php' => self::LONG_WINDOW,
        'Query/GetCandidatePrediction.php' => self::ORDER,
        'Query/GetCoPuzzlers.php' => self::FIRST_LAST . ' The shortcut score decays over a half-life of months.',
        'Query/GetComparisonPeople.php' => self::FIRST_LAST,
        'Query/GetCompetitionParticipants.php' => self::LONG_WINDOW,
        'Query/GetEventResultClaims.php' => 'An organizer\'s imported event result is dated by the event\'s day; the claimer\'s own candidates are found by puzzle and time, the date is only shown beside them (left as a day - listed in the doc under "What is left").',
        'Query/GetExportablePuzzleSolveSummary.php' => self::FIRST_LAST . ' The results export carries `date_precision` per result.',
        'Query/GetGroupPredictionResults.php' => self::ORDER,
        'Query/GetImageEstimateEvaluationRows.php' => self::ORDER,
        'Query/GetInsightsActivity.php' => self::INSIGHTS,
        'Query/GetInsightsFingerprint.php' => self::INSIGHTS,
        'Query/GetInsightsGroupResults.php' => self::INSIGHTS,
        'Query/GetInsightsRatingInputs.php' => self::INSIGHTS,
        'Query/GetInsightsResolveCandidates.php' => self::INSIGHTS,
        'Query/GetPersonalEstimates.php' => self::ORDER,
        'Query/GetPlayerPaces.php' => self::ORDER,
        'Query/GetPlayerPrediction.php' => self::ORDER,
        'Query/GetPlayerPredictions.php' => self::ORDER,
        'Query/GetPlayerPuzzleSolves.php' => self::FIRST_LAST,
        'Query/GetPlayerPuzzleTimes.php' => self::FIRST_LAST,
        'Query/GetPredictionPairRows.php' => self::ORDER,
        'Query/GetProgressionDrift.php' => self::ORDER,
        'Query/GetPuzzlePickerSuggestions.php' => self::FIRST_LAST . ' "Solved since" filters are windows of months.',
        'Query/GetSeatingSeedTimes.php' => self::LONG_WINDOW,
        'Query/GetSolveXpDisplayInfo.php' => self::ORDER . ' XP of a result solved before the launch is the history formula whatever its day.',
        'Query/GetSolvingTimePrediction.php' => self::ORDER,
        'Query/GetSuspicionEntryFacts.php' => self::ORDER . ' "The same day" evidence is GetSuspiciousTimeEvidence, which asks for two real days.',
        'Query/GetSuspiciousTimeCandidates.php' => self::ORDER,
        'Query/SearchComparisonTeams.php' => self::FIRST_LAST,
        'Services/Insights/GroupCoordinationFitter.php' => self::ORDER,
        'Services/Insights/InsightsProfileRecalculator.php' => self::ORDER,
        'Services/PuzzleIntelligence/DerivedMetricsCalculator.php' => self::ORDER,
        'Services/PuzzleIntelligence/ImprovementRatioCalculator.php' => self::ORDER,
        'Services/PuzzleIntelligence/Model/AttemptFlags.php' => self::ORDER,
        'Services/PuzzleIntelligence/Model/RepeatHistory.php' => self::ORDER,
        'Services/PuzzleIntelligence/Model/UnboxedFirstTries.php' => self::ORDER,
        'Services/PuzzleIntelligence/MspRatingCalculator.php' => self::ORDER . ' The rating window is ' . self::LONG_WINDOW,
        'Services/PuzzleIntelligence/RepeatResiduals.php' => self::ORDER,
        'Services/Xp/XpChainRecomputer.php' => self::ORDER . ' The weekly boost applies from the launch on; a result dated to a month or year before it earns the history formula (writer.md "XP"), one of the launch\'s own month the base like any back-dated result.',
        'Services/Xp/XpHistoryReplay.php' => self::ORDER,
    ];

    public function testEveryReaderOfTheSolveDayHasDecidedWhatAMonthOrAYearIs(): void
    {
        $undecided = [];
        $stale = self::ANCHOR_IS_RIGHT;
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // The SQL lives in strings - a comment mentioning the column decides nothing
            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            if (
                str_contains($code, 'puzzle_solving_time') === false
                || preg_match('/(?<![A-Za-z_>$\'])finished_at(?![A-Za-z_\'])/', $code) !== 1
                || str_contains($code, 'finished_at_precision')
                || str_contains($code, 'DatePrecision::sql')
            ) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            unset($stale[$relative]);

            if (array_key_exists($relative, self::ANCHOR_IS_RIGHT) === false) {
                $undecided[] = $relative;
            }
        }

        sort($undecided);

        self::assertSame([], $undecided, 'These files read `finished_at` of puzzle_solving_time without a word about `finished_at_precision`. A result known to its month or year only stands on the first day of it: leave such results out (`finished_at_precision IS NULL`), compare periods (DatePrecision::sqlSolvedPeriod()), or list the file here with the reason the first day is right - and add it to the table in docs/features/data-import/date-precision.md.');
        self::assertSame([], array_keys($stale), 'These files now decide in code or no longer read the solve day - remove them from the list.');
    }

    /**
     * The migration that added the column must stay what it is: a nullable column without a default - Postgres adds
     * it by changing the catalogue only (no rewrite, no scan of this big, hot table).
     */
    public function testTheColumnWasAddedWithoutRewritingTheTable(): void
    {
        $migration = (string) file_get_contents(__DIR__ . '/../migrations/Version20261115083249.php');

        self::assertStringContainsString("\$this->addSql('ALTER TABLE puzzle_solving_time ADD finished_at_precision VARCHAR(8) DEFAULT NULL');", $migration);
        self::assertSame(1, substr_count($migration, 'addSql(') - substr_count($migration, 'DROP finished_at_precision'), 'The migration\'s up() is one statement.');
        self::assertStringNotContainsString('NOT NULL', $migration);
        self::assertStringNotContainsString('UPDATE ', $migration);
        self::assertStringNotContainsString('CREATE INDEX', $migration);
    }
}
