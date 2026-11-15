<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportRowsToFix;
use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Results\ImportRowsToFixGroup;
use SpeedPuzzling\Web\Results\ImportSheetRow;

/**
 * What the "To fix" page of an import is rendered with (docs/features/data-import/pairing-screens.md "To fix") -
 * for its own page and for the same page again after a refused answer. Three statements whatever the import's size.
 */
readonly final class ImportToFixPage
{
    public const int GROUPS_PER_PAGE = 50;
    public const int LEFT_OUT_SHOWN = 50;

    public function __construct(
        private GetDataImports $getDataImports,
        private GetImportRowsToFix $getImportRowsToFix,
    ) {
    }

    /**
     * @param array<string, string> $errors by group key
     * @param array<string, string> $typed by group key
     *
     * @return array{import: DataImportOverview, groups: list<ImportRowsToFixGroup>, total: int, page: int, pages: int, left_out: array{rows: list<ImportSheetRow>, total: int}, errors: array<string, string>, typed: array<string, string>, max_rows: int}
     */
    public function parameters(string $importId, string $playerId, int $page, array $errors = [], array $typed = []): array
    {
        $import = $this->getDataImports->byIdOfPlayer($importId, $playerId);
        $page = max(1, $page);
        $found = $this->getImportRowsToFix->groups($importId, $playerId, self::GROUPS_PER_PAGE, ($page - 1) * self::GROUPS_PER_PAGE);

        return [
            'import' => $import,
            'groups' => $found['groups'],
            'total' => $found['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($found['total'] / self::GROUPS_PER_PAGE)),
            'left_out' => $this->getImportRowsToFix->leftOutByPlayer($importId, $playerId, self::LEFT_OUT_SHOWN),
            'errors' => $errors,
            'typed' => $typed,
            'max_rows' => ImportRowAnswers::MAX_ROWS,
        ];
    }
}
