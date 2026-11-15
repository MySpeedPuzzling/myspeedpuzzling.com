<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AnswerImportRowsGroup;
use SpeedPuzzling\Web\Message\ProcessImportGroupAnswer;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One answer for a whole group of rows (docs/features/data-import/group-answers.md): "These times are right - add
 * all 416", "They are missing - add all 441", "Leave them out" - from the done page of an "Add", the list of rows
 * that could not be added, and (for unusual times, before any "Add") the review. POST + CSRF; the form names the
 * reason and the action, **never the rows** - the server works them out; its `request_id` makes the same form sent
 * again answer the same group answer. Always a redirect:
 *
 * - a small group is answered at once: on to the "Add" it started (the batch's page), else to its own page;
 * - a large one goes to the worker: its page follows it live, and leads on to the "Add" when it is through;
 * - nothing in the group (any more): back, with a line that says so.
 *
 * Another player's import is a 404 (the handler checks it).
 */
final class StartImportGroupAnswerController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/group-answers',
        name: 'data_import_group_answer_start',
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
        $batchId = Uuid::isValid($form->getString('batch')) ? strtolower($form->getString('batch')) : null;
        $beforeAdd = $form->getString('before_add') === '1';
        $back = $beforeAdd
            ? $this->redirectToRoute('data_import_add', ['importId' => $importId, '_fragment' => 'unusual-times'])
            : $this->redirectToRoute('data_import_not_added', array_filter(['importId' => $importId, 'batch' => $batchId]));

        $started = $this->messageBus->dispatch(new AnswerImportRowsGroup(
            $form->getString('request_id'),
            $importId,
            $player->playerId,
            $form->getString('code'),
            $form->getString('action'),
            $batchId,
            $beforeAdd,
        ))->last(HandledStamp::class)?->getResult();

        if (is_array($started) === false || is_string($started['id'] ?? null) === false) {
            $this->addFlash('info', $this->translator->trans('data_import.group.flash.nothing'));

            return $back;
        }

        $answerId = $started['id'];

        if (($started['started'] ?? false) === true) {
            // A small group at once, in this request; a large one on the worker
            $this->messageBus->dispatch(
                new ProcessImportGroupAnswer($answerId),
                ($started['atOnce'] ?? false) === true ? [new TransportNamesStamp(['sync'])] : [],
            );
        } else {
            // The same form again. An answer no turn has touched for a minute is sent on its way once more
            $answer = $this->getImportAddScreen->groupAnswer($importId, $player->playerId, $answerId);

            if ($answer['stale']) {
                $this->messageBus->dispatch(new ProcessImportGroupAnswer($answerId));
            }
        }

        return $this->redirectToRoute('data_import_group_answer', ['importId' => $importId, 'answerId' => $answerId]);
    }
}
