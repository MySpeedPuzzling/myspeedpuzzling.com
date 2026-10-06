<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyKnown;
use SpeedPuzzling\Web\FormData\SuggestPuzzleNameFormData;
use SpeedPuzzling\Web\FormType\SuggestPuzzleNameFormType;
use SpeedPuzzling\Web\Message\SuggestPuzzleName;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\PuzzleNameSuggestions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * "Suggest another name" from the puzzle page's menu (docs/features/puzzle-names/README.md): one name and its language,
 * in the modal frame (a full page without it). A player's name becomes a change request - at most 10 a day
 * (`puzzle_name_suggestion` limiter); a moderator's or an admin's is saved at once and the page refreshes. Closed to
 * players while the kill switch PUZZLE_NAME_SUGGESTIONS_PUBLIC is off (PuzzleNameSuggestions).
 */
final class SuggestPuzzleNameController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleOverview $getPuzzleOverview,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly PuzzleNameSuggestions $puzzleNameSuggestions,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $puzzleNameSuggestionLimiter,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}/navrhnout-nazev',
            'en' => '/en/puzzle/{puzzleId}/suggest-name',
            'es' => '/es/puzzle/{puzzleId}/sugerir-nombre',
            'ja' => '/ja/puzzle/{puzzleId}/suggest-name',
            'fr' => '/fr/puzzle/{puzzleId}/suggerer-nom',
            'de' => '/de/puzzle/{puzzleId}/namen-vorschlagen',
        ],
        name: 'puzzle_suggest_name',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        // A puzzle a competition keeps secret answers 404 to everybody but its organisers (SecretPuzzleAccess)
        $this->secretPuzzleAccess->assertVisible($puzzleId, alsoWhileImageHidden: true);

        if ($this->puzzleNameSuggestions->isOpen() === false) {
            throw $this->createNotFoundException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();
        $puzzle = $this->getPuzzleOverview->byId($puzzleId);
        $moderator = $this->isGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS);
        $inModal = $request->headers->get('Turbo-Frame') === 'modal-frame';

        $form = $this->createForm(SuggestPuzzleNameFormType::class, SuggestPuzzleNameFormData::forPageLocale($request->getLocale()), [
            'moderator' => $moderator,
            'action' => $this->generateUrl('puzzle_suggest_name', ['puzzleId' => $puzzle->puzzleId]),
        ]);
        $form->handleRequest($request);

        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null);

            // Moderators and admins add names at once, like on the edit page - only the queue is protected
            if ($moderator === false && $this->puzzleNameSuggestionLimiter->create($player->playerId)->consume()->isAccepted() === false) {
                $form->addError(new FormError($this->translator->trans('puzzle_names.suggest_name.too_many')));
                $status = Response::HTTP_TOO_MANY_REQUESTS;
            } else {
                try {
                    $this->messageBus->dispatch(new SuggestPuzzleName(
                        suggestionId: Uuid::uuid7()->toString(),
                        puzzleId: $puzzle->puzzleId,
                        playerId: $player->playerId,
                        name: $data->name,
                        language: $data->language,
                        makeMainTitle: $moderator && $data->makeMainTitle,
                    ));

                    return $this->succeeded($request, $puzzle->puzzleId, $moderator, $inModal);
                } catch (PuzzleIsStillSecret) {
                    // A secret competition puzzle gets no name suggestions before its reveal
                    $form->addError(new FormError($this->translator->trans('competition.reveal.puzzle_still_secret')));
                } catch (PuzzleNameAlreadyKnown) {
                    $form->get('name')->addError(new FormError($this->translator->trans('puzzle_names.suggest_name.already_known')));
                } catch (InvalidPuzzleValues) {
                    // The one value a valid form can still break: the number of other names
                    $form->addError(new FormError($this->translator->trans(
                        'puzzle_names.too_many_names',
                        ['%limit%' => PuzzleNames::FORM_MAX_NAMES],
                        'validators',
                    )));
                }
            }
        }

        return $this->render($inModal ? 'puzzle/suggest_name_modal.html.twig' : 'puzzle/suggest_name.html.twig', [
            'puzzle' => $puzzle,
            'form' => $form,
            'moderator' => $moderator,
            // What the puzzle is known as already, so nobody suggests it again
            'known_names' => array_map(
                static fn (PuzzleName $name): array => [
                    'name' => $name->name,
                    'language' => $name->language,
                    'languageLabel' => $name->language !== null ? PuzzleNameLanguageChoices::label($name->language, $request->getLocale()) : null,
                ],
                $puzzle->puzzleAlternativeNames->all(),
            ),
        ], new Response(status: $status));
    }

    private function succeeded(Request $request, string $puzzleId, bool $moderator, bool $inModal): Response
    {
        $message = $this->translator->trans($moderator ? 'puzzle_names.suggest_name.added' : 'puzzle_names.suggest_name.submitted');

        if ($inModal && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            // A moderator's name is on the puzzle now - the page refreshes and shows it, with the message as its flash
            if ($moderator) {
                $this->addFlash('success', $message);
            }

            return $this->render('puzzle/suggest_name_success_stream.html.twig', [
                'message' => $moderator ? null : $message,
            ]);
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
