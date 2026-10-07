<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use Ramsey\Uuid\Uuid;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyPublic;
use SpeedPuzzling\Web\FormData\RoundPuzzleFormData;
use SpeedPuzzling\Web\FormType\RoundPuzzleFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddPuzzleToRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
        private readonly FormPhotoStash $formPhotoStash,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-puzzle-do-kola/{roundId}',
            'en' => '/en/add-puzzle-to-round/{roundId}',
            'es' => '/es/add-puzzle-to-round/{roundId}',
            'ja' => '/ja/add-puzzle-to-round/{roundId}',
            'fr' => '/fr/add-puzzle-to-round/{roundId}',
            'de' => '/de/add-puzzle-to-round/{roundId}',
        ],
        name: 'add_puzzle_to_round',
    )]
    public function __invoke(Request $request, string $roundId, #[CurrentUser] UserInterface $user): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        $formData = new RoundPuzzleFormData();
        $form = $this->createForm(RoundPuzzleFormType::class, $formData, [
            'competition_id' => $competitionId,
        ]);
        // The box photo of a refused submit comes back (FormPhotoStash) - organisers have a player profile
        $playerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;
        $restoredPhotos = $playerId !== null ? $this->formPhotoStash->restore($request, $form, $playerId) : [];
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->puzzle !== null);
            assert($data->brand !== null);

            // Another organiser's secret puzzle is not theirs to use (the picker never offers it)
            if (Uuid::isValid($data->puzzle)) {
                $this->secretPuzzleAccess->assertVisible($data->puzzle, alsoWhileImageHidden: true);
            }

            try {
                $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
                    roundPuzzleId: Uuid::uuid7(),
                    roundId: $roundId,
                    userId: $user->getUserIdentifier(),
                    brand: $data->brand,
                    puzzle: $data->puzzle,
                    piecesCount: $data->piecesCount,
                    puzzlePhoto: $data->puzzlePhoto,
                    eans: EanList::fromInputs($data->puzzleEans),
                    brandCodes: BrandCodeList::fromInputs($data->puzzleBrandCodes),
                    hideUntilRoundStarts: $data->hideUntilRoundStarts,
                    hideMode: $data->hideMode,
                ));
            } catch (PuzzleHiddenByHand | PuzzleNameAlreadyPublic $refusal) {
                // The handler cleared the entity manager (SecretPuzzleHides::lock()) - read the round again
                $round = $this->competitionRoundRepository->get($roundId);
                // A placeholder hidden by MySpeedPuzzling itself is no round's to hide or reveal; a name already out
                // stays out
                $form->get($refusal instanceof PuzzleNameAlreadyPublic ? 'hideMode' : 'puzzle')->addError(new FormError($this->translator->trans(
                    $refusal instanceof PuzzleNameAlreadyPublic ? 'competition.reveal.flash.name_already_public' : 'competition.reveal.flash.puzzle_hidden_by_hand',
                )));

                return $this->render('add_puzzle_to_round.html.twig', [
                    'form' => $form,
                    'competition' => $competition,
                    'round' => $round,
                    'revealed_right_away' => $round->automaticRevealAt() <= $this->clock->now(),
                    'kept_photos' => $playerId !== null ? $this->formPhotoStash->keep($form, $restoredPhotos, $playerId) : [],
                ]);
            } catch (HandlerFailedException $e) {
                $nested = $e->getPrevious() ?? $e;

                if (!$nested instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                    throw $e;
                }

                $round = $this->competitionRoundRepository->get($roundId);

                // A form error makes the form invalid, so render() answers 422 - Turbo Drive drops a 200
                $form->get('puzzle')->addError(new FormError($this->translator->trans(
                    'competition.round.form.puzzle_already_in_category',
                    ['%round%' => $nested->conflictingRoundName],
                )));

                return $this->render('add_puzzle_to_round.html.twig', [
                    'form' => $form,
                    'competition' => $competition,
                    'round' => $round,
                    'revealed_right_away' => $round->automaticRevealAt() <= $this->clock->now(),
                    'kept_photos' => $playerId !== null ? $this->formPhotoStash->keep($form, $restoredPhotos, $playerId) : [],
                ]);
            }

            if ($playerId !== null) {
                $this->formPhotoStash->forget($restoredPhotos, $playerId);
            }

            $this->addFlash('success', $this->translator->trans('competition.flash.puzzle_added'));

            return $this->redirectToRoute('manage_round_puzzles', ['roundId' => $roundId]);
        }

        return $this->render('add_puzzle_to_round.html.twig', [
            'form' => $form,
            'competition' => $competition,
            'round' => $round,
            // The round's automatic reveal (start + its reveal delay) is over: a secret puzzle added now with it is revealed
            // at once - the form says so
            'revealed_right_away' => $round->automaticRevealAt() <= $this->clock->now(),
            'kept_photos' => $playerId !== null ? $this->formPhotoStash->keep($form, $restoredPhotos, $playerId) : [],
        ]);
    }
}
