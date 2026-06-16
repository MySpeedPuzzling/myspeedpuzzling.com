<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ComparisonBucket;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ComparisonController extends AbstractController
{
    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly ComparisonBucket $comparisonBucket,
        private readonly PlayerRepository $playerRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/porovnani-puzzleru/',
            'en' => '/compare-puzzlers/',
            'es' => '/es/comparar-puzzleros/',
            'ja' => '/ja/hikaku-puzzleru/',
            'fr' => '/fr/comparer-puzzleurs/',
            'de' => '/de/puzzler-vergleich/',
        ],
        name: 'comparison',
    )]
    public function __invoke(Request $request): Response
    {
        $self = $this->retrieveLoggedUserProfile->getProfile();

        $playersParam = $request->query->get('players');

        if (is_string($playersParam) && $playersParam !== '') {
            $this->seedFromParam($playersParam, $self?->playerId);

            return $this->redirectToRoute('comparison');
        }

        if ($this->comparisonBucket->isEmpty() && $self !== null) {
            $this->comparisonBucket->addPlayer($self->playerId);
        }

        return $this->render('comparison.html.twig');
    }

    private function seedFromParam(string $playersParam, null|string $selfPlayerId): void
    {
        $this->comparisonBucket->clear();

        if ($selfPlayerId !== null) {
            $this->comparisonBucket->addPlayer($selfPlayerId);
        }

        foreach (explode(',', $playersParam) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            try {
                $player = $this->playerRepository->getByCode(ltrim($token, '#'));
                $this->comparisonBucket->addPlayer($player->id->toString());
            } catch (PlayerNotFound) {
                if (Uuid::isValid($token)) {
                    $this->comparisonBucket->addPlayer($token);
                }
            }
        }
    }
}
