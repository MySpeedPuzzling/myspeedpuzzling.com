<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A solving time may have no tracker: an imported event result nobody has claimed yet (`player_id` NULL - always a
 * pair's or team's, with anonymous slots, docs/features/event-results-import/README.md, trackerless-audit.md). Raw SQL
 * is invisible to PHPStan, so every file whose code (comments aside) reads puzzle_solving_time either shows in its SQL
 * what it does with such a row, or is listed here with the reason it does not need to - or fails this test until its
 * author has made that decision.
 *
 * What counts as showing it (on a puzzle_solving_time alias of the file - a member's `player_id` decides nothing):
 * - `<alias>.player_id IS NOT NULL`: left out at the source;
 * - `LEFT JOIN player <x> ON <x>.id = <alias>.player_id`: kept, the tracker is optional.
 * A solo-only condition or a mention of `puzzling_team_id` is no marker: plenty of files have one next to a statement
 * reading every kind of result (a 'solo' FILTER beside the totals, a team id selected beside an inner join on the
 * tracker) - those files are listed with their reason instead.
 */
final class TrackerlessTimeQueryCoverageTest extends TestCase
{
    private const string OWN = 'Reads a given player\'s results (tracked by them or as a registered member) - an imported result nobody has claimed has neither.';
    private const string SOLO = 'Reads solo results only - an imported result is always a pair\'s or a team\'s.';
    private const string COUNTS = 'Counts or lists results by puzzle, event, round or team, never through the tracker - an imported result counts like any other, which is right.';
    private const string MEMBERS = 'Reads a pair\'s/team\'s people from the team snapshot or puzzling_team_member - an anonymous slot is a person without an account, handled like a guest.';
    private const string PER_PLAYER = 'A per-player surface (profiles, rankings, insights, XP, notifications): an inner join on the player leaves an imported result nobody has claimed out - it is nobody\'s.';
    private const string D4 = 'Left out until somebody claims it (D4, docs/features/event-results-import/README.md): an inner join on the tracker.';
    private const string WRITE = 'Write side or integrity - moves, deletes, links or keeps track of every result, with a tracker or not.';
    private const string ADMIN = 'Admin / moderation tooling - counts or shows results to a moderator; an imported result is never in the time verification (the scan skips it).';
    private const string BACKGROUND = 'Background job or sitemap - picks puzzles, files or players, shows no person.';
    private const string PREDICTIONS = 'Reads stored predictions - an imported result nobody has claimed is never predicted (GetSolvingTimePrediction, the backfill reads a player\'s times).';
    private const string PHOTOS = 'Players\' finished-puzzle photos - an imported result has none.';

    /** Files reading puzzle_solving_time without a marker */
    private const array DECIDED = [
        'ConsoleCommands/RecalculatePuzzleStatisticsConsoleCommand.php' => self::BACKGROUND,
        'ConsoleCommands/WarmupImgproxyCacheConsoleCommand.php' => self::BACKGROUND,
        'Controller/EditTimeController.php' => 'Only the edit form\'s name contains the table\'s name - no query.',
        'MessageHandler/BackfillPuzzlingTeamsHandler.php' => self::WRITE,
        'MessageHandler/BackfillRoundPuzzleRevealsHandler.php' => self::WRITE,
        'MessageHandler/CleanupEmptyPuzzlingTeamsHandler.php' => self::WRITE,
        'MessageHandler/ConvertCompetitionToSeriesHandler.php' => self::WRITE,
        'MessageHandler/DeleteCompetitionHandler.php' => self::WRITE,
        'MessageHandler/DeleteCompetitionRoundHandler.php' => self::WRITE,
        'MessageHandler/DeleteCompetitionSeriesHandler.php' => self::WRITE,
        'MessageHandler/DeletePlayerHandler.php' => self::OWN,
        'MessageHandler/RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange.php' => self::SOLO,
        'MessageHandler/SnapshotPlayerInsightsHandler.php' => self::SOLO,
        'Query/AchievementSqlRules.php' => self::OWN,
        'Query/CountCompetitionResults.php' => self::COUNTS,
        'Query/FindSimilarSpeedPuzzler.php' => self::SOLO,
        'Query/GetAccountDeletionSummary.php' => self::OWN,
        'Query/GetAdminCompetitions.php' => self::ADMIN,
        'Query/GetAdminSeries.php' => self::ADMIN,
        'Query/GetAllPlayerIdsWithSolveTimes.php' => 'Background: every player with a result - `id IS NOT NULL` leaves a time without a tracker out.',
        'Query/GetBadges.php' => self::OWN,
        'Query/GetBorrowedPuzzles.php' => self::OWN,
        'Query/GetBrandDirectory.php' => self::COUNTS,
        'Query/GetBrandHub.php' => self::COUNTS,
        'Query/GetCandidatePrediction.php' => self::SOLO,
        'Query/GetCatalogueNumbers.php' => self::COUNTS,
        'Query/GetCloseCalls.php' => self::SOLO,
        'Query/GetComparisonPeople.php' => self::OWN,
        'Query/GetComparisonResults.php' => 'Compares chosen players (solo) and pairs/teams - a team gets into a line-up only through its registered members (SearchComparisonTeams), which an imported result nobody has claimed has none of.',
        'Query/GetCompetitionParticipants.php' => self::OWN,
        'Query/GetCompetitionPuzzles.php' => self::COUNTS,
        'Query/GetCompetitionSlugsForSitemap.php' => self::BACKGROUND,
        'Query/GetContentDigestRecipients.php' => self::OWN,
        'Query/GetCoPuzzlers.php' => self::OWN,
        'Query/GetDuplicateCandidates.php' => self::OWN,
        'Query/GetDuplicatePuzzleSignalCandidates.php' => 'Admin: the same person\'s results on two puzzles - a time without a tracker or member is nobody\'s, NULL never pairs with NULL.',
        'Query/GetExportablePuzzleSolveSummary.php' => self::OWN,
        'Query/GetExportableSolvingTimes.php' => self::OWN,
        'Query/GetFastestGroups.php' => self::D4,
        'Query/GetFastestPairs.php' => self::D4,
        'Query/GetFastestPlayers.php' => self::SOLO,
        'Query/GetFinishedPhotoAnalyses.php' => self::PHOTOS,
        'Query/GetFinishedPhotosForAnalysis.php' => self::PHOTOS,
        'Query/GetFirstTryTimes.php' => self::OWN,
        'Query/GetGettingStartedProgress.php' => self::OWN,
        'Query/GetGroupPredictionResults.php' => self::MEMBERS,
        'Query/GetGroupPuzzleFirstAttempts.php' => self::SOLO,
        'Query/GetGuestLinkRequests.php' => self::OWN,
        'Query/GetImageEstimateEvaluationRows.php' => self::SOLO,
        'Query/GetInsightsActivity.php' => self::OWN,
        'Query/GetInsightsFingerprint.php' => self::OWN,
        'Query/GetInsightsGroupResults.php' => self::OWN,
        'Query/GetInsightsPaceHistory.php' => self::OWN,
        'Query/GetInsightsRatingInputs.php' => self::SOLO,
        'Query/GetInsightsResolveCandidates.php' => self::OWN,
        'Query/GetInsightsResultCount.php' => self::SOLO,
        'Query/GetInsightsShelf.php' => self::OWN,
        'Query/GetInsightsSoloResults.php' => self::SOLO,
        'Query/GetMostActivePlayers.php' => self::PER_PLAYER,
        'Query/GetMostSolvedPuzzles.php' => self::COUNTS,
        'Query/GetNotifications.php' => self::PER_PLAYER,
        'Query/GetPendingPuzzleProposals.php' => self::ADMIN,
        'Query/GetPersonalEstimates.php' => self::OWN,
        'Query/GetPiecesHub.php' => self::COUNTS,
        'Query/GetPlayerActivityCalendar.php' => self::OWN,
        'Query/GetPlayerBaselineProgress.php' => self::SOLO,
        'Query/GetPlayerBestSoloTimes.php' => self::SOLO,
        'Query/GetPlayerChartData.php' => self::OWN,
        'Query/GetPlayerDuplicateCases.php' => self::OWN,
        'Query/GetPlayerIdsForSitemap.php' => self::BACKGROUND,
        'Query/GetPlayerPaces.php' => self::SOLO,
        'Query/GetPlayerPrediction.php' => self::SOLO,
        'Query/GetPlayerPredictions.php' => self::SOLO,
        'Query/GetPlayerProfile.php' => self::OWN,
        'Query/GetPlayerPuzzleSolves.php' => self::OWN,
        'Query/GetPlayerPuzzleTimes.php' => self::OWN,
        'Query/GetPlayerReviewCounts.php' => self::OWN,
        'Query/GetPlayerSolvedPuzzles.php' => self::PER_PLAYER,
        'Query/GetPlayerStatistics.php' => self::OWN,
        'Query/GetPlayerStatsSnapshot.php' => self::OWN,
        'Query/GetPlayerSuspiciousTimes.php' => self::OWN,
        'Query/GetPlayersWithPendingPredictions.php' => self::SOLO,
        'Query/GetPredictionCalibration.php' => self::PREDICTIONS,
        'Query/GetPredictionPairRows.php' => self::PREDICTIONS,
        'Query/GetProgressionDrift.php' => self::PER_PLAYER,
        'Query/GetPublishedRoundResults.php' => self::OWN,
        'Query/GetPuzzleIdsForSitemap.php' => self::BACKGROUND,
        'Query/GetPuzzleMergeReviewQueue.php' => self::ADMIN,
        'Query/GetPuzzlePickerSuggestions.php' => self::OWN,
        'Query/GetPuzzleSolvers.php' => 'The puzzle page lists every result: solo through an inner join on its (always present) player; a pair\'s/team\'s row takes a time without a tracker (PuzzleSolversGroup::$addedByPlayerId nullable) and anonymous slots (PuzzlesSorter keeps two anonymous teams apart).',
        'Query/GetPuzzleSpeedPercentiles.php' => self::SOLO,
        'Query/GetPuzzlingTeamDetail.php' => self::COUNTS,
        'Query/GetRanking.php' => self::SOLO,
        'Query/GetRecentActivity.php' => self::D4,
        'Query/GetRecentIdenticalSolvingTime.php' => self::OWN,
        'Query/GetResultReviewEmailCandidates.php' => self::OWN,
        'Query/GetResultReviewEmails.php' => self::OWN,
        'Query/GetSeatingSeedTimes.php' => self::OWN,
        'Query/GetSolveTimeDistribution.php' => 'Counts solvers per kind: a pair/team by puzzling_team_id, which an imported result always has - each anonymous team is one solver.',
        'Query/GetSolveXpDisplayInfo.php' => self::OWN,
        'Query/GetSolvingTimePrediction.php' => 'A given time\'s tracker - none on an imported result nobody has claimed, then no prediction (resultForTime()).',
        'Query/GetSolvingTimesLookup.php' => self::OWN,
        'Query/GetStatistics.php' => self::COUNTS,
        'Query/GetStopwatchMilestones.php' => self::PER_PLAYER,
        'Query/GetStoredFileReferences.php' => self::WRITE,
        'Query/GetSuggestedPlayers.php' => self::OWN,
        'Query/GetSuspicionEntryFacts.php' => self::OWN,
        'Query/GetSuspiciousTimeCaseDetail.php' => self::ADMIN,
        'Query/GetSuspiciousTimeEvidence.php' => self::ADMIN,
        'Query/GetSuspiciousTimeQueue.php' => self::ADMIN,
        'Query/GetSuspiciousTimeReferences.php' => self::COUNTS,
        'Query/GetSuspiciousTimesOverview.php' => self::ADMIN,
        'Query/GetTeamPlayers.php' => self::MEMBERS,
        'Query/GetTimesAwaitingImagePredictions.php' => self::SOLO,
        'Query/GetUnsolvedPuzzles.php' => self::OWN,
        'Query/GetUserPuzzleStatuses.php' => self::OWN,
        'Query/GetUserSolvedPuzzles.php' => self::OWN,
        'Query/GetXpHistory.php' => self::OWN,
        'Query/GetXpLaunchVerification.php' => self::ADMIN,
        'Query/IsPuzzleInUse.php' => self::WRITE,
        'Query/OccurrenceRounds.php' => self::COUNTS,
        'Query/SearchComparisonTeams.php' => self::OWN,
        'Query/SearchPuzzle.php' => self::OWN,
        'Repository/FinishedPhotoAnalysisRepository.php' => self::PHOTOS,
        'Repository/PuzzlingTeamRepository.php' => self::WRITE,
        'Repository/SuspiciousTimeCheckRepository.php' => self::WRITE,
        'Services/Badges/AchievementFirstMetDates.php' => self::OWN,
        'Services/Community/CommunityStatsCalculator.php' => self::PER_PLAYER,
        'Services/Community/PlayerMomentDetector.php' => self::PER_PLAYER,
        'Services/Digest/PlayerPeriodActivityReader.php' => self::OWN,
        'Services/Drafts/UnpublishBlockers.php' => self::COUNTS,
        'Services/Insights/GroupCoordinationFitter.php' => self::MEMBERS,
        'Services/Insights/InsightsProfileRecalculator.php' => self::SOLO,
        'Services/PuzzleIntelligence/DerivedMetricsCalculator.php' => self::SOLO,
        'Services/PuzzleIntelligence/ImprovementRatioCalculator.php' => self::SOLO,
        'Services/PuzzleIntelligence/Model/AttemptFlags.php' => self::OWN,
        'Services/PuzzleIntelligence/Model/RepeatHistory.php' => self::SOLO,
        'Services/PuzzleIntelligence/Model/UnboxedFirstTries.php' => self::SOLO,
        'Services/PuzzleIntelligence/MspRatingCalculator.php' => self::SOLO,
        'Services/PuzzleIntelligence/PlayerBaselineCalculator.php' => self::SOLO,
        'Services/PuzzleIntelligence/PlayerPaceHistoryWriter.php' => self::SOLO,
        'Services/PuzzleIntelligence/PlayerSkillCalculator.php' => self::SOLO,
        'Services/PuzzleIntelligence/PredictionReconstructor.php' => self::SOLO,
        'Services/PuzzleIntelligence/RepeatResiduals.php' => self::SOLO,
        'Services/PuzzleIntelligence/StatisticalInputsSql.php' => self::SOLO,
        'Services/PuzzleStatisticsCalculator.php' => 'Puzzle statistics count every result; best times are per solver, and an imported result nobody has claimed is its own team (COALESCE(player_id, puzzling_team_id, id)).',
        'Services/PuzzlingTeamMemberConversion.php' => self::WRITE,
        'Services/RoundResults/RoundResultsReconciler.php' => self::WRITE,
        'Services/SeriesEditions/SeriesEditionReconciler.php' => self::WRITE,
        'Services/Xp/XpChainRecomputer.php' => 'XP of a solve\'s participants - an imported result nobody has claimed has none, so nothing happens (participantsOf()).',
        'Services/Xp/XpHistoryReplay.php' => self::OWN,
    ];

    public function testEveryReadOfSolvingTimesDecidesAboutATimeWithoutTracker(): void
    {
        $undecided = [];
        $stale = self::DECIDED;
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // The SQL lives in strings - a comment mentioning the table or the column decides nothing
            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            if (str_contains($code, 'puzzle_solving_time') === false || self::hasMarker($code)) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            unset($stale[$relative]);

            if (array_key_exists($relative, self::DECIDED) === false) {
                $undecided[] = $relative;
            }
        }

        sort($undecided);

        self::assertSame([], $undecided, 'These files read puzzle_solving_time without deciding about a time without a tracker (an imported event result nobody has claimed, docs/features/event-results-import/README.md). Leave it out (`pst.player_id IS NOT NULL`), keep it (`LEFT JOIN player x ON x.id = pst.player_id`), or list the file here with the reason.');
        self::assertSame([], array_keys($stale), 'These files now carry a marker or no longer read puzzle_solving_time - remove them from the list.');
    }

    public function testTheMarkersAreBoundToTheSolvingTimeTable(): void
    {
        self::assertTrue(self::hasMarker('SELECT pst.player_id FROM puzzle_solving_time pst WHERE pst.player_id IS NOT NULL'));
        self::assertTrue(self::hasMarker('FROM puzzle_solving_time pst LEFT JOIN player owner ON owner.id = pst.player_id'));
        self::assertTrue(self::hasMarker('FROM puzzle_solving_time WHERE puzzle_solving_time.player_id IS NOT NULL'));

        // A member's player id says nothing about the tracker
        self::assertFalse(self::hasMarker('FROM puzzle_solving_time pst JOIN puzzling_team_member ptm ON ptm.team_id = pst.puzzling_team_id WHERE ptm.player_id IS NOT NULL'));
        self::assertFalse(self::hasMarker('FROM puzzle_solving_time pst LEFT JOIN player p ON p.id = ptm.player_id'));
        self::assertFalse(self::hasMarker('FROM puzzle_solving_time pst INNER JOIN player owner ON owner.id = pst.player_id'));
    }

    private static function hasMarker(string $code): bool
    {
        // Aliases the file gives the table: `puzzle_solving_time pst`, `puzzle_solving_time AS t`
        preg_match_all('/\bpuzzle_solving_time\s+(?:AS\s+)?(?!(?:WHERE|INNER|LEFT|RIGHT|JOIN|ON|SET|GROUP|ORDER|USING|CROSS|WITH|UNION|LIMIT)\b)([a-z_]\w*)/i', $code, $matches);
        $aliases = implode('|', array_map(static fn (string $alias): string => preg_quote($alias, '/'), ['puzzle_solving_time', ...$matches[1]]));

        return preg_match('/(?<![\w.])(?:' . $aliases . ')\.player_id\s+IS\s+NOT\s+NULL/i', $code) === 1
            || preg_match('/LEFT\s+JOIN\s+player\s+(?:AS\s+)?(\w+)\s+ON\s+\1\.id\s*=\s*(?:' . $aliases . ')\.player_id\b/i', $code) === 1;
    }
}
