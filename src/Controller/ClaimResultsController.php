<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\ClaimRoundResults;
use SpeedPuzzling\Web\Query\GetClaimableResultsForPlayer;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ClaimResultsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetClaimableResultsForPlayer $getClaimableResults,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prevzit-vysledky/{competitionId}',
            'en' => '/en/claim-results/{competitionId}',
            'es' => '/es/claim-results/{competitionId}',
            'ja' => '/ja/claim-results/{competitionId}',
            'fr' => '/fr/claim-results/{competitionId}',
            'de' => '/de/claim-results/{competitionId}',
        ],
        name: 'claim_results',
    )]
    public function __invoke(string $competitionId, Request $request): Response
    {
        $competition = $this->getCompetitionEvents->byId($competitionId);
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        if ($request->isMethod('POST')) {
            /** @var array<string> $resultIds */
            $resultIds = $request->request->all('result_ids');

            if ($resultIds !== []) {
                $this->messageBus->dispatch(new ClaimRoundResults(
                    playerId: $profile->playerId,
                    resultIds: array_values($resultIds),
                ));

                $this->addFlash('success', $this->translator->trans('flashes.results_claimed'));
            }

            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        $claimable = $this->getClaimableResults->inCompetition($competitionId, $profile->playerId);

        if ($claimable === []) {
            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        return $this->render('claim_results.html.twig', [
            'competition' => $competition,
            'claimable' => $claimable,
        ]);
    }
}
