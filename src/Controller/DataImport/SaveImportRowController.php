<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportRowsToFix;
use SpeedPuzzling\Web\Results\ImportRowDetail;
use SpeedPuzzling\Web\Results\ImportSheetRow;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Value\DataImport\ImportRowOrigin;
use SpeedPuzzling\Web\Services\DataImport\ImportRowSheet;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The row sheet saved (docs/features/data-import/pairing-screens.md "The row sheet"): the fields the player changed
 * become the row's answers, an emptied field takes its answer back, "No time - I didn't time this one" is the time's
 * answer ImportRowAnswers::NO_TIME, "Leave this row out" / "Take it in again" - the same message as "To fix"
 * (AnswerImportRows), with the stamp the page showed. POST + CSRF.
 *
 * Saved (PRG): back to the screen the player came from (`return`, ImportRowOrigin - only a screen of this import),
 * which says "Row 14 is saved"; without one - or for "Save and fix the next one" - on to the next row that needs a
 * look, and to "Rows we could not read" when none is left (a row that still needs a look itself stays open
 * instead). A row put back stays open. A value that can not be read
 * for sure: the same page with a 422, the reason at its field and everything typed kept - the way back included.
 * Changed meanwhile: the row as it stands, with a notice. Another player's import, or a row of another import, is a
 * 404.
 */
final class SaveImportRowController extends AbstractController
{
    /**
     * "Row 14 is saved" - a flash of its own: the screen it is shown on is scrolled to the row or group the player
     * came from, so it is said fixed in view (data_import/_saved_notice.html.twig) and not at the top of the page
     */
    public const string SAVED_FLASH = 'data_import_saved';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportRowsToFix $getImportRowsToFix,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/rows/{rowId}',
        name: 'data_import_row_save',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'rowId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $rowId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $form = $request->request;
        $detail = $this->getImportRowsToFix->row($importId, $player->playerId, $rowId);
        $origin = ImportRowOrigin::tryFrom($form->getString('return'), $importId);
        $back = $this->toRow($importId, $rowId, $origin);

        if ($detail->beingSetUp === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $action = $form->getString('action', 'save');
        $submitted = $form->all('answers');
        $stamp = $form->getString('stamp');

        // "No time - I didn't time this one" stands for the time's field, whatever is typed in it - only where the
        // page offers it (never for a row of a shelf whose time cell is empty: it would not be a result)
        if ($form->getBoolean('no_time')) {
            foreach (ImportRowSheet::fields($detail) as $field) {
                if ($field['canBeUntimed']) {
                    $submitted['time'] = ImportRowAnswers::NO_TIME;
                }
            }
        }

        $message = match ($action) {
            'leave_out' => new AnswerImportRows($importId, $player->playerId, [$rowId], leaveOut: true, seenStamp: $stamp),
            'take_in' => new AnswerImportRows($importId, $player->playerId, [$rowId], leaveOut: false, seenStamp: $stamp),
            default => new AnswerImportRows($importId, $player->playerId, [$rowId], ImportRowSheet::changed($detail, $submitted), seenStamp: $stamp),
        };

        if ($message->answers === [] && $message->leaveOut === null) {
            $this->addFlash('info', $this->translator->trans('data_import.row.flash.nothing_changed'));

            // "Save and fix the next one" moves on all the same
            return $action === 'save_next' ? $this->onwards($importId, $player->playerId, $rowId, $origin, true) : $back;
        }

        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof ImportRowAnswerRefused) {
                return $this->refused($importId, $player->playerId, $detail, $submitted, $cause, $origin);
            }

            if ($cause instanceof ImportRowsChangedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('data_import.row.flash.changed_meanwhile'));

                return $back;
            }

            throw $exception;
        }

        $number = ['%number%' => $detail->row->rowNumber];

        if ($action === 'take_in') {
            // Put back to be looked at: the row stays open
            $this->addFlash(self::SAVED_FLASH, $this->translator->trans('data_import.row.flash.taken_in', $number));

            return $back;
        }

        // The row as it is read now
        $now = $this->getImportRowsToFix->row($importId, $player->playerId, $rowId)->row;

        if ($action === 'leave_out') {
            $this->addFlash(self::SAVED_FLASH, $this->translator->trans('data_import.row.flash.left_out', $number));
        } else {
            // Nothing unclear is left, or something still is
            $unclear = array_filter($now->problems, static fn (array $problem): bool => $problem['waiting'] === false);
            $this->addFlash(self::SAVED_FLASH, $this->translator->trans($unclear === [] ? 'data_import.row.flash.saved' : 'data_import.row.flash.saved_needs_look', $number));

            // Still needs a look, and no screen to go back to: the player stays with this row
            if ($unclear !== [] && $origin === null && $action === 'save') {
                return $back;
            }
        }

        // Came from the page of one puzzle of the file, and the row is not that puzzle any more (its name, brand or
        // pieces changed, or it is not imported): that page may be gone once the puzzles are matched again - the
        // way back is the puzzles' overview, which follows the matching live
        if ($origin !== null && $origin->screen === 'puzzle' && self::puzzleKey($detail->row) !== self::puzzleKey($now)) {
            if ($action === 'save_next') {
                return $this->onwards($importId, $player->playerId, $rowId, null, true);
            }

            $this->addFlash('info', $this->translator->trans('data_import.row.flash.puzzle_matched_again'));

            return $this->redirectToRoute('data_import_puzzles', ['importId' => $importId]);
        }

        return $this->onwards($importId, $player->playerId, $rowId, $origin, $action === 'save_next');
    }

    private static function puzzleKey(ImportSheetRow $row): null|string
    {
        $keys = $row->parsed['keys'] ?? null;
        $key = is_array($keys) ? $keys['puzzle'] ?? null : null;

        return is_string($key) ? $key : null;
    }

    /**
     * Where a saved row leads: the screen the player came from; without one - or when asked for the next one - the
     * next row that needs a look; "Rows we could not read" when none is left.
     */
    private function onwards(string $importId, string $playerId, string $rowId, null|ImportRowOrigin $origin, bool $next): Response
    {
        // The group the player came from is gone (its last row was just answered): the top of the list of groups instead, so
        // that the page still lands where the player was
        $anchor = $origin?->anchor();

        if (
            $origin !== null && $origin->screen === 'to_fix' && $anchor !== null && str_starts_with($anchor, 'group-')
            && $this->getImportRowsToFix->groups($importId, $playerId, 1, 0, substr($anchor, 6))['groups'] === []
        ) {
            $origin = $origin->at('import-to-fix-count');
        }

        if ($origin !== null && $next === false) {
            return $this->redirect($origin->url);
        }

        $nextRowId = $this->getImportRowsToFix->nextRowId($importId, $playerId, $rowId);

        if ($nextRowId !== null) {
            return $this->toRow($importId, $nextRowId, $origin);
        }

        return $origin !== null ? $this->redirect($origin->url) : $this->redirectToRoute('data_import_to_fix', ['importId' => $importId]);
    }

    private function toRow(string $importId, string $rowId, null|ImportRowOrigin $origin): Response
    {
        return $this->redirectToRoute('data_import_row', array_filter(['importId' => $importId, 'rowId' => $rowId, 'return' => $origin?->url]));
    }

    /**
     * @param array<array-key, mixed> $submitted
     */
    private function refused(string $importId, string $playerId, ImportRowDetail $detail, array $submitted, ImportRowAnswerRefused $refusal, null|ImportRowOrigin $origin): Response
    {
        $typed = array_filter($submitted, is_string(...));
        $unreadable = $refusal->reason === ImportRowAnswerRefused::UNREADABLE && $refusal->field !== null;

        /** @var array<string, string> $typed */
        return $this->render(
            'data_import/row.html.twig',
            [
                'import' => $this->getDataImports->byIdOfPlayer($importId, $playerId),
                'detail' => $detail,
                'fields' => ImportRowSheet::fields($detail, $typed, $unreadable ? [(string) $refusal->field => 'unreadable'] : []),
                'form_error' => $unreadable ? null : 'refused',
                'origin' => $origin,
                'has_next' => $this->getImportRowsToFix->nextRowId($importId, $playerId, $detail->rowId) !== null,
            ],
            new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }
}
