<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportRowsToFix;
use SpeedPuzzling\Web\Results\ImportRowDetail;
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
 * become the row's answers, an emptied field takes its answer back, "Leave this row out" / "Take it in again" - the
 * same message as "To fix" (AnswerImportRows), with the stamp the page showed. POST + CSRF.
 *
 * Saved: back to the row (PRG). A value that can not be read for sure: the same page with a 422, the reason at its
 * field and everything typed kept. Changed meanwhile: the row as it stands, with a notice. Another player's import,
 * or a row of another import, is a 404.
 */
final class SaveImportRowController extends AbstractController
{
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

        $detail = $this->getImportRowsToFix->row($importId, $player->playerId, $rowId);
        $back = $this->redirectToRoute('data_import_row', ['importId' => $importId, 'rowId' => $rowId]);

        if ($detail->beingSetUp === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $form = $request->request;
        $action = $form->getString('action', 'save');
        $submitted = $form->all('answers');
        $stamp = $form->getString('stamp');

        $message = match ($action) {
            'leave_out' => new AnswerImportRows($importId, $player->playerId, [$rowId], leaveOut: true, seenStamp: $stamp),
            'take_in' => new AnswerImportRows($importId, $player->playerId, [$rowId], leaveOut: false, seenStamp: $stamp),
            default => new AnswerImportRows($importId, $player->playerId, [$rowId], ImportRowSheet::changed($detail, $submitted), seenStamp: $stamp),
        };

        if ($message->answers === [] && $message->leaveOut === null) {
            $this->addFlash('info', $this->translator->trans('data_import.row.flash.nothing_changed'));

            return $back;
        }

        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof ImportRowAnswerRefused) {
                return $this->refused($importId, $player->playerId, $detail, $submitted, $cause);
            }

            if ($cause instanceof ImportRowsChangedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('data_import.row.flash.changed_meanwhile'));

                return $back;
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans(match ($action) {
            'leave_out' => 'data_import.row.flash.left_out',
            'take_in' => 'data_import.row.flash.taken_in',
            default => 'data_import.row.flash.saved',
        }));

        return $back;
    }

    /**
     * @param array<array-key, mixed> $submitted
     */
    private function refused(string $importId, string $playerId, ImportRowDetail $detail, array $submitted, ImportRowAnswerRefused $refusal): Response
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
            ],
            new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }
}
