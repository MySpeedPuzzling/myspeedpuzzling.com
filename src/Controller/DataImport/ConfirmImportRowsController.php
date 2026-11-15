<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\ConfirmImportRows;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
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
 * The words of the review (docs/features/data-import/adding-screens.md "The words before adding"). POST + CSRF,
 * always a redirect back to the review:
 *
 * - `right`: "It is right" for unusual times (ConfirmImportRows: `answers.addAnyway` gets `unusual_time`);
 * - `leave_out`: the rows are left out (AnswerImportRows - the pairing screens' own "leave out");
 * - `separate` / `one`: the rows that are the same result as `group` count each on its own / are one result again.
 *
 * Nothing is added here. Rows that changed meanwhile (an "Add" took them, the sheet is being read again): nothing
 * is written, the review as it stands now with a plain notice. Another player's import is a 404 (the handlers
 * check it).
 */
final class ConfirmImportRowsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/add/confirm',
        name: 'data_import_add_confirm',
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

        $form = $request->request;
        $action = $form->getString('action');
        $rowIds = array_slice(array_values(array_filter($form->all('rows'), is_string(...))), 0, ImportRowAnswers::MAX_ROWS);
        $review = $this->redirectToRoute('data_import_add', ['importId' => $importId, '_fragment' => in_array($action, ['separate', 'one'], true) ? 'equal-rows' : 'unusual-times']);

        $message = match (true) {
            $action === 'right' && $rowIds !== [] => new ConfirmImportRows($importId, $player->playerId, $rowIds, [ImportRowRefusal::UnusualTime->value]),
            $action === 'leave_out' && $rowIds !== [] => new AnswerImportRows($importId, $player->playerId, $rowIds, leaveOut: true),
            $action === 'separate', $action === 'one' => new ConfirmImportRows($importId, $player->playerId, equalGroupOf: $form->getString('group'), separate: $action === 'separate'),
            default => null,
        };

        if ($message === null) {
            $this->addFlash('info', $this->translator->trans('data_import.add.flash.nothing_chosen'));

            return $review;
        }

        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof ImportRowsChangedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('data_import.add.flash.changed_meanwhile'));

                return $review;
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans(match ($action) {
            'right' => 'data_import.add.flash.confirmed',
            'leave_out' => 'data_import.add.flash.left_out',
            'separate' => 'data_import.add.flash.separate',
            default => 'data_import.add.flash.one',
        }));

        return $review;
    }
}
