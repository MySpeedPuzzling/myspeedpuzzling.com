<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportPersonChoiceRefused;
use SpeedPuzzling\Web\Message\DecideImportPerson;
use SpeedPuzzling\Web\Results\ImportPersonCard;
use SpeedPuzzling\Web\Services\DataImport\ImportInPlace;
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
 * To Turbo (and the import's own script) both are shown **in place** instead - the person's card as it stands after
 * the answer, see shown(); what is written is the same either way.
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
        $code = trim($request->request->getString('code'));

        // A code typed beside the answer that is selected by itself ("keep as a guest"): the player typed a code -
        // it is the code answer (taken, or refused with its notice), never silently a guest
        if ($code !== '' && in_array($answer, ['guest', ''], true)) {
            $answer = 'code';
        }

        $message = match (true) {
            // "Yes, still right" - what the player answered for the name in an earlier file; for one name, or all
            $answer === 'remembered' => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Guest, asRemembered: true),
            $answer === 'remembered_all' => new DecideImportPerson($importId, $player->playerId, '', ImportPersonChoice::Guest, allRemembered: true),
            $answer === 'guest' => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Guest),
            $answer === 'me' => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Me),
            $answer === 'code' => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Player, code: $code),
            default => new DecideImportPerson($importId, $player->playerId, $personId, ImportPersonChoice::Player, targetPlayerId: $answer),
        };

        $inPlace = ImportInPlace::asked($request);

        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if (!$cause instanceof ImportPersonChoiceRefused) {
                throw $exception;
            }

            // The code that could not be taken stays in its field, to be corrected
            $page = $this->peoplePage->page($importId, $player->playerId, $personId, $cause->reason) + ['refused_code' => $answer === 'code' ? mb_substr($code, 0, 9) : ''];

            if ($inPlace) {
                // Nothing was written: the person's card again, with why
                return $this->shown($request, $importId, $page, $personId, null);
            }

            return $this->render('data_import/people.html.twig', $page, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $saved = $this->translator->trans('data_import.people.flash.saved');

        if ($inPlace) {
            return $this->shown($request, $importId, $this->peoplePage->page($importId, $player->playerId), $answer === 'remembered_all' ? null : $personId, $saved);
        }

        $this->addFlash('success', $saved);

        return $this->redirectToRoute('data_import_people', ['importId' => $importId, '_fragment' => preg_match('/^' . Requirement::UUID . '$/i', $personId) === 1 ? 'person-' . strtolower($personId) : null]);
    }

    /**
     * The answer, shown in place (docs/features/data-import/people.md "Answers in place") - Turbo Streams: the card
     * of the person as it stands now (who they are - or why the answer could not be taken), or every card for "Yes,
     * still right - for all of them"; and "Saved" in the line that stays in view. Read with the page's own queries,
     * after the answer was written.
     *
     * @param array<string, mixed> $page what ImportPeopleController::page() gives
     * @param null|string $personId the person the answer was about - null: all of them
     */
    private function shown(Request $request, string $importId, array $page, null|string $personId, null|string $saved): Response
    {
        $person = null;

        if ($personId !== null) {
            $people = $page['people'];
            assert(is_array($people));

            foreach ($people as $card) {
                if ($card instanceof ImportPersonCard && strtolower($card->personId) === strtolower($personId)) {
                    $person = $card;
                }
            }
        }

        ImportInPlace::answerFor($request, 'data_import_people', $this->generateUrl('data_import_people', ['importId' => $importId]));

        return $this->render('data_import/_people_answer.stream.html.twig', $page + [
            // Null: every card (an answer for all of them - or for a person the page no longer has)
            'person' => $person,
            'said' => $saved === null ? null : ['type' => 'success', 'text' => $saved, 'import_id' => $importId],
        ]);
    }
}
