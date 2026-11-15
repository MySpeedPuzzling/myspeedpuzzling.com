<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A player's unlisted puzzles are nobody else's business (docs/features/data-import/unlisted-puzzles.md "The privacy
 * rule"): puzzles of their spreadsheet that are not in the catalogue, with their results and whether they own them,
 * live in tables of their own - `unlisted_puzzle`, `unlisted_result`, `unlisted_ownership`, `unlisted_pairing` - that
 * no ranking, statistic, chart, feed, search, list, export or API reads. They are out of every public number because
 * nothing public can see them, not because every reader remembers to filter them.
 *
 * That holds only while nobody reaches them "just this once". So every file under src/ whose code (comments aside)
 * names the tables, their entities, their repository, their one reader or one of the feature's services is listed
 * here with the reason it may. A new caller - a profile query counting them in, a statistics job, an API resource -
 * fails this test until its author has made that decision and written it down.
 *
 * What the test can not see: what a listed file does with them. That is what the reasons are for (each says whose
 * unlisted puzzles the file touches, so a review can check it) and what UnlistedPuzzlesPagesTest checks from the
 * outside (another player and a guest get 404 for every address, and no public page shows a name).
 */
final class UnlistedDataCoverageTest extends TestCase
{
    private const string NEEDLES = '/\bunlisted_(?:puzzle|result|ownership|pairing)(?:_id)?\b|\b(?:UnlistedPuzzle|UnlistedResult|UnlistedOwnership|UnlistedPairing|UnlistedPuzzleRepository|GetUnlistedPuzzles|UnlistedRowWriter|UnlistedPairer|UnlistedPuzzleMatcher|UnlistedPuzzlePage)\b/';

    private const string OWNER_PAGE = 'A page / action of "Unlisted puzzles" (owner only): it takes no player id from the address - UnlistedPuzzlePage resolves the signed-in player, a guest and anybody who is not the owner get 404; an unlisted puzzle id from the address is looked up among that player\'s only.';
    private const string OWNER_WRITE = 'Write side, the owner\'s own: UnlistedPuzzleRepository::getOwnedBy() with the acting player\'s id - somebody else\'s unlisted puzzle is "not found".';
    private const string WORKER_STEP = 'The `imports` worker\'s step of a pairing the owner started (ids only, nothing is read out or shown): turns the owner\'s unlisted results into real ones through ImportedResultWriter, or takes them back.';

    /** Files that may name the unlisted tables or what reads and writes them */
    private const array ALLOWED = [
        // The storage
        'Entity/UnlistedPuzzle.php' => 'The entity - one per player and puzzle key.',
        'Entity/UnlistedResult.php' => 'The entity - a result on an unlisted puzzle, never a row of puzzle_solving_time.',
        'Entity/UnlistedOwnership.php' => 'The entity - an owned / wish mark, never a row of collection_item or wish_list_item.',
        'Entity/UnlistedPairing.php' => 'The entity - the record of one "It is this catalogue puzzle".',
        'Repository/UnlistedPuzzleRepository.php' => 'Write-side repository: lookups that start from a request take the player (getOwnedBy refuses anybody else\'s); the rest is by id for the worker and the daily look - never for display.',

        // The one reader
        'Query/GetUnlistedPuzzles.php' => 'THE reader - every statement bound to the owner (`WHERE player_id = :ownerPlayerId`). Called by the owner\'s own pages only.',

        // The feature's services
        'Services/Unlisted/UnlistedRowWriter.php' => 'A row of the importing player\'s own "Add" (or its Undo) whose puzzle they keep as unlisted: writes that player\'s unlisted puzzle, result and mark - nothing is read out or shown.',
        'Services/Unlisted/UnlistedPairer.php' => self::WORKER_STEP,
        'Services/Unlisted/UnlistedPuzzleMatcher.php' => 'Asks the catalogue what it has for unlisted puzzles it is handed (the owner\'s page, the daily look) - reads the catalogue, writes nothing, shows nothing.',
        'Services/Unlisted/UnlistedPuzzlePage.php' => 'What the page of one unlisted puzzle shows. Takes no player id: resolves the signed-in player itself.',
        'Services/Unlisted/ViewerUnlistedPuzzles.php' => 'Whether the signed-in player has any unlisted puzzle - a yes/no for THEIR OWN menu, remembered in their session. Takes no player id: resolves the signed-in player itself; a guest is "no" without a statement.',

        // The pages
        'Controller/Unlisted/UnlistedPuzzlesController.php' => self::OWNER_PAGE,
        'Controller/Unlisted/UnlistedPuzzleController.php' => self::OWNER_PAGE,
        'Controller/Unlisted/SearchUnlistedPuzzleController.php' => self::OWNER_PAGE,
        'Controller/Unlisted/PairUnlistedPuzzleController.php' => self::OWNER_PAGE,
        'Controller/Unlisted/UndoUnlistedPairingController.php' => self::OWNER_PAGE,
        'Controller/Unlisted/RemoveUnlistedPuzzleController.php' => self::OWNER_PAGE,
        'Controller/Unlisted/RefuseUnlistedSuggestionController.php' => self::OWNER_PAGE,
        'Controller/Unlisted/AddUnlistedPuzzleToCatalogueController.php' => self::OWNER_PAGE,

        // The handlers
        'MessageHandler/PairUnlistedPuzzleHandler.php' => self::OWNER_WRITE,
        'MessageHandler/UndoUnlistedPairingHandler.php' => 'Write side, the owner\'s own: the pairing is looked up by id and refused ("not found") unless its unlisted puzzle is the acting player\'s.',
        'MessageHandler/RemoveUnlistedPuzzleHandler.php' => self::OWNER_WRITE,
        'MessageHandler/AnswerUnlistedSuggestionHandler.php' => self::OWNER_WRITE,
        'MessageHandler/ProcessUnlistedPairingHandler.php' => 'The worker\'s turn of a pairing: asks which of its results and marks still wait (ids only) and hands each to its step - nothing is read out or shown.',
        'MessageHandler/ApplyUnlistedPairingStepHandler.php' => self::WORKER_STEP,
        'MessageHandler/SuggestUnlistedPuzzlePairingsHandler.php' => 'The daily look at the grown catalogue: reads unlisted puzzles (what the file said of them) to ask the catalogue, marks a suggestion on them and tells their own player once - pairs nothing, shows nothing to anybody else.',
        'MessageHandler/ContinueImportBatchesHandler.php' => 'The sweeper: asks which pairings stand still (ids only) and queues them again.',
        'MessageHandler/UndoImportRowHandler.php' => 'Undo of the importing player\'s own row: hands a row that made unlisted things to UnlistedRowWriter::takeBack() - nothing is read out or shown.',
        'Services/DataImport/ImportRowAdder.php' => 'A row of the importing player\'s own "Add" whose puzzle they keep as unlisted is handed to UnlistedRowWriter - nothing is read out or shown.',
    ];

    public function testOnlyTheListedOwnerOnlyCodeNamesTheUnlistedTablesOrWhatReadsThem(): void
    {
        $unlisted = [];
        $stale = self::ALLOWED;
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match(self::NEEDLES, self::codeOf($file->getPathname())) !== 1) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            unset($stale[$relative]);

            if (array_key_exists($relative, self::ALLOWED) === false) {
                $unlisted[] = $relative;
            }
        }

        sort($unlisted);

        self::assertSame([], $unlisted, 'These files name the unlisted puzzles\' tables or a class that reads or writes them. A player\'s unlisted puzzles are only theirs (docs/features/data-import/unlisted-puzzles.md): no shared read, statistic, ranking, feed, export or API may see them - an unlisted result becomes public only by being paired, through the import\'s writer. If the file shows a player their OWN unlisted puzzles, or is the worker\'s step of the owner\'s own action, list it here and say whose data it touches.');
        self::assertSame([], array_keys($stale), 'These files no longer name the unlisted puzzles\' tables or what reads them - remove them from the list.');
    }

    /**
     * The shared reads, the live components and the whole API know nothing of the unlisted tables at all - not in a
     * comment either. The one reader is the only file of src/Query that may.
     */
    public function testNoSharedReadComponentOrApiKnowsTheUnlistedTables(): void
    {
        $root = (string) realpath(__DIR__ . '/../src');
        $checked = 0;

        foreach (['Query', 'Api', 'Component', 'Twig', 'Results'] as $directory) {
            if (is_dir($root . '/' . $directory) === false) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || $file->getPathname() === $root . '/Query/GetUnlistedPuzzles.php') {
                    continue;
                }

                $checked++;
                self::assertDoesNotMatchRegularExpression(self::NEEDLES, (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }

        self::assertGreaterThan(300, $checked);
    }

    /**
     * Every statement of the one reader is bound to the owner, and its public methods take the owner's id first.
     */
    public function testEveryStatementOfTheOneReaderIsBoundToTheOwner(): void
    {
        $code = (string) file_get_contents(__DIR__ . '/../src/Query/GetUnlistedPuzzles.php');
        $statements = preg_split('/->executeQuery\(/', $code);
        self::assertIsArray($statements);
        array_shift($statements);
        self::assertGreaterThanOrEqual(5, count($statements));

        foreach ($statements as $statement) {
            $sql = substr($statement, 0, (int) strpos($statement, "\nSQL"));
            self::assertMatchesRegularExpression('/\bplayer_id = :ownerPlayerId\b/', $sql, 'A statement of GetUnlistedPuzzles is not bound to the owner: ' . substr($sql, 0, 200));
        }

        $reflection = new \ReflectionClass(\SpeedPuzzling\Web\Query\GetUnlistedPuzzles::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }

            self::assertSame('ownerPlayerId', $method->getParameters()[0]->getName(), $method->getName() . '() does not take the owner first.');
        }
    }

    /**
     * The page's service takes no player id - there is nothing to pass the wrong one into.
     */
    public function testThePageResolvesTheSignedInPlayerItself(): void
    {
        $reflection = new \ReflectionClass(\SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getName() === '__construct' ? [] : $method->getParameters() as $parameter) {
                self::assertDoesNotMatchRegularExpression('/player|viewer|user|owner/i', $parameter->getName(), 'UnlistedPuzzlePage::' . $method->getName() . '() takes $' . $parameter->getName() . ' - the signed-in player is resolved inside, never handed in.');
            }
        }
    }

    /**
     * The file's code without its comments: a class that only mentions the tables in a docblock reads nothing.
     */
    private static function codeOf(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
