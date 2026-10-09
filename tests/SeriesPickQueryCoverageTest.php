<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A series pick (docs/features/events-page/high-frequency-series.md) is a solving time linked to a series through
 * puzzle_solving_time.competition_series_id - its competition_id is the edition MySpeedPuzzling found, or NULL
 * (series-level). The read side has no chokepoint: every query is hand-written SQL. So every file whose code (comments
 * aside) reads puzzle_solving_time together with competition_id either mentions competition_series_id (it decided what
 * a series pick is for it) or is listed here with the reason it does not need to - or fails this test until its author
 * has made that decision. Per file: SeriesPickCanaryTest proves the surfaces.
 */
final class SeriesPickQueryCoverageTest extends TestCase
{
    private const string ROUND_LEVEL = 'Round level: a time belongs to a round only through its competition - an automatic link has its round exactly like an explicit one, a series-level time is on no round.';
    private const string PER_COMPETITION = 'Per competition: an automatic link counts for its edition; a series-level time belongs to no edition.';
    private const string PARTICIPANTS = 'Reads event participants or who took part in an event (competition_participant), not the event of a time.';
    private const string NOT_A_TIMES_EVENT = 'competition_id here is not the event of a solving time (another table\'s column, or a reference only).';
    private const string RELEASES_ON_DELETE = 'Write side: deleting an edition releases its automatic links to series-level together with their match kind; the series reconcile re-matches them.';

    /** Files reading puzzle_solving_time with competition_id that do not need to know about series picks */
    private const array NOT_SERIES_AWARE = [
        'MessageHandler/DeleteCompetitionHandler.php' => self::RELEASES_ON_DELETE,
        'Query/CountCompetitionResults.php' => self::PER_COMPETITION,
        'Query/GetCompetitionParticipants.php' => self::PARTICIPANTS,
        'Query/GetCompetitionPuzzles.php' => self::PER_COMPETITION,
        'Query/GetCompetitionSlugsForSitemap.php' => self::ROUND_LEVEL,
        'Query/GetNotifications.php' => self::NOT_A_TIMES_EVENT,
        'Query/GetParticipantsSheetState.php' => self::ROUND_LEVEL,
        'Query/GetPublishedRoundResults.php' => self::ROUND_LEVEL,
        'Query/GetSuggestedPlayers.php' => self::PARTICIPANTS,
        'Query/OccurrenceRounds.php' => self::ROUND_LEVEL,
        'Query/SearchPuzzle.php' => self::NOT_A_TIMES_EVENT,
        'Services/ParticipantImport/SiteSnapshotReader.php' => self::ROUND_LEVEL,
    ];

    public function testEveryReadOfATimesEventDecidesSeriesPicks(): void
    {
        $undecided = [];
        $stale = self::NOT_SERIES_AWARE;
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = self::codeWithoutComments((string) file_get_contents($file->getPathname()));

            if (self::readsATimesEvent($code) === false) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            if (str_contains($code, 'competition_series_id')) {
                continue;
            }

            unset($stale[$relative]);

            if (array_key_exists($relative, self::NOT_SERIES_AWARE) === false) {
                $undecided[] = $relative;
            }
        }

        sort($undecided);

        self::assertSame([], $undecided, 'These files read puzzle_solving_time with competition_id without a word about series picks. Decide what a series pick (competition_series_id) is for them, or list the file here with the reason.');
        self::assertSame([], array_keys($stale), 'These files now mention competition_series_id or no longer read puzzle_solving_time with competition_id - remove them from the list.');
    }

    public function testTheDetectionIgnoresComments(): void
    {
        self::assertTrue(self::readsATimesEvent(self::codeWithoutComments('<?php $sql = "SELECT competition_id FROM puzzle_solving_time";')));
        self::assertFalse(self::readsATimesEvent(self::codeWithoutComments("<?php\n// puzzle_solving_time.competition_id\n\$sql = 'SELECT 1';")));
        self::assertFalse(self::readsATimesEvent(self::codeWithoutComments('<?php $sql = "SELECT competition_round_id FROM puzzle_solving_time";')));
    }

    private static function readsATimesEvent(string $code): bool
    {
        return str_contains($code, 'puzzle_solving_time') && preg_match('/\bcompetition_id\b/', $code) === 1;
    }

    private static function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
