<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Query\CountsAsSolve;

/**
 * An unfinished competition result (`pieces_placed` set, never a `seconds_to_solve` - PuzzleSolvingTime's invariant) is
 * not a solve (docs/features/competitions-management/unfinished-results-plan.md "Refined 2026-11-16", D9 in
 * docs/features/event-results-import/README.md): every count of completed puzzles leaves it out, "pieces puzzled" takes
 * its pieces placed and "time spent puzzling" its `seconds_spent`. Raw SQL is invisible to PHPStan, so every file whose
 * code (comments aside) reads puzzle_solving_time - in SQL, in DQL (`->from(PuzzleSolvingTime::class` /
 * `join(PuzzleSolvingTime::class`) or as a handler of PuzzleSolved - either uses CountsAsSolve (and so decided where it
 * counts, sums or neither), or is classified here with the reason it needs nothing - or fails this test until its
 * author has made that decision.
 */
final class SolvingTimeReadersAreClassifiedTest extends TestCase
{
    // Tier 1 - an unfinished result never has a time, so it can never change these
    private const string PERFORMANCE = 'performance: reads timed rows only (`seconds_to_solve IS NOT NULL` / `> 0`, or MIN / AVG / percentiles of it) - an unfinished result never has a time to solve.';

    // Tier 3 - shown, labelled from step 2 of the plan
    private const string HISTORY = 'history: lists or links individual results (profile, feeds, round pages, result detail, exports, notifications) - an unfinished result is one of them, labelled from step 2 of the plan.';

    private const string NEUTRAL = 'neutral: write side or integrity (moves, deletes, unlinks, team backfills), a read by id, an "is it in use / does it have results" check, ids for a sitemap or a cron - no figure about solves.';

    private const string ADMIN = 'neutral: admin / moderation tooling showing every result raw (or every linked result before a delete) - an unfinished result is a result there.';

    // Kept on purpose: decision 4 of the plan (an unfinished result is an active day), puzzling together, an attempt
    private const string ACTIVITY = 'activity: when or with whom the player puzzled (an active day, a pair puzzled together, an attempt before a first try) - an unfinished result is puzzling, kept on purpose (decision 4 of the plan).';

    private const string PREDICTIONS = 'performance: stored predictions and the model features of timed attempts - unfinished results are excluded from every calculator (plan "Ratings, skill, difficulty"), nothing in src/Services/PuzzleIntelligence changes.';

    private const string DATA_IMPORT = 'neutral: the spreadsheet import - matches the player\'s own results (by id, or day + time) so nothing is imported twice; an import never writes an unfinished result, and "nothing to wish for" (ImportNeverTwice::hasSolved()) leaves one out.';

    private const string PHOTOS = 'neutral: players\' finished-puzzle photo files and their analyses - no figure; an unfinished result\'s photo (not of a finished puzzle) is left out of the work list.';

    // Decides in PHP: the file must read piecesPlaced
    private const string SOLVE_IN_PHP = 'solve-count in PHP: reads the entity and skips an unfinished result itself (`piecesPlaced !== null`).';

    /** Files reading puzzle_solving_time without CountsAsSolve */
    private const array CLASSIFIED = [
        'ConsoleCommands/RecalculatePuzzleStatisticsConsoleCommand.php' => 'neutral: picks the puzzles to recompute - PuzzleStatisticsCalculator leaves unfinished results out of the counts.',
        'ConsoleCommands/WarmupImgproxyCacheConsoleCommand.php' => self::NEUTRAL,
        'Controller/EditTimeController.php' => 'neutral: only the edit form\'s name contains the table\'s name - no query.',
        'MessageHandler/BackfillPuzzlingTeamsHandler.php' => self::NEUTRAL,
        'MessageHandler/BackfillRoundPuzzleRevealsHandler.php' => self::NEUTRAL,
        'MessageHandler/CleanupEmptyPuzzlingTeamsHandler.php' => self::NEUTRAL,
        'MessageHandler/ConvertCompetitionToSeriesHandler.php' => self::NEUTRAL,
        'MessageHandler/DeleteCompetitionHandler.php' => self::NEUTRAL,
        'MessageHandler/DeleteCompetitionRoundHandler.php' => self::NEUTRAL,
        'MessageHandler/DeleteCompetitionSeriesHandler.php' => self::NEUTRAL,
        'MessageHandler/DeletePlayerHandler.php' => self::NEUTRAL,
        'MessageHandler/NotifyWhenPuzzleSolved.php' => 'history: favourites are told about the result (decision 5 of the plan) - labelled from step 2.',
        'MessageHandler/RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange.php' => self::PERFORMANCE,
        'MessageHandler/RemoveFromWishListWhenPuzzleSolved.php' => self::SOLVE_IN_PHP,
        'MessageHandler/SnapshotPlayerInsightsHandler.php' => self::PERFORMANCE,
        'Query/CountCompetitionResults.php' => 'history: "N results" of an event and per round counts the results its round pages list - an unfinished result is listed there (GetRoundResults), so it counts as a result of the event.',
        'Query/FindSimilarSpeedPuzzler.php' => 'performance + activity: the paces are timed only; "active in the last 12 months" takes any solo result (relax ones too) - an unfinished result is activity.',
        'Query/GetAdminCompetitions.php' => self::ADMIN,
        'Query/GetAdminSeries.php' => self::ADMIN,
        'Query/GetAllPlayerIdsWithSolveTimes.php' => 'neutral: candidate players for the XP / badge recalculations - an extra one costs nothing, the recalculation itself leaves unfinished results out.',
        'Query/GetBotTrustEligibility.php' => 'neutral: the bot-blocker trust cookie - "has logged a result" is evidence of a real person, an unfinished result too.',
        'Query/GetBrandDirectory.php' => self::PERFORMANCE,
        'Query/GetBrandHub.php' => self::PERFORMANCE,
        'Query/GetEventWizardStructureLock.php' => self::NEUTRAL,
        'Query/GetAnalyticsStudioEntrantHistory.php' => self::SOLVE_IN_PHP . ' The analytics studio\'s attempts on a round puzzle (AnalyticsStudioAttempt::countsAsSolve()) - an unfinished one is listed with its pieces, never a solve or a time.',
        'Query/GetAnalyticsStudioLineupHistory.php' => self::SOLVE_IN_PHP . ' The analytics studio\'s line-up attempts - folded like the members\' (AnalyticsStudioAttempts).',
        'Query/GetAnalyticsStudioPuzzleStats.php' => self::PERFORMANCE,
        'Query/GetAnalyticsStudioSimilarHistory.php' => self::PERFORMANCE,
        'Query/GetCandidatePrediction.php' => self::PREDICTIONS,
        'Query/GetCatalogueNumbers.php' => self::PERFORMANCE,
        'Query/GetCloseCalls.php' => self::PERFORMANCE,
        'Query/GetCoPuzzlers.php' => self::ACTIVITY,
        'Query/GetComparisonPeople.php' => self::ACTIVITY,
        'Query/GetComparisonResults.php' => self::PERFORMANCE,
        'Query/GetCompetitionParticipants.php' => 'performance: the solved count is COUNT(CASE WHEN … THEN seconds_to_solve END) - timed rows only.',
        'Query/GetCompetitionPuzzles.php' => 'history: the puzzles puzzled at the event, ordered by how often - an unfinished result was the event\'s puzzle too.',
        'Query/GetCompetitionSlugsForSitemap.php' => self::NEUTRAL,
        'Query/GetContentDigestRecipients.php' => 'history: who gets a digest - "had a result this period" is anything the digest lists (PlayerPeriodActivityReader lists an unfinished result and counts its pieces).',
        'Query/GetDuplicateCandidates.php' => 'neutral: finds a result saved twice - pairs timed rows; a copy is a copy.',
        'Query/GetDuplicatePuzzleSignalCandidates.php' => 'neutral: catalogue duplicates from timed results.',
        'Query/GetEventResultClaims.php' => self::NEUTRAL,
        'Query/GetExportableSolvingTimes.php' => self::HISTORY,
        'Query/GetFastestGroups.php' => self::PERFORMANCE,
        'Query/GetFastestPairs.php' => self::PERFORMANCE,
        'Query/GetFastestPlayers.php' => self::PERFORMANCE,
        'Query/GetFinishedPhotoAnalyses.php' => self::PHOTOS,
        'Query/GetFinishedPhotosForAnalysis.php' => self::PHOTOS,
        'Query/GetFirstTryTimes.php' => 'activity: first-try integrity - an earlier unfinished attempt is earlier contact with the puzzle, a later solve is no first try.',
        'Query/GetGettingStartedProgress.php' => 'history: "has logged a puzzle" - any result logged by the player ticks the onboarding step.',
        'Query/GetGroupPredictionResults.php' => self::PREDICTIONS,
        'Query/GetGroupPuzzleFirstAttempts.php' => 'predictions: emits the timed qualifying first attempts of PlayerBaselineCalculator - the pair/team subquery only picks the puzzles.',
        'Query/GetGuestLinkRequests.php' => self::HISTORY,
        'Query/GetImageEstimateEvaluationRows.php' => self::PREDICTIONS,
        'Query/GetImportPuzzleCandidates.php' => self::DATA_IMPORT,
        'Query/GetImportPuzzleScreen.php' => self::DATA_IMPORT,
        'Query/GetImportRowsOnProfile.php' => self::DATA_IMPORT,
        'Query/GetInsightsFingerprint.php' => 'neutral: a cache key over the player\'s results - pieces_placed and seconds_spent are part of it (concat_ws skips NULLs, so no key changed).',
        'Query/GetInsightsGroupResults.php' => self::PERFORMANCE,
        'Query/GetInsightsPaceHistory.php' => 'predictions: joins player_pace_history, written for timed qualifying attempts only (PlayerPaceHistoryWriter).',
        'Query/GetInsightsRatingInputs.php' => self::PERFORMANCE,
        'Query/GetInsightsResolveCandidates.php' => 'performance + activity: attempts are timed only; "last done at all" takes any result - an unfinished attempt is doing it.',
        'Query/GetInsightsResultCount.php' => self::PERFORMANCE,
        'Query/GetInsightsSoloResults.php' => self::PERFORMANCE,
        'Query/GetMyDataResultContext.php' => self::NEUTRAL,
        'Query/GetMyDataResults.php' => self::HISTORY,
        'Query/GetNotifications.php' => self::HISTORY,
        'Query/GetParticipantsSheetState.php' => self::NEUTRAL,
        'Query/GetPersonalEstimates.php' => self::PREDICTIONS,
        'Query/GetPiecesHub.php' => self::PERFORMANCE,
        'Query/GetPlayerBaselineProgress.php' => self::PERFORMANCE,
        'Query/GetPlayerBestSoloTimes.php' => self::PERFORMANCE,
        'Query/GetPlayerDuplicateCases.php' => self::NEUTRAL,
        'Query/GetPlayerIdsForSitemap.php' => self::NEUTRAL,
        'Query/GetPlayerPaces.php' => self::PERFORMANCE,
        'Query/GetPlayerPrediction.php' => self::PREDICTIONS,
        'Query/GetPlayerPredictions.php' => self::PREDICTIONS,
        'Query/GetPlayerReviewCounts.php' => self::NEUTRAL,
        'Query/GetPlayerSuspiciousTimes.php' => self::NEUTRAL,
        'Query/GetPlayersWithPendingPredictions.php' => self::PREDICTIONS,
        'Query/GetPredictionCalibration.php' => self::PREDICTIONS,
        'Query/GetPredictionPairRows.php' => self::PREDICTIONS,
        'Query/GetPublishedRoundResults.php' => self::HISTORY,
        'Query/GetPuzzleIdsForSitemap.php' => self::NEUTRAL,
        'Query/GetPuzzleResultDetail.php' => 'history + performance: the attempts list shows every result; the standing is timed only.',
        'Query/GetPuzzleSpeedPercentiles.php' => self::PERFORMANCE,
        'Query/GetRanking.php' => self::PERFORMANCE,
        'Query/GetRecentActivity.php' => self::HISTORY,
        'Query/GetRelayResultRound.php' => 'neutral: the round a relay team\'s result links to, by its id - no figure (solo-and-relays.md §2).',
        'Query/GetRecentIdenticalSolvingTime.php' => 'neutral: the add handler\'s resend guard, by seconds - never matches a result without a time.',
        'Query/GetResultReviewEmailCandidates.php' => self::NEUTRAL,
        'Query/GetResultReviewEmails.php' => self::NEUTRAL,
        'Query/GetResultsImportStoredTabs.php' => 'listing: the results import page compares every imported team\'s stored result - its time or its pieces placed, both selected - with the sheet; nothing is counted.',
        'Query/GetRoundResults.php' => 'history: the round page - lists unfinished results after the finished ones already (phase 1a).',
        'Query/GetSandboxSimulationData.php' => 'neutral: the sandbox simulator\'s own made-up history - it never logs an unfinished result.',
        'Query/GetSeatingSeedTimes.php' => self::PERFORMANCE,
        'Query/GetSolveTimeDistribution.php' => self::PERFORMANCE,
        'Query/GetSolvingTimePrediction.php' => self::NEUTRAL,
        'Query/GetSolvingTimesLookup.php' => self::NEUTRAL,
        'Query/GetStopwatchMilestones.php' => self::PERFORMANCE,
        'Query/GetStoredFileReferences.php' => self::NEUTRAL,
        'Query/GetSuspicionEntryFacts.php' => 'neutral: the time verification - cases exist for timed results only.',
        'Query/GetSuspiciousTimeCandidates.php' => self::PERFORMANCE,
        'Query/GetSuspiciousTimeCaseDetail.php' => self::ADMIN,
        'Query/GetSuspiciousTimeEvidence.php' => self::PERFORMANCE,
        'Query/GetSuspiciousTimeReferences.php' => self::PERFORMANCE,
        'Query/GetSuspiciousTimesOverview.php' => self::ADMIN,
        'Query/GetTeamPlayers.php' => self::HISTORY,
        'Query/GetTimesAwaitingImagePredictions.php' => self::PREDICTIONS,
        'Query/IsPuzzleInUse.php' => self::NEUTRAL,
        'Query/OccurrenceRounds.php' => 'history: a round "has results" when its page lists any - an unfinished result is listed there.',
        'Query/SearchComparisonTeams.php' => self::PERFORMANCE,
        'Repository/DataImportPersonRepository.php' => self::DATA_IMPORT,
        'Repository/DataImportRowRepository.php' => self::DATA_IMPORT,
        'Repository/FinishedPhotoAnalysisRepository.php' => self::PHOTOS,
        'Repository/PuzzleSolvingTimeRepository.php' => 'neutral + performance: reads by id, by round, photos of a puzzle, combined guests; the prediction backfill reads timed solo results only.',
        'Repository/PuzzlingTeamRepository.php' => self::NEUTRAL,
        'Repository/RelayResultRepository.php' => 'neutral: a relay team\'s results by their relay (solo-and-relays.md §2), to claim, release or remove them together - no figure.',
        'Repository/SuspiciousTimeCaseRepository.php' => self::ADMIN,
        'Repository/SuspiciousTimeCheckRepository.php' => self::NEUTRAL,
        'Services/DataImport/ImportNeverTwice.php' => self::DATA_IMPORT,
        'Services/DataImport/ImportResultQuestions.php' => self::DATA_IMPORT,
        'Services/DataImport/ImportedResultWriter.php' => self::DATA_IMPORT,
        'Services/Drafts/UnpublishBlockers.php' => self::NEUTRAL,
        'Services/Insights/GroupCoordinationFitter.php' => self::PERFORMANCE,
        'Services/Insights/InsightsProfileRecalculator.php' => self::PERFORMANCE,
        'Services/ParticipantImport/SiteSnapshotReader.php' => self::NEUTRAL,
        'Services/PuzzleIntelligence/DerivedMetricsCalculator.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/ImprovementRatioCalculator.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/Model/AttemptFlags.php' => 'activity: a model feature "after a pair/team attempt" - an unfinished group attempt is familiarity with the puzzle (plan: nothing in src/Services/PuzzleIntelligence changes).',
        'Services/PuzzleIntelligence/Model/RepeatHistory.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/Model/UnboxedFirstTries.php' => 'activity: "no earlier attempt" for an unboxed first try - an earlier unfinished attempt is an attempt (plan: nothing in src/Services/PuzzleIntelligence changes).',
        'Services/PuzzleIntelligence/MspRatingCalculator.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/PlayerBaselineCalculator.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/PlayerPaceHistoryWriter.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/PlayerSkillCalculator.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/PredictionReconstructor.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/PuzzleIntelligenceRecalculator.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/RepeatResiduals.php' => self::PREDICTIONS,
        'Services/PuzzleIntelligence/StatisticalInputsSql.php' => self::PREDICTIONS,
        'Services/PuzzlingTeamMemberConversion.php' => self::NEUTRAL,
        'Services/RoundResults/RoundResultsReconciler.php' => self::NEUTRAL,
        'Services/SeriesEditions/SeriesEditionReconciler.php' => self::NEUTRAL,
    ];

    public function testEveryReaderOfSolvingTimesDecidesAboutAnUnfinishedResult(): void
    {
        $unclassified = [];
        $stale = self::CLASSIFIED;
        $root = (string) realpath(__DIR__ . '/../src');

        foreach (self::readers($root) as $relative => $code) {
            if (str_contains($code, 'CountsAsSolve::')) {
                continue;
            }

            unset($stale[$relative]);

            if (array_key_exists($relative, self::CLASSIFIED) === false) {
                $unclassified[] = $relative;
            }
        }

        sort($unclassified);

        self::assertSame([], $unclassified, 'These files read puzzle_solving_time without deciding about an unfinished result (pieces placed, no time - not a solve, docs/features/competitions-management/unfinished-results-plan.md). Use CountsAsSolve where they count solves or sum pieces / time, or classify the file here with the reason it needs nothing.');
        self::assertSame([], array_keys($stale), 'These files now use CountsAsSolve or no longer read puzzle_solving_time - remove them from the list.');
    }

    public function testAFileDecidingInPhpReadsThePiecesPlaced(): void
    {
        $root = (string) realpath(__DIR__ . '/../src');
        $readers = self::readers($root);

        foreach (self::CLASSIFIED as $relative => $reason) {
            if ($reason !== self::SOLVE_IN_PHP) {
                continue;
            }

            self::assertArrayHasKey($relative, $readers);
            self::assertStringContainsString('piecesPlaced', $readers[$relative], $relative . ' is classified as deciding in PHP, but never reads piecesPlaced.');
        }
    }

    public function testATimedReaderShowsItsSecondsFilter(): void
    {
        $root = (string) realpath(__DIR__ . '/../src');
        $readers = self::readers($root);

        foreach (self::CLASSIFIED as $relative => $reason) {
            if ($reason !== self::PERFORMANCE && $reason !== self::PREDICTIONS) {
                continue;
            }

            self::assertArrayHasKey($relative, $readers);
            self::assertMatchesRegularExpression(
                '/seconds_to_solve\s+IS\s+NOT\s+NULL|seconds_to_solve\s*>\s*0|secondsToSolve\s+IS\s+NOT\s+NULL/i',
                $readers[$relative],
                $relative . ' is classified as reading timed rows only, but shows no seconds filter - give it its own reason.',
            );
        }
    }

    public function testTheSharedFragments(): void
    {
        self::assertSame(CountsAsSolve::SQL_CONDITION, CountsAsSolve::condition('pst'));
        self::assertSame('(t.pieces_placed IS NULL)', CountsAsSolve::condition('t'));
        self::assertSame('COALESCE(t.pieces_placed, p.pieces_count)', CountsAsSolve::piecesPuzzled('t', 'p.pieces_count'));
        self::assertSame('COALESCE(t.seconds_to_solve, t.seconds_spent)', CountsAsSolve::secondsPuzzled('t'));
    }

    /**
     * @return array<string, string> path relative to src/ => its code without comments
     */
    private static function readers(string $root): array
    {
        $readers = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            $reads = str_contains($code, 'puzzle_solving_time')
                || preg_match('/(?:->from|join)\(\s*PuzzleSolvingTime::class/i', $code) === 1
                || preg_match('/__invoke\(\s*PuzzleSolved\s/', $code) === 1;

            if ($reads) {
                $readers[substr($file->getPathname(), strlen($root) + 1)] = $code;
            }
        }

        return $readers;
    }
}
