<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportMappingChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\ConfirmImportMapping;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Query\GetImportSheetScreen;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Don't import" and "Put back" on "What will be added" (docs/features/data-import/guided-flow.md §7) - each a small
 * POST with its token, answered by a redirect (303) back to the same list. **No state of its own**: what it says is
 * what the import's other screens say, through their messages -
 *
 * - a row, or every ready row of one puzzle: the row's own "leave it out" (AnswerImportRows `leaveOut`), the very
 *   state "Rows we could not read" and the row's page set - so a row not imported here can be put back there, and
 *   the other way round;
 * - a whole sheet: the sheet is "not puzzles" (ConfirmImportMapping, the one-tap change of "How we read your file") -
 *   changed back there.
 *
 * Changed meanwhile (another tab, the sheet being read again): nothing is written, the list as it stands, with a
 * plain notice. Another player's import is a 404.
 */
final class ChangeImportAddListController extends AbstractController
{
    /** Said inside the list's own frame (add_list.html.twig) - so it is there after a frame swap too */
    public const string FLASH = 'import_add_list';
    /** The sheet that was just set to "not imported" and what it was before ({sheet, kind}): "Put back" in the same bar */
    public const string FLASH_SHEET = 'import_add_list_sheet';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private GetImportSheetScreen $getImportSheetScreen,
        readonly private ImportSheetScreen $sheetScreen,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/add/list',
        name: 'data_import_add_list_change',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);
        $playerId = $player->playerId;

        // Whose import it is: a 404 for anybody else's, before anything is looked at
        $this->getDataImports->byIdOfPlayer($importId, $playerId);

        $form = $request->request;
        $filters = ImportAddListController::filters($form->all());
        $action = $form->getString('action');
        $rowId = $form->getString('row');

        if ($action === 'leave_out_sheet' || $action === 'put_back_sheet') {
            // `sheet` names the sheet that goes - not a filter of the list to come back to
            unset($filters['sheet']);
        }

        $back = $this->redirectToRoute(
            'data_import_add_list',
            ['importId' => $importId, ...$filters, 'page' => max(1, $form->getInt('page', 1)), '_fragment' => Uuid::isValid($rowId) ? 'row-' . strtolower($rowId) : null],
            Response::HTTP_SEE_OTHER,
        );

        try {
            $flash = match ($action) {
                'leave_out', 'put_back' => $this->row($importId, $playerId, $rowId, $action === 'leave_out'),
                'leave_out_puzzle' => $this->puzzle($importId, $playerId, $form->getString('puzzle')),
                'leave_out_sheet' => $this->sheet($importId, $playerId, $form->getString('sheet'), $form->getInt('version')),
                'put_back_sheet' => $this->sheetBack($importId, $playerId, $form->getString('sheet'), $form->getString('kind')),
                default => null,
            };
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof DataImportNotFound) {
                throw $cause;
            }

            if ($cause instanceof ImportRowsChangedMeanwhile || $cause instanceof ImportRowAnswerRefused || $cause instanceof ImportMappingChangedMeanwhile || $cause instanceof InvalidImportMapping) {
                $this->addFlash(self::FLASH, $this->translator->trans('data_import.add_list.flash.changed_meanwhile'));

                return $back;
            }

            throw $exception;
        }

        if ($flash === null) {
            $this->addFlash(self::FLASH, $this->translator->trans('data_import.add_list.flash.changed_meanwhile'));
        } else {
            $this->addFlash(self::FLASH, $flash);
        }

        return $back;
    }

    private function row(string $importId, string $playerId, string $rowId, bool $leaveOut): null|string
    {
        if (Uuid::isValid($rowId) === false) {
            return null;
        }

        $this->messageBus->dispatch(new AnswerImportRows($importId, $playerId, [$rowId], leaveOut: $leaveOut));

        return $this->translator->trans($leaveOut ? 'data_import.add_list.flash.left_out' : 'data_import.add_list.flash.put_back');
    }

    /**
     * Every row of the puzzle an "Add" would take now - the rows the list showed under the search.
     */
    private function puzzle(string $importId, string $playerId, string $importPuzzleId): null|string
    {
        $rowIds = $this->getImportAddScreen->readyRowIdsOfPuzzle($importId, $playerId, $importPuzzleId, ImportRowAnswers::MAX_ROWS);

        if ($rowIds === []) {
            return null;
        }

        $changed = $this->messageBus->dispatch(new AnswerImportRows($importId, $playerId, $rowIds, leaveOut: true))->last(HandledStamp::class)?->getResult();
        $changed = is_int($changed) ? $changed : count($rowIds);

        return $this->translator->trans('data_import.add_list.flash.left_out_puzzle', ['%count%' => $changed, '%number%' => $changed]);
    }

    /**
     * The sheet is "not puzzles" - the one-tap change of "How we read your file", with the version the list showed.
     */
    private function sheet(string $importId, string $playerId, string $sheetId, int $version): null|string
    {
        if (Uuid::isValid($sheetId) === false) {
            return null;
        }

        $sheet = $this->getImportSheetScreen->sheet($importId, $sheetId, $playerId);
        /** @var InputBag<string> $form the one field the one-tap change of a sheet's kind sends */
        $form = new InputBag(['kind' => DataImportSheetKind::NotPuzzles->value]);
        $submission = $this->sheetScreen->submission($sheet, $form);

        if ($sheet->beingSetUp === false || $submission['errors'] !== [] || $submission['mapping'] === null) {
            return null;
        }

        $this->messageBus->dispatch(new ConfirmImportMapping(
            importId: $importId,
            playerId: $playerId,
            sheetId: $sheetId,
            mappingVersion: $version,
            mapping: $submission['mapping'],
            answers: $submission['answers'],
            sheetWide: $submission['sheetWide'],
        ));

        if ($sheet->kind !== null && $sheet->kind !== DataImportSheetKind::NotPuzzles) {
            // What it was: "Put back" in the list's bar sets it to that again
            $this->addFlash(self::FLASH_SHEET, ['sheet' => $sheetId, 'kind' => $sheet->kind->value]);
        }

        // A file without sheets (a CSV) has one sheet without a name: "the whole file"
        return trim($sheet->title) === ''
            ? $this->translator->trans('data_import.add_list.flash.left_out_file')
            : $this->translator->trans('data_import.add_list.flash.left_out_sheet', ['%title%' => $sheet->title]);
    }

    /**
     * "Put back" for a sheet that was just set to "not imported": the same one-tap change, back to what the sheet
     * was (`kind` - one of the kinds a sheet of puzzles has), with the version the sheet has now. Its rows are read
     * again in the background and are in the list as soon as they are.
     */
    private function sheetBack(string $importId, string $playerId, string $sheetId, string $kind): null|string
    {
        $was = DataImportSheetKind::tryFrom($kind);

        if (Uuid::isValid($sheetId) === false || $was === null || $was === DataImportSheetKind::NotPuzzles) {
            return null;
        }

        $sheet = $this->getImportSheetScreen->sheet($importId, $sheetId, $playerId);

        if ($sheet->kind !== DataImportSheetKind::NotPuzzles) {
            return null;
        }

        /** @var InputBag<string> $form the one field the one-tap change of a sheet's kind sends */
        $form = new InputBag(['kind' => $was->value]);
        $submission = $this->sheetScreen->submission($sheet, $form);

        if ($sheet->beingSetUp === false || $submission['errors'] !== [] || $submission['mapping'] === null) {
            return null;
        }

        $this->messageBus->dispatch(new ConfirmImportMapping(
            importId: $importId,
            playerId: $playerId,
            sheetId: $sheetId,
            mappingVersion: $sheet->mappingVersion,
            mapping: $submission['mapping'],
            answers: $submission['answers'],
            sheetWide: $submission['sheetWide'],
        ));

        return $this->translator->trans('data_import.add_list.flash.put_back_sheet');
    }
}
