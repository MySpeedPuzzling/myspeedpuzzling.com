<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One Undo of the signed-in player's import (docs/features/data-import/adding-screens.md "Undo"): the live line
 * while the worker takes the rows back, then what happened - taken back, kept because it was changed since (each
 * such result listed with "Remove it too") - that the rows are ready again, and "choose the puzzle again" for rows
 * that had landed on the wrong puzzle. Another player's Undo is a 404. Four statements at most.
 */
final class ImportUndoController extends AbstractController
{
    public const int KEPT_SHOWN = 50;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/undos/{undoId}',
        name: 'data_import_undo_progress',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'undoId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $undoId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $undo = $this->getImportAddScreen->undo($importId, $player->playerId, $undoId);
        $rowId = $request->query->getString('row');
        $kept = $undo['running'] === false && $undo['resultsKeptEdited'] > 0
            ? $this->getImportAddScreen->keptByUndo($importId, $player->playerId, $undo['id'], self::KEPT_SHOWN)
            : [];
        $requestIds = [];

        foreach ($kept as $row) {
            // One id per row's "Remove it too": the same form sent again answers the same Undo
            $requestIds[$row->rowId] = Uuid::uuid7()->toString();
        }

        return $this->render('data_import/undo.html.twig', [
            'import' => $import,
            'undo' => $undo,
            'kept' => $kept,
            'request_ids' => $requestIds,
            // Rows the Undo left alone because their puzzle, kept just for the player, was matched since: the match's own
            // Undo is on that puzzle's page - "Remove it too" could do nothing for them
            'kept_matched' => $this->getImportAddScreen->keptJustForYouOfRows($importId, $player->playerId, array_map(static fn (ImportAddRow $row): string => $row->rowId, $kept)),
            // The one row a single "Take back" was about: its puzzle's pairing can be forgotten from here
            'row_pairing' => $undo['running'] === false && $undo['scope'] === 'rows' && Uuid::isValid($rowId)
                ? $this->getImportAddScreen->pairingOfRow($importId, $player->playerId, $rowId)
                : null,
            'row_id' => Uuid::isValid($rowId) ? strtolower($rowId) : null,
        ]);
    }
}
