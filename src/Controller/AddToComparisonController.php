<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetComparisonPlayers;
use SpeedPuzzling\Web\Services\ComparisonBucket;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddToComparisonController extends AbstractController
{
    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly ComparisonBucket $comparisonBucket,
        private readonly GetComparisonPlayers $getComparisonPlayers,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/porovnani-puzzleru/pridat/{playerId}/',
            'en' => '/compare-puzzlers/add/{playerId}/',
            'es' => '/es/comparar-puzzleros/anadir/{playerId}/',
            'ja' => '/ja/hikaku-puzzleru/add/{playerId}/',
            'fr' => '/fr/comparer-puzzleurs/ajouter/{playerId}/',
            'de' => '/de/puzzler-vergleich/hinzufuegen/{playerId}/',
        ],
        name: 'comparison_add',
    )]
    public function __invoke(string $playerId): Response
    {
        $self = $this->retrieveLoggedUserProfile->getProfile();

        if ($self !== null && $this->comparisonBucket->isEmpty()) {
            $this->comparisonBucket->addPlayer($self->playerId);
        }

        $player = $this->getComparisonPlayers->byIds([$playerId])[$playerId] ?? null;

        if ($player !== null && ($player->isPrivate === false || $player->playerId === $self?->playerId)) {
            $this->comparisonBucket->addPlayer($playerId);
        }

        return $this->redirectToRoute('comparison');
    }
}
