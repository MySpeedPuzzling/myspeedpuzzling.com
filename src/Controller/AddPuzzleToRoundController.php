<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\FormData\RoundPuzzleFormData;
use SpeedPuzzling\Web\FormType\RoundPuzzleFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
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
        $form = $this->createForm(RoundPuzzleFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->puzzle !== null);
            assert($data->brand !== null);

            try {
                $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
                    roundPuzzleId: Uuid::uuid7(),
                    roundId: $roundId,
                    userId: $user->getUserIdentifier(),
                    brand: $data->brand,
                    puzzle: $data->puzzle,
                    piecesCount: $data->piecesCount,
                    puzzlePhoto: $data->puzzlePhoto,
                    puzzleEan: $data->puzzleEan,
                    puzzleIdentificationNumber: $data->puzzleIdentificationNumber,
                    hideUntilRoundStarts: $data->hideUntilRoundStarts,
                    hideMode: $data->hideMode,
                ));
            } catch (HandlerFailedException $e) {
                $nested = $e->getPrevious() ?? $e;

                if (!$nested instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                    throw $e;
                }

                // A form error makes the form invalid, so render() answers 422 - Turbo Drive drops a 200
                $form->get('puzzle')->addError(new FormError($this->translator->trans(
                    'competition.round.form.puzzle_already_in_category',
                    ['%round%' => $nested->conflictingRoundName],
                )));

                return $this->render('add_puzzle_to_round.html.twig', [
                    'form' => $form,
                    'competition' => $competition,
                    'round' => $round,
                ]);
            }

            $this->addFlash('success', $this->translator->trans('competition.flash.puzzle_added'));

            return $this->redirectToRoute('manage_round_puzzles', ['roundId' => $roundId]);
        }

        return $this->render('add_puzzle_to_round.html.twig', [
            'form' => $form,
            'competition' => $competition,
            'round' => $round,
        ]);
    }
}
