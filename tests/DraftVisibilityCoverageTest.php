<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A draft appears on no public surface (docs/features/organizations/README.md "Drafts") - and the read side has no
 * chokepoint: every query is hand-written SQL. So every file whose code (comments aside) reads the competition,
 * competition_series or organization table either embeds the visibility rule (IsCompetitionPubliclyVisible,
 * IsSeriesPubliclyVisible, IsOrganizationPubliclyVisible, GetMarketplaceEvents::SQL_QUALIFIES - which embeds the first)
 * or says what it does with drafts (`is_draft`), or is listed here with the reason it does not need to - or fails this
 * test until its author has made that decision.
 *
 * The approval part alone (IsCompetitionPubliclyVisible::SQL_APPROVED) is no decision about drafts: a file naming it
 * and nothing else of the rule is listed too. DraftCanaryTest proves the public surfaces; this test keeps new readers
 * from slipping past it.
 */
final class DraftVisibilityCoverageTest extends TestCase
{
    private const string VIA_SOLVING_TIMES = 'Reached through puzzle_solving_time.competition_id - a draft never gets a linked time: the pickers offer public events only, the API refuses a round of a non-public event, Unpublish refuses with linked times.';
    private const string PAGE_GATED = 'Called only by a page or endpoint that 404s a draft first.';
    private const string ORGANISER = 'Organiser tooling behind COMPETITION_EDIT / a voter - the team sees its own drafts.';
    private const string OWN = 'The viewer\'s own rows - what they organize or follow; the page that lists them filters drafts itself.';
    private const string WRITE = 'Write side or integrity - creates, moves, deletes or keeps track of every row, a draft or not.';
    private const string URL_ONLY = 'Builds a URL or a storage key, shows nothing.';
    private const string NOTIFICATION = 'Rows created only after IsCompetitionPubliclyVisible::check() (official results notices); Unpublish refuses with results.';
    private const string SECRECY = 'Secret puzzle rules: whether a round keeps a puzzle hidden and who may see it before the reveal - no event is shown.';

    /** Files reading competition / competition_series / organization without a word about drafts */
    private const array NOT_FILTERED = [
        'ConsoleCommands/WarmupImgproxyCacheConsoleCommand.php' => self::URL_ONLY,
        'MessageHandler/AddCompetitionSeriesHandler.php' => self::WRITE,
        'MessageHandler/AddEditionHandler.php' => self::WRITE,
        'MessageHandler/ConvertCompetitionToSeriesHandler.php' => self::WRITE,
        'MessageHandler/DeleteCompetitionSeriesHandler.php' => self::WRITE,
        'Query/GetCompetitionPageSections.php' => 'The detail pages ask for sections only while the event or series is publicly visible; the editors are organiser tooling.',
        'Query/GetCompetitionPermissions.php' => self::ORGANISER,
        'Query/GetCompetitionRoundsForManagement.php' => self::ORGANISER,
        'Query/GetEditionRounds.php' => self::PAGE_GATED,
        'Query/GetEventAttendance.php' => self::PAGE_GATED,
        'Query/GetEventsViewerData.php' => self::OWN,
        'Query/GetFastestGroups.php' => self::VIA_SOLVING_TIMES,
        'Query/GetFastestPairs.php' => self::VIA_SOLVING_TIMES,
        'Query/GetFastestPlayers.php' => self::VIA_SOLVING_TIMES,
        'Query/GetNotifications.php' => self::NOTIFICATION,
        'Query/GetParticipantsSheetState.php' => self::ORGANISER,
        'Query/GetParticipantsSheetVersion.php' => self::ORGANISER,
        'Query/GetPlayerDuplicateCases.php' => self::VIA_SOLVING_TIMES,
        'Query/GetPlayerSolvedPuzzles.php' => self::VIA_SOLVING_TIMES,
        'Query/GetPublishedRoundResults.php' => 'Round results pages answer 404 for an event that is not public (RoundResultsPageBuilder), the sitemap and the counts embed IsCompetitionPubliclyVisible.',
        'Query/GetPuzzleResultDetail.php' => self::VIA_SOLVING_TIMES,
        'Query/GetPuzzleSolvers.php' => self::VIA_SOLVING_TIMES,
        'Query/GetRecentActivity.php' => self::VIA_SOLVING_TIMES,
        'Query/GetRoundResultsOverview.php' => self::ORGANISER,
        'Query/GetRoundsWithPublishedOfficialResults.php' => self::NOTIFICATION,
        'Query/GetStoredFileReferences.php' => self::WRITE,
        'Query/GetSuspiciousTimeCaseDetail.php' => self::VIA_SOLVING_TIMES,
        'Services/CompetitionSlugGenerator.php' => self::WRITE,
        'Services/Drafts/UnpublishBlockers.php' => self::WRITE,
        'Services/ParticipantsSheet/SheetChangesPlanner.php' => self::ORGANISER,
        'Services/RoundResults/RoundResultsReconciler.php' => self::WRITE,
        'Services/SecretPuzzleAccess.php' => self::SECRECY,
        'Services/SeriesEditions/SeriesEditionReconciler.php' => self::WRITE,
        'Value/RoundPuzzleOwnership.php' => self::SECRECY,
    ];

    private const string READS_EVENT_TABLES = '/\b(FROM|JOIN)\s+(competition|competition_series|organization)\b(?![_a-z])/i';
    private const string DECIDES = '/Is(Competition|Series|Organization)PubliclyVisible|\bis_draft\b|GetMarketplaceEvents::SQL_QUALIFIES/';
    private const string DECIDES_DRAFTS = '/SQL_CONDITION|SQL_NOT_DRAFT|SQL_JOIN_OF_COMPETITION|\bis_draft\b|->check\(|SQL_QUALIFIES/';

    public function testEveryReadOfEventRowsIsADecision(): void
    {
        $undecided = [];
        $stale = self::NOT_FILTERED;
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // The SQL lives in strings - a comment mentioning a table or the flag decides nothing
            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match(self::READS_EVENT_TABLES, $code) !== 1) {
                continue;
            }

            if (self::decides($code)) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            unset($stale[$relative]);

            if (array_key_exists($relative, self::NOT_FILTERED) === false) {
                $undecided[] = $relative;
            }
        }

        sort($undecided);

        self::assertSame([], $undecided, 'These files read competition / competition_series / organization rows without leaving drafts out. Embed IsCompetitionPubliclyVisible::SQL_CONDITION (or IsSeriesPubliclyVisible / IsOrganizationPubliclyVisible), or list the file here with the reason.');
        self::assertSame([], array_keys($stale), 'These files now decide about drafts or no longer read the event tables - remove them from the list.');
    }

    public function testTheApprovalPartAloneIsNoDecision(): void
    {
        self::assertFalse(self::decides('$approved = IsCompetitionPubliclyVisible::SQL_APPROVED; $sql = "SELECT 1 FROM competition c WHERE {$approved}";'));
        self::assertTrue(self::decides('$visible = IsCompetitionPubliclyVisible::SQL_CONDITION; $sql = "SELECT 1 FROM competition c WHERE {$visible}";'));
        self::assertTrue(self::decides('$sql = "SELECT 1 FROM competition_series cs WHERE cs.is_draft = false";'));
        self::assertTrue(self::decides('$qualifies = GetMarketplaceEvents::SQL_QUALIFIES;'));
        self::assertFalse(self::decides('$sql = "SELECT 1 FROM competition WHERE approved_at IS NOT NULL";'));
    }

    private static function decides(string $code): bool
    {
        if (preg_match(self::DECIDES, $code) !== 1) {
            return false;
        }

        // A file naming the approval part must also name a part about drafts
        return str_contains($code, 'SQL_APPROVED') === false || preg_match(self::DECIDES_DRAFTS, $code) === 1;
    }
}
