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
 *   column `custom_values` (of the values table and of `collection_item`), the entities `CustomField` /
 *   `SolvingTimeCustomValues` and their repositories, **the collection item's own values** - its property and its
 *   three methods `customValues` / `changeCustomValues` / `fillCustomValueGapsFrom` - or
 * - anything that hands fields or values out: the readers `GetCustomFields` / `GetCustomValues` /
 *   `GetCollectionItemCustomValues`, the services `ViewerResultFields`, `OwnCustomValuesOnResults`,
 *   `AddFormCustomFields`, `OwnCustomValuesInExport`, `CustomValuesOfRemovedCopy`, `SubmittedValuesChange`,
 *   `CustomFieldLabels`, and the objects that carry them - `CustomValues`, `OwnCustomValues`, `OwnCustomColumns`,
 *   `CustomFieldOverview`, `CustomFieldsForm`
 *
 * is listed here with the reason it may. A new caller - a profile controller asking for the profile owner's values,
 * an API resource, a list query - fails this test until its author has made that decision and written it down.
 *
 * Collection pages are often public and their lists are shared between viewers, and `CollectionItem` is an entity half
 * the app holds - so the item's values are private in the entity (no public property), and whoever calls one of its
 * three methods is on this list: the owner's edit form, the writers, the export. No collection page, list, card, API
 * resource, share page, marketplace or lending class is.
 *
 * What the test can not see: what a listed file does with them. That is what the reasons are for (each says whose
 * fields the file touches, so a review can check it) and what CustomValuesPrivacyCanaryTest checks from the outside.
 */
final class CustomValuesCoverageTest extends TestCase
{
    private const string NEEDLES = '/custom_field|custom_values|\b(?:CustomField|CustomFieldRepository|SolvingTimeCustomValues|SolvingTimeCustomValuesRepository|GetCustomFields|GetCustomValues|GetCollectionItemCustomValues|ViewerResultFields|OwnCustomValuesOnResults|AddFormCustomFields|OwnCustomValuesInExport|CustomValuesOfRemovedCopy|SubmittedValuesChange|CustomFieldLabels|CustomValues|OwnCustomValues|OwnCustomColumns|CustomFieldOverview|CustomFieldsForm|customValues|changeCustomValues|fillCustomValueGapsFrom)\b/';

    private const string MANAGE_PAGE = 'The manage page "My fields" (signed-in only): lists and changes the fields of RetrieveLoggedUserProfile\'s player - no player id comes from the URL; a field id from the URL is looked up among that player\'s fields only.';
    private const string FIELD_WRITE = 'Write side of a player\'s own fields: loads the acting player\'s fields (CustomFieldRepository::ofPlayer / getOwnedBy) - somebody else\'s field is "not found".';
    private const string MEMBER_LEFT = 'Deletes the values row of a player who is no longer on the result - nothing is read out or shown.';
    private const string COPY_REMOVED = 'Duplicate results: before a copy is deleted, CustomValuesOfRemovedCopy moves each player\'s row onto the kept result of the same player - nothing is read out or shown.';
    private const string ITEMS_BECOME_ONE = 'Two collection items of one player become one (the target collection already holds the puzzle): CollectionItem::fillCustomValueGapsFrom() fills the staying item\'s empty fields from the leaving item of the same player - nothing is read out or shown.';
    private const string EXPORT_WRITER = 'Renders an owner-only export: writes the OwnCustomColumns it is handed (built by OwnCustomValuesInExport for the signed-in player) as extra columns - runs no query, reads nothing itself.';
    private const string SENTRY = 'Keeps what a player typed out of what is sent to Sentry: names the classes whose values it withholds - reads nothing, shows nothing.';
    private const string NO_QUERY = 'Feature-internal value/DTO - holds or checks what an owner-only reader or writer gave it, runs no query and is reached only through the listed services.';

    /** Files that may name the storage or what hands it out */
    private const array ALLOWED = [
        // The storage
        'Entity/CustomField.php' => 'The entity.',
        'Entity/SolvingTimeCustomValues.php' => 'The entity - one row per result and player.',
        'Entity/CollectionItem.php' => 'The collection item: the owner\'s own values in a private property (`custom_values`), reached only through customValues() / changeCustomValues() / fillCustomValueGapsFrom() - every caller of those is on this list.',
        'Repository/CustomFieldRepository.php' => 'Write-side repository: a player\'s own fields (getOwnedBy refuses anybody else\'s).',
        'Repository/SolvingTimeCustomValuesRepository.php' => 'Write-side repository: rows by result and player, for the writer and the clean-ups - never for display.',

        // The two readers
        'Query/GetCustomFields.php' => 'THE reader of a player\'s fields. Callers pass the signed-in player\'s id (see each caller\'s reason).',
        'Query/GetCustomValues.php' => 'THE reader of the values on results - every statement bound to the viewer\'s id.',
        'Query/GetCollectionItemCustomValues.php' => 'THE reader of the values on collection items - every statement bound to the viewer\'s id (`WHERE player_id = :viewerPlayerId`). Called by the owner\'s edit form and the owner\'s export only.',

        // What hands them out
        'Services/CustomFields/ViewerResultFields.php' => 'The signed-in player\'s fields for results, with the "has none" memo of their session. Called by AddFormCustomFields and OwnCustomValuesOnResults (which resolve the signed-in player themselves) and by the manage page to clear the memo.',
        'Services/CustomFields/OwnCustomValuesOnResults.php' => 'The owner-only block of the result detail. Takes no player id: resolves the signed-in viewer itself and reads only the attempts that viewer is on.',
        'Services/CustomFields/AddFormCustomFields.php' => 'The add-result form. Takes no player id: resolves the signed-in player itself, shows their fields and saves their values on the result they just saved.',
        'Services/CustomFields/OwnCustomValuesInExport.php' => 'The owner-only exports. Takes no player id: resolves the signed-in player itself and reads that player\'s fields and values (results / collection items) for the file they download.',
        'Services/CustomFields/SubmittedValuesChange.php' => 'The one rule of both writers: checks submitted values against the acting player\'s fields and returns what the row holds afterwards - pure, runs no query, shows nothing.',
        'Services/CustomFields/CustomValuesOfRemovedCopy.php' => self::COPY_REMOVED,
        'Services/CustomFields/SubmittedCustomValues.php' => 'Reads the `my_fields[…]` inputs of the current request (their name is CustomFieldsForm::INPUT) - what the signed-in player just typed, never stored values.',
        'Services/CustomFields/CustomFieldLabels.php' => 'Words a field and a value (ready-made names in the page language) - formats what it is given, reads nothing.',
        'Services/CustomFields/CustomFieldRefusalMessage.php' => 'Words a refused add/edit of the player\'s own field - names the entity for its limits only.',
        'Services/Sentry/OwnFieldsSentryScrubber.php' => self::SENTRY,
        'Services/Sentry/GenericObjectSerializer.php' => self::SENTRY,
        'Twig/CustomFieldsTwigExtension.php' => 'Twig functions over CustomFieldLabels for the feature\'s own templates (templates/my_fields/) - formats what the template was given, reads nothing.',

        // The pages of the feature
        'Controller/CustomFields/CustomFieldsController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/AddReadyMadeCustomFieldController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/ArchiveCustomFieldController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/EditCustomFieldController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/MoveCustomFieldController.php' => self::MANAGE_PAGE,
        'Controller/CustomFields/SolvingTimeCustomValuesController.php' => 'The values form of a result (signed-in only): 404 unless RetrieveLoggedUserProfile\'s player is on the result, then that player\'s fields and that player\'s values (GetCustomValues::forViewer with their id).',

        // The collection item's edit form - the only page that shows an item's values
        'Controller/EditCollectionItemCommentController.php' => 'The owner\'s edit form of one collection item (signed-in only, 404 unless the item is RetrieveLoggedUserProfile\'s player\'s): that player\'s fields for collection items and that item\'s values through GetCollectionItemCustomValues::forViewer with their id.',

        // The owner-only exports
        'Controller/ExportPuzzlerDataDownloadController.php' => 'The results export (403 unless the URL\'s player is the signed-in one): calls OwnCustomValuesInExport::onResultsOfSignedInViewer(), which takes no player id.',
        'Controller/ExportPuzzleLibraryDownloadController.php' => 'The library export (403 unless the URL\'s player is the signed-in one): calls OwnCustomValuesInExport::onCollectionItemsOfSignedInViewer(), which takes no player id.',
        'Services/PuzzlerDataExporter.php' => self::EXPORT_WRITER,
        'Services/Export/SectionedExportWriter.php' => self::EXPORT_WRITER,
        'Services/Export/PuzzleLibraryExportBuilder.php' => 'Builds the owner-only library export: puts the OwnCustomColumns it is handed onto the `collection_items` rows by item id - its own queries (GetExportable…) select nothing of the values.',

        // The two shared pages that show a player their own
        'Controller/PuzzleAddController.php' => 'The add-result form: calls AddFormCustomFields::forForm() / saveSubmitted(), which take no player id and resolve the signed-in player themselves.',
        'Controller/PuzzleResultDetailController.php' => 'The result detail: calls OwnCustomValuesOnResults::ofSignedInViewer(), which takes no player id - the block can only be the viewer\'s own, on attempts the viewer is on.',

        // Writes
        'MessageHandler/AddCustomFieldHandler.php' => self::FIELD_WRITE,
        'MessageHandler/ArchiveCustomFieldHandler.php' => self::FIELD_WRITE,
        'MessageHandler/EditCustomFieldHandler.php' => self::FIELD_WRITE,
        'MessageHandler/ReorderCustomFieldsHandler.php' => self::FIELD_WRITE,
        'MessageHandler/SetSolvingTimeCustomValuesHandler.php' => 'THE writer of the values on results: the acting player\'s own row of a result they are on (memberPlayerIds), their own fields only.',
        'MessageHandler/SetCollectionItemCustomValuesHandler.php' => 'THE writer of the values on collection items: the acting player\'s own item (anybody else\'s is "not found"), their own fields for collection items only.',
        'MessageHandler/CopyPuzzlesToCollectionHandler.php' => 'Copying a player\'s own items into another collection of theirs: the copy starts with the source item\'s values (changeCustomValues) - nothing is read out or shown.',
        'MessageHandler/MovePuzzlesToCollectionHandler.php' => self::ITEMS_BECOME_ONE,
        'MessageHandler/MovePuzzleToCollectionHandler.php' => self::ITEMS_BECOME_ONE,
        'MessageHandler/ApprovePuzzleMergeRequestHandler.php' => 'Puzzle merge: an item that meets the same player\'s item of the surviving puzzle in the same collection fills that item\'s empty fields (fillCustomValueGapsFrom) before it is removed - nothing is read out, shown or written to the merge audit.',
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
        'Results/Export/OwnCustomColumns.php' => self::NO_QUERY,
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
            // Not a shared page, but the same rule: an export can only carry the downloading player's own
            \SpeedPuzzling\Web\Services\CustomFields\OwnCustomValuesInExport::class => ['onResultsOfSignedInViewer', 'onCollectionItemsOfSignedInViewer'],
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
            // Collection pages are often public, their lists shared: nothing that reads or shows a collection knows
            // the items' values - not the lists, not the cards' DTO, not the export's own item query (the export gets
            // them from the viewer-bound reader), not adding a puzzle to a collection
            $root . '/Query/GetCollectionItems.php',
            $root . '/Query/GetPlayerCollectionsWithCounts.php',
            $root . '/Query/GetPuzzleCollections.php',
            $root . '/Query/GetUnsolvedPuzzles.php',
            $root . '/Query/GetBorrowCandidates.php',
            $root . '/Query/GetExportableCollectionItems.php',
            $root . '/Results/CollectionItemOverview.php',
            $root . '/Results/Export/ExportableCollectionItem.php',
            $root . '/Controller/CollectionDetailController.php',
            $root . '/Controller/SystemCollectionDetailController.php',
            $root . '/Services/CollectionSelectionResponder.php',
            $root . '/Repository/CollectionItemRepository.php',
            $root . '/MessageHandler/AddPuzzleToCollectionHandler.php',
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

    /**
     * Templates are code too: a template that is handed a collection item (an entity half the app passes around) can
     * print `item.customValues.values`, and one that is handed the viewer's own block can be included anywhere. Every
     * template that names the values - the entity's methods, the column, a `.values` read, the Twig function that
     * words a value, the variables that carry them (`my_fields`, `own_field_values`) - is listed with its reason; a
     * template under `templates/my_fields/` is the feature's own.
     */
    private const string TEMPLATE_NEEDLES = '/custom_?values|\.values\b|own_field_values|my_field_value_text|\.filledOn\b|\bmy_fields\b/i';

    private const string OWN_TEMPLATES = 'my_fields/';

    /** Templates outside templates/my_fields/ that may name the values */
    private const array ALLOWED_TEMPLATES = [
        'collections/edit-item-comment.html.twig' => 'The owner\'s edit form of one collection item (the controller 404s for anybody else): includes my_fields/_inputs.html.twig with the CustomFieldsForm of the signed-in owner - it never reads the item\'s values off the entity.',
        '_solving_time_form.html.twig' => 'The add-result form: one expression that includes my_fields/_add_form_block.html.twig when `my_fields` is there - the CustomFieldsForm AddFormCustomFields built for the signed-in player from the request (what was just typed, never stored values).',
        'puzzle_result/_body.html.twig' => 'The result detail: includes my_fields/_result_block.html.twig with `own_field_values`, which OwnCustomValuesOnResults built for the signed-in viewer alone (null for anybody else - then nothing is included).',
    ];

    public function testOnlyTheListedTemplatesNameTheValues(): void
    {
        $unlisted = [];
        $stale = self::ALLOWED_TEMPLATES;
        $ownTemplates = 0;
        $root = (string) realpath(__DIR__ . '/../templates');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }

            if (preg_match(self::TEMPLATE_NEEDLES, self::templateCodeOf((string) file_get_contents($file->getPathname()))) !== 1) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            if (str_starts_with($relative, self::OWN_TEMPLATES)) {
                $ownTemplates++;

                continue;
            }

            unset($stale[$relative]);

            if (array_key_exists($relative, self::ALLOWED_TEMPLATES) === false) {
                $unlisted[] = $relative;
            }
        }

        sort($unlisted);

        self::assertSame([], $unlisted, 'These templates name a player\'s own values (the collection item\'s customValues, a `.values` read, the viewer\'s own block). The values are private (docs/features/custom-fields.md): a collection page is often public and its lists are shared, a result is shown to everybody. If the template shows the signed-in player their OWN values, handed in by a service that resolves the signed-in player itself, list it here and say so.');
        self::assertSame([], array_keys($stale), 'These templates no longer name the values - remove them from the list.');
        self::assertGreaterThan(1, $ownTemplates, 'The feature\'s own templates were found - the scan sees what it should');
    }

    public function testTheTemplateNeedlesCatchWhatTheyShould(): void
    {
        foreach (
            [
                '{{ item.customValues.values|json_encode }}',
                '{% for value in collectionItem.customValues.values %}',
                '{{ row.custom_values }}',
                '{{ stored.values[field.fieldId] }}',
                '{{ include(\'my_fields/_result_block.html.twig\', {own_field_values: own_field_values}) }}',
                '{{ my_field_value_text(field, value) }}',
                '{% for entry in something.filledOn(time_id) %}',
                '{{ my_fields.inputs[field.fieldId] }}',
                '{{ my_fields.input(field) }}',
                '{{ my_fields|default(null) is not null ? include(\'my_fields/_add_form_block.html.twig\') : \'\' }}',
                // Inside an attribute's quotes it is still Twig
                '<div class="card" data-values="{{ item.customValues.values|json_encode }}">',
            ] as $template
        ) {
            self::assertMatchesRegularExpression(self::TEMPLATE_NEEDLES, self::templateCodeOf($template), $template);
        }

        // A text key, a route or a comment that only has the words hands nothing out
        foreach (
            [
                '{{ \'my_fields.values.title\'|trans }}',
                '<a href="{{ path(\'my_fields\') }}">{{ "my_fields.values.saved"|trans }}</a>',
                '{# the item.customValues are never shown here #}',
                '{{ form.vars.value }}',
            ] as $template
        ) {
            self::assertDoesNotMatchRegularExpression(self::TEMPLATE_NEEDLES, self::templateCodeOf($template), $template);
        }
    }

    /**
     * No query reads a collection item wholesale. `collection_item.custom_values` is named by one reader - but a
     * `SELECT ci.*`, a `SELECT * FROM collection_item` or a `to_jsonb(ci)` would carry the column without naming it,
     * straight past the needles above. Files that do so on purpose are listed with their reason (none today).
     */
    private const array ALLOWED_WHOLESALE_READS = [];

    /**
     * @return array<string, string> file => the reason it may
     */
    private static function allowedWholesaleReads(): array
    {
        return self::ALLOWED_WHOLESALE_READS;
    }

    public function testNoQueryReadsACollectionItemWholesale(): void
    {
        $offenders = [];
        $scanned = 0;
        $root = (string) realpath(__DIR__ . '/../src');

        foreach (['Query', 'Services'] as $directory) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $code = self::codeOf($file->getPathname());

                if (preg_match('/\bcollection_item\b/', $code) !== 1) {
                    continue;
                }

                $scanned++;
                $relative = substr($file->getPathname(), strlen($root) + 1);

                if (self::readsCollectionItemsWholesale($code) && array_key_exists($relative, self::allowedWholesaleReads()) === false) {
                    $offenders[] = $relative;
                }
            }
        }

        sort($offenders);

        self::assertGreaterThan(5, $scanned, 'The queries that read collection items were found - the scan sees what it should');
        self::assertSame([], $offenders, 'These files read `collection_item` with a wildcard (`alias.*`, `SELECT * FROM collection_item`) or as a whole row (`to_jsonb(`, `row_to_json(`). That selects `custom_values` - a player\'s private values (docs/features/custom-fields.md "Collection items") - without naming it. Select the columns by name.');
    }

    public function testTheWholesaleCheckCatchesWhatItShould(): void
    {
        foreach (
            [
                'SELECT ci.* FROM collection_item ci WHERE ci.player_id = :playerId',
                'SELECT ci.id, ci.* FROM collection_item AS ci',
                'SELECT collection_item.* FROM collection_item',
                'SELECT * FROM collection_item WHERE player_id = :playerId',
                "SELECT *\nFROM collection_item\nWHERE player_id = :playerId",
                'SELECT to_jsonb(ci) FROM collection_item ci',
                'SELECT row_to_json(item) FROM collection_item item',
                'SELECT p.name, item.* FROM puzzle p INNER JOIN collection_item item ON item.puzzle_id = p.id',
            ] as $sql
        ) {
            self::assertTrue(self::readsCollectionItemsWholesale($sql), $sql);
        }

        foreach (
            [
                'SELECT ci.id, ci.comment FROM collection_item ci',
                'SELECT COUNT(*) FROM collection_item WHERE player_id = :playerId',
                // A wildcard over another table or a subquery whose own columns are named
                'SELECT p.* FROM puzzle p WHERE EXISTS (SELECT 1 FROM collection_item ci WHERE ci.puzzle_id = p.id)',
                'SELECT * FROM (SELECT ci.id, ci.puzzle_id FROM collection_item ci) owned',
            ] as $sql
        ) {
            self::assertFalse(self::readsCollectionItemsWholesale($sql), $sql);
        }
    }

    private static function readsCollectionItemsWholesale(string $code): bool
    {
        // Every name the table goes by in the file: itself and its aliases
        preg_match_all('/\bcollection_item\s+(?:AS\s+)?([a-z_][a-z0-9_]*)\b/i', $code, $matches);
        $names = ['collection_item'];

        foreach ($matches[1] as $alias) {
            if (in_array(strtoupper($alias), ['WHERE', 'ON', 'INNER', 'LEFT', 'RIGHT', 'JOIN', 'CROSS', 'ORDER', 'GROUP', 'SET', 'USING', 'LIMIT', 'UNION', 'AS', 'FOR', 'HAVING', 'VALUES', 'RETURNING', 'FULL', 'NATURAL', 'WINDOW', 'OFFSET'], true) === false) {
                $names[] = $alias;
            }
        }

        foreach (array_unique($names) as $name) {
            $name = preg_quote($name, '/');

            if (preg_match('/\b' . $name . '\.\*|\b(?:to_jsonb?|row_to_json|json_agg|jsonb_agg|array_agg)\(\s*' . $name . '\s*\)/i', $code) === 1) {
                return true;
            }
        }

        return preg_match('/SELECT\s+\*\s+FROM\s+collection_item\b/i', $code) === 1;
    }

    /**
     * What Twig runs in a template - its `{{ }}` and `{% %}`, without comments and quoted texts: a translation key, a
     * route name or a comment naming the values decides nothing.
     */
    private static function templateCodeOf(string $template): string
    {
        $withoutComments = (string) preg_replace('/\{#.*?#\}/s', '', $template);
        // Only what Twig runs: the markup around it (attributes in quotes, texts) is no code
        preg_match_all('/\{\{.*?\}\}|\{%.*?%\}/s', $withoutComments, $blocks);

        return (string) preg_replace('/\'[^\'\n]*\'|"[^"\n]*"/', "''", implode("\n", $blocks[0]));
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
                // A collection page, card or API resource reaching for an item's values
                'SELECT ci.id, ci.custom_values FROM collection_item ci',
                '$values = $collectionItem->customValues();',
                '$item->changeCustomValues($values);',
                '$target->fillCustomValueGapsFrom($source);',
                'private GetCollectionItemCustomValues $getCollectionItemCustomValues,',
                'private OwnCustomValuesInExport $ownCustomValuesInExport,',
                'function write(OwnCustomColumns $custom): void',
                'SubmittedValuesChange::apply($stored, $fields, $submitted)',
            ] as $code
        ) {
            self::assertMatchesRegularExpression(self::NEEDLES, $code);
        }

        // A class that only has the words in its name (a message, an exception) hands nothing out
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'final class AddReadyMadeCustomFieldMessage');
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'new ArchiveCustomField($fieldId, $playerId)');
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'new SetSolvingTimeCustomValues($timeId, $playerId, $values)');
        self::assertDoesNotMatchRegularExpression(self::NEEDLES, 'new SetCollectionItemCustomValues($collectionItemId, $playerId, $values)');
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
