<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A player's own fields and values are private by construction (docs/features/custom-fields.md): they live in tables
 * of their own that no shared read selects, and they are read for the signed-in player only. That holds only while
 * nobody reaches them from a shared page "just this once" - through SQL, or through one of the classes that hand
 * them out. So every file under src/ whose code (comments aside) names
 *
 * - the storage: the tables `player_custom_field` / `solving_time_custom_values` (any `custom_field…` name), the
 *   column `custom_values`, the entities `CustomField` / `SolvingTimeCustomValues` and their repositories, or
 * - anything that hands fields or values out: the readers `GetCustomFields` / `GetCustomValues`, the services
 *   `ViewerResultFields`, `OwnCustomValuesOnResults`, `AddFormCustomFields`, `CustomValuesOfRemovedCopy`,
 *   `CustomFieldLabels`, and the objects that carry them - `CustomValues`, `OwnCustomValues`, `CustomFieldOverview`,
 *   `CustomFieldsForm`
 *
 * is listed here with the reason it may. A new caller - a profile controller asking for the profile owner's values,
 * an API resource, a list query - fails this test until its author has made that decision and written it down.
 *
 * What the test can not see: what a listed file does with them. That is what the reasons are for (each says whose
 * fields the file touches, so a review can check it) and what CustomValuesPrivacyCanaryTest checks from the outside.
 */
final class CustomValuesCoverageTest extends TestCase
{
    private const string NEEDLES = '/custom_field|custom_values|\b(?:CustomField|CustomFieldRepository|SolvingTimeCustomValues|SolvingTimeCustomValuesRepository|GetCustomFields|GetCustomValues|ViewerResultFields|OwnCustomValuesOnResults|AddFormCustomFields|CustomValuesOfRemovedCopy|CustomFieldLabels|CustomValues|OwnCustomValues|CustomFieldOverview|CustomFieldsForm)\b/';

    private const string MANAGE_PAGE = 'The manage page "My fields" (signed-in only): lists and changes the fields of RetrieveLoggedUserProfile\'s player - no player id comes from the URL; a field id from the URL is looked up among that player\'s fields only.';
    private const string FIELD_WRITE = 'Write side of a player\'s own fields: loads the acting player\'s fields (CustomFieldRepository::ofPlayer / getOwnedBy) - somebody else\'s field is "not found".';
    private const string MEMBER_LEFT = 'Deletes the values row of a player who is no longer on the result - nothing is read out or shown.';
    private const string COPY_REMOVED = 'Duplicate results: before a copy is deleted, CustomValuesOfRemovedCopy moves each player\'s row onto the kept result of the same player - nothing is read out or shown.';
    private const string NO_QUERY = 'Feature-internal value/DTO - holds or checks what an owner-only reader or writer gave it, runs no query and is reached only through the listed services.';

    /** Files that may name the storage or what hands it out */
    private const array ALLOWED = [
        // The storage
        'Entity/CustomField.php' => 'The entity.',
        'Entity/SolvingTimeCustomValues.php' => 'The entity - one row per result and player.',
        'Repository/CustomFieldRepository.php' => 'Write-side repository: a player\'s own fields (getOwnedBy refuses anybody else\'s).',
        'Repository/SolvingTimeCustomValuesRepository.php' => 'Write-side repository: rows by result and player, for the writer and the clean-ups - never for display.',

        // The two readers
        'Query/GetCustomFields.php' => 'THE reader of a player\'s fields. Callers pass the signed-in player\'s id (see each caller\'s reason).',
        'Query/GetCustomValues.php' => 'THE reader of the values - every statement bound to the viewer\'s id.',

        // What hands them out
        'Services/CustomFields/ViewerResultFields.php' => 'The signed-in player\'s fields for results, with the "has none" memo of their session. Called by AddFormCustomFields and OwnCustomValuesOnResults (which resolve the signed-in player themselves) and by the manage page to clear the memo.',
        'Services/CustomFields/OwnCustomValuesOnResults.php' => 'The owner-only block of the result detail. Takes no player id: resolves the signed-in viewer itself and reads only the attempts that viewer is on.',
        'Services/CustomFields/AddFormCustomFields.php' => 'The add-result form. Takes no player id: resolves the signed-in player itself, shows their fields and saves their values on the result they just saved.',
        'Services/CustomFields/CustomValuesOfRemovedCopy.php' => self::COPY_REMOVED,
        'Services/CustomFields/SubmittedCustomValues.php' => 'Reads the `my_fields[…]` inputs of the current request (their name is CustomFieldsForm::INPUT) - what the signed-in player just typed, never stored values.',
        'Services/CustomFields/CustomFieldLabels.php' => 'Words a field and a value (ready-made names in the page language) - formats what it is given, reads nothing.',
        'Services/CustomFields/CustomFieldRefusalMessage.php' => 'Words a refused add/edit of the player\'s own field - names the entity for its limits only.',
        'Twig/CustomFieldsTwigExtension.php' => 'Twig functions over CustomFieldLabels for the feature\'s own templates (templates/my_fields/) - formats what the template was given, reads nothing.',

        // The pages of the feature
        'Controller/CustomFields/CustomFieldsController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/AddReadyMadeCustomFieldController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/ArchiveCustomFieldController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/EditCustomFieldController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/MoveCustomFieldController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/SolvingTimeCustomValuesController.php' => 'The values form of a result (signed-in only): 404 unless RetrieveLoggedUserProfile\'s player is on the result, then that player\'s fields and that player\'s values (GetCustomValues::forViewer with their id).',

        // The two shared pages that show a player their own
        'Controller/PuzzleAddController.php' => 'The add-result form: calls AddFormCustomFields::forForm() / saveSubmitted(), which take no player id and resolve the signed-in player themselves.',
        'Controller/PuzzleResultDetailController.php' => 'The result detail: calls OwnCustomValuesOnResults::ofSignedInViewer(), which takes no player id - the block can only be the viewer\'s own, on attempts the viewer is on.',

        // Writes
        'MessageHandler/AddCustomFieldHandler.php' => self::FIELD_WRITE,
        'MessageHandler/ArchiveCustomFieldHandler.php' => self::FIELD_WRITE,
        'MessageHandler/EditCustomFieldHandler.php' => self::FIELD_WRITE,
        'MessageHandler/ReorderCustomFieldsHandler.php' => self::FIELD_WRITE,
        'MessageHandler/SetSolvingTimeCustomValuesHandler.php' => 'THE writer of the values: the acting player\'s own row of a result they are on (memberPlayerIds), their own fields only.',
        'MessageHandler/DeletePlayerHandler.php' => 'Account deletion: deletes the player\'s fields and their values on every result, by player id.',
        'MessageHandler/RemoveCustomValuesOfFormerMembers.php' => self::MEMBER_LEFT,
        'MessageHandler/ReplaceGroupMemberWithGuestHandler.php' => self::MEMBER_LEFT,
        'Services/EventResultsImport/EventResultSlotRelease.php' => self::MEMBER_LEFT,
        'MessageHandler/KeepDuplicateCopyHandler.php' => self::COPY_REMOVED,
        'MessageHandler/AutoRemoveCertainDuplicateHandler.php' => self::COPY_REMOVED,

        // Values and DTOs of the feature
        'Value/CustomValues.php' => self::NO_QUERY,
        'Value/CustomFieldDefinition.php' => 'Checks a field\'s name and choices (uses CustomValues::isStorableText) - no query.',
        'Results/OwnCustomValues.php' => self::NO_QUERY,
        'Results/CustomFieldOverview.php' => self::NO_QUERY,
        'Results/CustomFieldsForm.php' => self::NO_QUERY,
        'FormData/CustomFieldFormData.php' => 'The manage page\'s form data - prefilled from one of the signed-in player\'s own fields (CustomFieldOverview).',
    ];

    public function testOnlyTheListedOwnerOnlyCodeNamesTheCustomFieldsStorageOrWhatHandsItOut(): void
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

        self::assertSame([], $unlisted, 'These files name the custom fields\' storage or a class that hands fields or values out. A player\'s own fields and values are private (docs/features/custom-fields.md): they are read for the signed-in player only and never reach a shared read (a result list, the result detail\'s query, a profile, the API). If the file shows a player their OWN fields - through a service that resolves the signed-in player itself - list it here and say whose fields it touches.');
        self::assertSame([], array_keys($stale), 'These files no longer name the custom fields\' storage or what hands it out - remove them from the list.');
    }

    /**
     * The services a shared page calls take no player id - there is nothing to pass the wrong one into.
     */
    public function testTheServicesSharedPagesCallTakeNoPlayerId(): void
    {
        $services = [
            \SpeedPuzzling\Web\Services\CustomFields\OwnCustomValuesOnResults::class => ['ofSignedInViewer'],
            \SpeedPuzzling\Web\Services\CustomFields\AddFormCustomFields::class => ['forForm', 'saveSubmitted'],
        ];

        foreach ($services as $class => $expectedMethods) {
            $reflection = new \ReflectionClass($class);
            $public = array_values(array_filter(
                array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)),
                static fn (string $name): bool => $name !== '__construct',
            ));

            self::assertSame($expectedMethods, $public, $class . ': a new public method is a new way to hand values out - decide it here.');

            foreach ($expectedMethods as $name) {
                foreach ($reflection->getMethod($name)->getParameters() as $parameter) {
                    self::assertDoesNotMatchRegularExpression('/player|viewer|user|owner/i', $parameter->getName(), $class . '::' . $name . '() takes $' . $parameter->getName() . ' - the signed-in player is resolved inside, never handed in.');
                }
            }
        }
    }

    /**
     * The shared reads the design names, and the whole API: none of them may know the fields or values at all.
     */
    public function testTheSharedReadsAndTheApiDoNotKnowTheValues(): void
    {
        $root = (string) realpath(__DIR__ . '/../src');
        $files = [
            $root . '/Query/GetPlayerSolvedPuzzles.php',
            $root . '/Query/GetPuzzleResultDetail.php',
            $root . '/Query/GetPlayerProfile.php',
            $root . '/Controller/EditTimeController.php',
            $root . '/Controller/PlayerProfileController.php',
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
            self::assertDoesNotMatchRegularExpression('/custom_field|custom_values|CustomField|CustomValues|my_fields/i', (string) file_get_contents($path), $path);
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
                // A profile controller reaching for somebody's values through a wrapper
                'private OwnCustomValuesOnResults $ownCustomValuesOnResults,',
                '$this->viewerResultFields = $container->get(ViewerResultFields::class);',
                'private AddFormCustomFields $addFormCustomFields,',
                'CustomValues::fromStored($row[\'x\'])',
                'function show(OwnCustomValues $values): void',
                'array_map(static fn (CustomFieldOverview $field): string => $field->name, $fields)',
                'new CustomFieldsForm($fields)',
                'private CustomFieldLabels $labels',
                '$this->customValuesOfRemovedCopy = new CustomValuesOfRemovedCopy($repository, $clock);',
            ] as $code
        ) {
            self::assertMatchesRegularExpression(self::NEEDLES, $code);
        }

        // A class that only has the words in its name (a message, an exception) hands nothing out
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'final class AddReadyMadeCustomFieldMessage');
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'new ArchiveCustomField($fieldId, $playerId)');
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'new SetSolvingTimeCustomValues($timeId, $playerId, $values)');
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
