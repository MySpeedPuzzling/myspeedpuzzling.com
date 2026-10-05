<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The single alternative name of old (`puzzle.alternative_name`, `alternativeName`) is gone since puzzle names phase
 * 1c: a puzzle's other names are the `alternative_names` list (PuzzleNames, docs/features/puzzle-names/). The word may
 * only stay where this test says why - a new use fails it until its author has made that decision, and an entry whose
 * file no longer needs it fails it too, so the list only shrinks.
 */
final class LegacyAlternativeNameCoverageTest extends TestCase
{
    private const string INTERIM_FORM_FIELD = 'Interim single "Alternative name" field of the moderator forms (PuzzleNames::withLegacyAlternativeName()) until the phase-3 names editor.';
    private const string API_V1 = 'API v1 field, additive list next to it: kept for existing clients as PuzzleNames::legacyAlternativeName(), `alternativeNames` holds every name.';
    private const string INTERNAL_API = 'Internal API legacy field: the merge queue lists it next to `alternativeNames`.';
    private const string APPEND_ONLY_LOG = 'Append-only logs keep the old shape: decision and merge snapshots written before the names list hold an `alternativeName` string.';

    private const array ALLOWED = [
        'src/Api/V1/PuzzleDetailResponse.php' => self::API_V1,
        'src/Api/V1/PuzzleResponse.php' => self::API_V1,
        'src/Services/Api/PuzzleResponseFactory.php' => self::API_V1,
        'src/Controller/InternalApi/ListPuzzleMergeRequestsController.php' => self::INTERNAL_API,
        'src/Results/PuzzleHistoryChange.php' => self::APPEND_ONLY_LOG,
        'src/Controller/Admin/PuzzleApprovalDetailController.php' => self::INTERIM_FORM_FIELD,
        'src/FormData/ApprovePuzzleFormData.php' => self::INTERIM_FORM_FIELD,
        'src/FormData/PuzzleRecordFormData.php' => self::INTERIM_FORM_FIELD,
        'src/FormType/PuzzleRecordFormType.php' => self::INTERIM_FORM_FIELD,
        'src/Message/ApprovePuzzle.php' => self::INTERIM_FORM_FIELD,
        'src/MessageHandler/ApprovePuzzleChangeRequestHandler.php' => self::INTERIM_FORM_FIELD,
        'src/MessageHandler/ApprovePuzzleHandler.php' => self::INTERIM_FORM_FIELD,
        'src/Services/PuzzleRecordUpdater.php' => self::INTERIM_FORM_FIELD . ' Also names the old snapshot shape PuzzleHistoryChange reads.',
        'src/Value/PuzzleRecordValues.php' => self::INTERIM_FORM_FIELD,
        'templates/admin/_puzzle_change_request_review.html.twig' => self::INTERIM_FORM_FIELD,
        'templates/admin/puzzle_approval_detail.html.twig' => self::INTERIM_FORM_FIELD,
        'templates/admin/puzzle_edit.html.twig' => self::INTERIM_FORM_FIELD,
    ];

    public function testTheOldSingleAlternativeNameIsUsedOnlyWhereAllowListed(): void
    {
        $projectDir = dirname(__DIR__);
        $undecided = [];
        $staleAllowlist = self::ALLOWED;

        $files = (new Finder())
            ->in([$projectDir . '/src', $projectDir . '/templates', $projectDir . '/assets'])
            ->files()
            ->name(['*.php', '*.twig', '*.js', '*.scss', '*.json']);

        foreach ($files as $file) {
            if (preg_match('/\b(?:alternative_name|alternativeName)\b/i', $file->getContents()) !== 1) {
                continue;
            }

            $path = substr($file->getPathname(), strlen($projectDir) + 1);

            if (array_key_exists($path, self::ALLOWED)) {
                unset($staleAllowlist[$path]);

                continue;
            }

            $undecided[] = $path;
        }

        sort($undecided);

        self::assertSame([], $undecided, 'These files use the old single alternative name (`alternative_name` / `alternativeName`) - read the `alternative_names` list (PuzzleNames) instead, or allow-list the file here with a reason.');
        self::assertSame([], array_keys($staleAllowlist), 'Allow-listed files that no longer use the old single alternative name - remove them from the list.');
    }
}
