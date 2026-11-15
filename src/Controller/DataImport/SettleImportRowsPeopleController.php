<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AddImportRows;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\PuzzlingType;
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
 * One answer for all rows that could not be added because they say pair or team and name nobody
 * (docs/features/data-import/adding-screens.md "Who did you solve these with?") - an export that marks pair / team
 * by a tag leaves dozens of them. Either the names (guests, separated by commas: one name makes the rows pair
 * results, more make them team results) or "they were solo" - written as the rows' own answers through the pairing
 * screens' message (AnswerImportRows: "solved with" and "solo / pair / team"), then those rows are added
 * (AddImportRows by their ids). POST + CSRF + the form's `request_id`; always a redirect.
 *
 * Which rows: every row of the import (of the "Add" named by `batch`) that waits with that very reason - worked
 * out here, never sent by the form. Another player's import is a 404.
 */
final class SettleImportRowsPeopleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/not-added/people',
        name: 'data_import_not_added_people',
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
        $requestId = $form->getString('request_id');
        $back = $this->redirectToRoute('data_import_not_added', array_filter(['importId' => $importId, 'batch' => $batchId]));

        // Another player's import is "not found" before anything else is looked at
        $this->getImportAddScreen->summary($importId, $player->playerId);

        if ($form->getString('action') === 'solo') {
            $answers = [ImportField::SolvingType->value => PuzzlingType::Solo->value];
        } else {
            $names = array_values(array_filter(array_map(trim(...), preg_split('/[,;\n]/u', $form->getString('names')) ?: []), static fn (string $name): bool => $name !== ''));

            if ($names === []) {
                $this->addFlash('warning', $this->translator->trans('data_import.not_added.people.no_names'));

                return $back;
            }

            $answers = [
                ImportField::SolvedWith->value => implode(', ', $names),
                ImportField::SolvingType->value => (count($names) === 1 ? PuzzlingType::Duo : PuzzlingType::Team)->value,
            ];
        }

        $rowIds = $this->getImportAddScreen->rowIdsNotAddedFor($importId, $player->playerId, ImportRowRefusal::PeopleDoNotFitType->value, $batchId, ImportRowAnswers::MAX_ROWS);

        if (Uuid::isValid($requestId) === false) {
            $this->addFlash('info', $this->translator->trans('data_import.add.flash.nothing_ready'));

            return $back;
        }

        try {
            // No row waits with that reason any more: most often this very form, sent twice - the "Add" below then
            // answers the same batch, and "nothing is ready" otherwise
            if ($rowIds !== []) {
                $this->messageBus->dispatch(new AnswerImportRows($importId, $player->playerId, $rowIds, $answers));
            }
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof ImportRowsChangedMeanwhile || $cause instanceof ImportRowAnswerRefused) {
                $this->addFlash('warning', $this->translator->trans('data_import.add.flash.changed_meanwhile'));

                return $back;
            }

            throw $exception;
        }

        $outcome = $this->messageBus->dispatch(new AddImportRows($requestId, $importId, $player->playerId, $rowIds))
            ->last(HandledStamp::class)
            ?->getResult();
        assert($outcome instanceof AddImportRowsOutcome);

        if ($outcome->isStarted() && $outcome->id !== null) {
            return $this->redirectToRoute('data_import_batch', ['importId' => $importId, 'batchId' => $outcome->id]);
        }

        // The answer is saved; the rows wait (adding is paused, another "Add" runs): said, and they can be added later
        $this->addFlash('info', $this->translator->trans(match (true) {
            $outcome->status === AddImportRowsOutcome::PAUSED => 'data_import.add.flash.paused',
            $rowIds === [] => 'data_import.add.flash.nothing_ready',
            default => 'data_import.not_added.people.saved_not_added',
        }));

        return $back;
    }
}
