<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Query\GetImportRowsToFix;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\DataImport\ImportToFixPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * One answer for every row of a group of "To fix" (docs/features/data-import/pairing-screens.md "To fix"): the value
 * typed for the group's field, or "Leave these rows out" - and a row the player left out taken in again. One
 * message (AnswerImportRows). POST + CSRF. The group is named by its key, never by its value.
 *
 * Saved: back to the list (PRG). A value that can not be read for sure: the same page with a 422, the reason at the
 * group's field and what was typed kept. Changed meanwhile (the sheet is read again, another tab): the list as it
 * stands, with a notice. Another player's import is a 404.
 */
final class AnswerImportRowsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportRowsToFix $getImportRowsToFix,
        readonly private ImportToFixPage $page,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/to-fix',
        name: 'data_import_rows_answer',
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
        $pageNumber = max(1, $form->getInt('page', 1));
        $back = $this->redirectToRoute('data_import_to_fix', ['importId' => $importId, 'page' => $pageNumber]);
        $action = $form->getString('action');
        $key = $form->getString('group');
        $typed = trim(mb_substr($form->getString('answer'), 0, 500));

        if ($action === 'take_in') {
            $message = new AnswerImportRows($importId, $player->playerId, [$form->getString('row')], leaveOut: false);
            $group = null;
        } else {
            // The group as it stands now - another player's import finds none (and the handler checks it again)
            $group = $this->getImportRowsToFix->groups($importId, $player->playerId, 1, 0, $key)['groups'][0] ?? null;

            if ($group === null || ($action !== 'answer' && $action !== 'leave_out')) {
                // Checks whose import it is: a 404 for anybody else's
                $this->page->parameters($importId, $player->playerId, $pageNumber);
                $this->addFlash('warning', $this->translator->trans('data_import.to_fix.flash.changed_meanwhile'));

                return $back;
            }

            if ($action === 'answer' && ($typed === '' || $group->input === null)) {
                return $this->refused($importId, $player->playerId, $pageNumber, $key, 'empty', $typed);
            }

            $rowIds = $this->getImportRowsToFix->rowIdsOfGroup($importId, $player->playerId, $key, ImportRowAnswers::MAX_ROWS);
            $message = $action === 'answer'
                ? new AnswerImportRows($importId, $player->playerId, $rowIds, [$group->field => $typed], onlyWithProblem: $key)
                : new AnswerImportRows($importId, $player->playerId, $rowIds, leaveOut: true, onlyWithProblem: $key);
        }

        try {
            $changed = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof ImportRowAnswerRefused) {
                return $this->refused($importId, $player->playerId, $pageNumber, $key, $cause->reason === ImportRowAnswerRefused::UNREADABLE ? 'unreadable' : 'refused', $typed);
            }

            if ($cause instanceof ImportRowsChangedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('data_import.to_fix.flash.changed_meanwhile'));

                return $back;
            }

            throw $exception;
        }

        $changed = is_int($changed) ? $changed : 0;

        $this->addFlash('success', $this->translator->trans(match (true) {
            $action === 'take_in' => 'data_import.to_fix.flash.taken_in',
            $action === 'leave_out' => 'data_import.to_fix.flash.left_out',
            default => 'data_import.to_fix.flash.answered',
        }, ['%number%' => $changed]));

        if ($group !== null && $group->rows > $changed && $changed >= ImportRowAnswers::MAX_ROWS) {
            $this->addFlash('info', $this->translator->trans('data_import.to_fix.flash.more_remain', ['%number%' => $group->rows - $changed]));
        }

        return $back;
    }

    private function refused(string $importId, string $playerId, int $page, string $key, string $error, string $typed): Response
    {
        return $this->render(
            'data_import/to_fix.html.twig',
            $this->page->parameters($importId, $playerId, $page, [$key => $error], [$key => $typed]),
            new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }
}
