<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CustomFields;

use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
use SpeedPuzzling\Web\Message\SetSolvingTimeCustomValues;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Query\GetCustomValues;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Results\CustomFieldsForm;
use SpeedPuzzling\Web\Services\CustomFields\SubmittedCustomValues;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CustomValues;
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
 * The signed-in player's own values on one result - their own form, route and message, never the result's edit: an
 * edit of a pair's result tells the whole team and recalculates everybody's achievements, a private field is nobody
 * else's business (docs/features/custom-fields.md). Only whoever is on the result gets here, and only ever to their
 * own values - a pair partner opens the same URL and gets their own (empty) form.
 *
 * In the modal frame it answers with the frame; saved, it goes back to the result detail (in the frame: the detail
 * replaces the form in the modal).
 */
final class SolvingTimeCustomValuesController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'result_my_fields';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        readonly private GetCustomFields $getCustomFields,
        readonly private GetCustomValues $getCustomValues,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/custom-fields/result/{timeId}',
        name: 'result_my_fields',
        // Any uuid-shaped id, like the result detail: the fixture times are no valid RFC 4122 versions
        requirements: ['_locale' => CustomFieldsController::LOCALES, 'timeId' => Requirement::UID_RFC4122],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $timeId): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        assert($viewer !== null);

        $solvingTime = $this->puzzleSolvingTimeRepository->get($timeId);
        $timeId = $solvingTime->id->toString();

        // Whoever tracked the result or is a registered member of it. Anybody else: there is nothing here
        if (in_array($viewer->playerId, $solvingTime->memberPlayerIds(), true) === false) {
            throw $this->createNotFoundException();
        }

        $fields = $this->getCustomFields->usableOnResults($viewer->playerId);
        $stored = $this->getCustomValues->forViewer([$timeId], $viewer->playerId)[$timeId] ?? new CustomValues();

        $inputs = [];

        foreach ($fields as $field) {
            $inputs[$field->fieldId] = CustomValues::toInput($stored->of($field->fieldId));
        }

        $problems = [];
        $isModal = $request->headers->get('Turbo-Frame') === 'modal-frame';

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            // Only the fields the form shows - an input left empty takes the value away
            $inputs = array_intersect_key(SubmittedCustomValues::of($request), $inputs) + $inputs;

            try {
                if ($fields !== []) {
                    $this->messageBus->dispatch(new SetSolvingTimeCustomValues($timeId, $viewer->playerId, $inputs));
                }

                // In the modal the result detail that replaces the form shows the saved values - a flash would wait
                // for the next full page
                if ($isModal === false) {
                    $this->addFlash('success', $this->translator->trans('my_fields.values.saved'));
                }

                return $this->redirectToRoute('puzzle_result_detail', ['timeId' => $timeId], Response::HTTP_SEE_OTHER);
            } catch (HandlerFailedException $exception) {
                $refusal = $exception->getPrevious();

                if (!$refusal instanceof InvalidCustomValues) {
                    throw $exception;
                }

                $problems = $refusal->problems;
            }
        }

        $response = $this->render($isModal ? 'my_fields/_values_modal.html.twig' : 'my_fields/values.html.twig', [
            'time_id' => $timeId,
            'my_fields' => new CustomFieldsForm($fields, $inputs, $problems),
        ]);

        // A refused save is never a 200: Turbo Drive drops a 200 answer to a form submission
        if ($problems !== []) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Same URL, two bodies - and never anybody's but the viewer's
        $response->setVary('Turbo-Frame', false);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
