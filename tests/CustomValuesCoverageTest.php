<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A player's own fields and values are private by construction (docs/features/custom-fields.md): they live in tables
 * of their own that no shared read selects, and exactly one query class reads the values - for the signed-in player
 * only. That holds only while nobody joins them into a result list, a profile or the API "just this once", so every
 * file under src/ whose code (comments aside) names one of the tables, the column, the entities or the reader is
 * listed here with the reason it may - or fails this test until its author has made that decision.
 *
 * The needles: the tables `player_custom_field` / `solving_time_custom_values` (and any `custom_field…` name), the
 * column `custom_values`, the entities `CustomField` / `SolvingTimeCustomValues` with their repositories, and the
 * readers `GetCustomFields` / `GetCustomValues`. That is why the feature's routes, texts, templates and form inputs
 * are named `my_fields…` - the storage names stay rare enough to guard.
 */
final class CustomValuesCoverageTest extends TestCase
{
    private const string NEEDLES = '/custom_field|custom_values|\b(?:CustomField|CustomFieldRepository|SolvingTimeCustomValues|SolvingTimeCustomValuesRepository|GetCustomFields|GetCustomValues)\b/';

    private const string OWNER_PAGE = 'A page of the signed-in player about their own fields - reads and writes take the signed-in player\'s id, never one from the URL.';
    private const string WRITE = 'Write side of the player\'s own fields: the handler loads the acting player\'s fields and refuses anybody else\'s.';
    private const string MEMBER_LEFT = 'Removes the values row of a player who is no longer on the result - a delete, nothing is read out.';

    /** Files that may name the tables, the column, the entities or the readers */
    private const array ALLOWED = [
        'Controller/CustomFields/CustomFieldsController.php' => self::OWNER_PAGE,
        'Controller/CustomFields/EditCustomFieldController.php' => self::OWNER_PAGE,
        'Controller/CustomFields/MoveCustomFieldController.php' => self::OWNER_PAGE,
        'Controller/CustomFields/SolvingTimeCustomValuesController.php' => 'The values form of a result: only whoever is on the result, and only their own values (GetCustomValues::forViewer with the signed-in player\'s id).',
        'Entity/CustomField.php' => 'The entity.',
        'Entity/SolvingTimeCustomValues.php' => 'The entity.',
        'MessageHandler/AddCustomFieldHandler.php' => self::WRITE,
        'MessageHandler/ArchiveCustomFieldHandler.php' => self::WRITE,
        'MessageHandler/EditCustomFieldHandler.php' => self::WRITE,
        'MessageHandler/ReorderCustomFieldsHandler.php' => self::WRITE,
        'MessageHandler/SetSolvingTimeCustomValuesHandler.php' => 'THE writer of the values: the acting player\'s own row of a result they are on.',
        'MessageHandler/DeletePlayerHandler.php' => 'Account deletion: deletes the player\'s fields and their values on every result.',
        'MessageHandler/RemoveCustomValuesOfFormerMembers.php' => self::MEMBER_LEFT,
        'MessageHandler/ReplaceGroupMemberWithGuestHandler.php' => self::MEMBER_LEFT,
        'Services/EventResultsImport/EventResultSlotRelease.php' => self::MEMBER_LEFT,
        'Query/GetCustomFields.php' => 'THE reader of a player\'s fields - for the player\'s own pages, by the signed-in player\'s id.',
        'Query/GetCustomValues.php' => 'THE reader of the values - every statement bound to the viewer\'s id.',
        'Repository/CustomFieldRepository.php' => 'Write-side repository.',
        'Repository/SolvingTimeCustomValuesRepository.php' => 'Write-side repository - by result and player, used by the writer and the member-left cleanups.',
        'Services/CustomFields/AddFormCustomFields.php' => 'The add-result form of the signed-in player: their own fields, saved for them after the result.',
        'Services/CustomFields/OwnCustomValuesOnResults.php' => 'The owner-only block of the result detail: GetCustomValues::forViewer with the signed-in viewer\'s id.',
        'Services/CustomFields/ViewerResultFields.php' => 'The signed-in player\'s own fields for results (with the "has none" memo of their session).',
        'Services/CustomFields/CustomFieldRefusalMessage.php' => 'Words a refused add/edit of the player\'s own field - names the entity for its limit only.',
    ];

    public function testOnlyTheListedOwnerOnlyCodeNamesTheCustomFieldsStorage(): void
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

        self::assertSame([], $unlisted, 'These files name the custom fields\' tables, column, entities or readers. A player\'s own fields and values are private (docs/features/custom-fields.md): they are read by GetCustomValues / GetCustomFields for the signed-in player only and never joined into a shared read (a result list, the result detail\'s query, a profile, the API). If the file is an owner-only reader or writer, list it here with the reason.');
        self::assertSame([], array_keys($stale), 'These files no longer name the custom fields\' storage - remove them from the list.');
    }

    /**
     * The shared reads the design names, and the whole API: none of them may know the values exist.
     */
    public function testTheSharedReadsAndTheApiDoNotKnowTheValues(): void
    {
        $root = (string) realpath(__DIR__ . '/../src');
        $files = [
            $root . '/Query/GetPlayerSolvedPuzzles.php',
            $root . '/Query/GetPuzzleResultDetail.php',
            $root . '/Query/GetPlayerProfile.php',
        ];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/Api', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertGreaterThan(10, count($files));

        foreach ($files as $path) {
            self::assertFileExists($path);
            self::assertDoesNotMatchRegularExpression('/custom_field|custom_values|CustomField|CustomValues/i', (string) file_get_contents($path), $path);
        }
    }

    public function testTheNeedlesCatchWhatTheyShould(): void
    {
        foreach (
            [
                'LEFT JOIN solving_time_custom_values v ON v.solving_time_id = pst.id',
                'SELECT custom_values FROM x',
                'FROM player_custom_field',
                'use SpeedPuzzling\Web\Entity\CustomField;',
                'private GetCustomValues $getCustomValues,',
                'private GetCustomFields $getCustomFields',
                'SolvingTimeCustomValuesRepository $repository',
            ] as $code
        ) {
            self::assertMatchesRegularExpression(self::NEEDLES, $code);
        }

        // A class that only has the words in its name (a controller, a message) is not the storage
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'final class AddReadyMadeCustomFieldController');
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'new ArchiveCustomField($fieldId, $playerId)');
    }

    /**
     * The file without its comments - a comment naming a table decides nothing.
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
