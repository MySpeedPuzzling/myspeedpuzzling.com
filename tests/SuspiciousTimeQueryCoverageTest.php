<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A suspicious result (docs/features/suspicious-times.md) is neither counted nor timed anywhere - leaderboards,
 * rankings, "Completed N×", fastest / average / median times, statistics, totals, insights, shared feeds. It is shown
 * only in the player's and the pair's/team's own results, labelled. So every file whose code (comments aside) reads
 * puzzle_solving_time either says what it does with suspicious results, or is listed here with the reason it does
 * not need to - or fails this test until its author has made that decision.
 */
final class SuspiciousTimeQueryCoverageTest extends TestCase
{
    private const string OWN = 'The player\'s own results or solved status - a suspicious result is still theirs, and the puzzle still solved.';
    private const string WRITE = 'Write side or integrity - moves, deletes, links or keeps track of every result, suspicious or not.';
    private const string ADMIN = 'Admin / moderation tooling - sees every result.';
    private const string BACKGROUND = 'Background job or sitemap - shows no figure.';
    private const string DUPLICATES = 'Finds a result saved twice - a suspicious copy is still a copy (docs/features/duplicate-results.md).';

    /** Files reading puzzle_solving_time without a word about suspicious results */
    private const array NOT_FILTERED = [
        'ConsoleCommands/RecalculatePuzzleStatisticsConsoleCommand.php' => 'Picks the puzzles to recompute - PuzzleStatisticsCalculator leaves suspicious results out.',
        'ConsoleCommands/WarmupImgproxyCacheConsoleCommand.php' => self::BACKGROUND,
        'MessageHandler/BackfillPuzzlingTeamsHandler.php' => self::WRITE,
        'MessageHandler/BackfillRoundPuzzleRevealsHandler.php' => self::WRITE,
        'MessageHandler/CleanupEmptyPuzzlingTeamsHandler.php' => self::WRITE,
        'MessageHandler/DeleteCompetitionHandler.php' => self::WRITE,
        'MessageHandler/DeleteCompetitionRoundHandler.php' => self::WRITE,
        'MessageHandler/DeleteCompetitionSeriesHandler.php' => self::WRITE,
        'MessageHandler/DeletePlayerHandler.php' => self::WRITE,
        'Query/GetAccountDeletionSummary.php' => 'Counts what an account deletion removes - everything.',
        'Query/GetAdminCompetitions.php' => self::ADMIN,
        'Query/GetBorrowedPuzzles.php' => self::OWN,
        'Query/GetComparisonPeople.php' => self::OWN,
        'Query/GetCompetitionSlugsForSitemap.php' => self::BACKGROUND,
        'Query/GetCoPuzzlers.php' => self::OWN,
        'Query/GetDuplicateCandidates.php' => self::DUPLICATES,
        'Query/GetDuplicatePuzzleSignalCandidates.php' => self::DUPLICATES,
        'Query/GetFirstTryTimes.php' => 'One first try per person per puzzle - a suspicious first try is still the first try.',
        'Query/GetGettingStartedProgress.php' => self::OWN,
        'Query/GetGuestLinkRequests.php' => 'Lists a guest\'s results to the player asked to become that guest - their own results.',
        'Query/GetNotifications.php' => 'A notification about one result - no figure.',
        'Query/GetParticipantsSheetState.php' => 'Organiser tooling: in which rounds a linked player has any time at all - the results guard of the participants spreadsheet (a person with a time in a round stays in it, like the import\'s rule); no figure.',
        'Query/GetPendingPuzzleProposals.php' => self::ADMIN,
        'Query/GetPlayerDuplicateCases.php' => self::DUPLICATES,
        'Query/GetPlayerIdsForSitemap.php' => self::BACKGROUND,
        'Query/GetPlayerPuzzleSolves.php' => self::OWN,
        'Query/GetPlayersWithPendingPredictions.php' => 'Background: times still without a stored prediction - suspicious ones are predicted and stored too (prediction-history.md).',
        'Query/GetPublishedRoundResults.php' => 'Reads only the viewer\'s own times in the round ("On your profile" of the official results) - a suspicious time is still theirs and stays on their profile; the official ranking itself comes from the organiser\'s record, not from times.',
        'Query/GetPuzzleIdsForSitemap.php' => self::BACKGROUND,
        'Query/GetPuzzleMergeReviewQueue.php' => self::ADMIN,
        'Query/GetRecentIdenticalSolvingTime.php' => self::DUPLICATES,
        'Query/GetSolvingTimePrediction.php' => self::OWN,
        'Query/GetStoredFileReferences.php' => self::WRITE,
        'Query/GetSuggestedPlayers.php' => 'Who the viewer has puzzled with, met at events or shares puzzles with - existence only, no figure.',
        'Query/GetTeamPlayers.php' => 'The members of results already selected elsewhere - no figure.',
        'Query/GetUnsolvedPuzzles.php' => self::OWN,
        'Query/GetUserPuzzleStatuses.php' => self::OWN,
        'Query/GetUserSolvedPuzzles.php' => self::OWN,
        'Query/IsPuzzleInUse.php' => self::WRITE,
        'Query/SearchPuzzle.php' => self::OWN,
        'Repository/PuzzlingTeamRepository.php' => self::WRITE,
        'Services/Drafts/UnpublishBlockers.php' => 'Whether an event can go back to draft - a suspicious time linked to it is still a linked time (docs/features/organizations/README.md "Drafts").',
        'Services/ParticipantImport/SiteSnapshotReader.php' => self::WRITE,
        'Services/PuzzleIntelligence/PuzzleIntelligenceRecalculator.php' => 'Background: picks the players and puzzles to recompute - the calculators leave suspicious results out.',
        'Services/PuzzlingTeamMemberConversion.php' => self::WRITE,
        'Services/RoundResults/RoundResultsReconciler.php' => self::WRITE,
    ];

    public function testEveryReadOfSolvingResultsIsADecision(): void
    {
        $undecided = [];
        $stale = self::NOT_FILTERED;
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // The SQL lives in strings - a comment mentioning the table or the flag decides nothing
            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            if (str_contains($code, 'puzzle_solving_time') === false || stripos($code, 'suspicious') !== false) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            unset($stale[$relative]);

            if (array_key_exists($relative, self::NOT_FILTERED) === false) {
                $undecided[] = $relative;
            }
        }

        sort($undecided);

        self::assertSame([], $undecided, 'These files read puzzle_solving_time without leaving suspicious results out (`suspicious = false`). Add the condition, or list the file here with the reason.');
        self::assertSame([], array_keys($stale), 'These files now handle suspicious results or no longer read puzzle_solving_time - remove them from the list.');
    }
}
