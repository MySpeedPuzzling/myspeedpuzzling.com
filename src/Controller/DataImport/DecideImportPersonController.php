<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportPersonChoiceRefused;
use SpeedPuzzling\Web\Message\DecideImportPerson;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
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
 * The importing player's answer about one person of their file (docs/features/data-import/people.md "Answers"):
 * POST + CSRF, one person per form. `answer` is `guest`, `me`, `code` (with the typed `code`) or the id of one of
 * the players offered - or `remembered` ("yes, still right": the answer of an earlier file for this name) and
 * `remembered_all` (for every such name of the import).
 *
 * - taken: a redirect back to the page, at the person;
 * - not taken (a code nobody has - or a player who may not be added: one message for both, a player who was not
 *   offered): nothing is written, the page again with a 422 and the notice at the person.
 *
 * Another player's import is a 404 (the handler checks it).
 */
final class DecideImportPersonController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ImportPeopleController $peoplePage,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/people',
        name: 'data_import_people_decide',
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

        $personId = $request->request->getString('person');
        $answer = $request->request->getString('answer');

        $message = match (true) {
            // "Yes, still right" - what the player answered for the name in an earlier file; for one name, or all
            $answer === 'remembered' => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Guest, asRemembered: true),
            $answer === 'remembered_all' => new DecideImportPerson($importId, $player->playerId, '', ImportPersonChoice::Guest, allRemembered: true),
            $answer === 'guest' => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Guest),
            $answer === 'me' => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Me),
            $answer === 'code' => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Player, code: $request->request->getString('code')),
            default => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Player, targetPlayerId: $answer),
        };

        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if (!$cause instanceof ImportPersonChoiceRefused) {
                throw $exception;
            }

            return $this->render(
                'data_import/people.html.twig',
                $this->peoplePage->page($importId, $player->playerId, $personId, $cause->reason),
                new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        $this->addFlash('success', $this->translator->trans('data_import.people.flash.saved'));

        return $this->redirectToRoute('data_import_people', ['importId' => $importId, '_fragment' => preg_match('/^' . Requirement::UUID . '$/i', $personId) === 1 ? 'person-' . strtolower($personId) : null]);
    }
}
