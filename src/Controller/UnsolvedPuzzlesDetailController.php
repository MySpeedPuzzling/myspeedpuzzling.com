<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UnsolvedPuzzlesDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/neposlkadane-puzzle/{playerId}',
            'en' => '/en/unsolved-puzzles/{playerId}',
            'es' => '/es/puzzles-sin-resolver/{playerId}',
            'ja' => '/ja/未解決パズル/{playerId}',
            'fr' => '/fr/puzzles-non-resolus/{playerId}',
            'de' => '/de/ungeloeste-puzzles/{playerId}',
        ],
        name: 'unsolved_puzzles_detail',
    )]
    public function __invoke(string $playerId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));
            return $this->redirectToRoute('ladder');
        }

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $visibility = $player->unsolvedPuzzlesVisibility;
        $collectionName = $this->translator->trans('unsolved_puzzles.name');

        // Check visibility permissions
        if ($visibility === CollectionVisibility::Private && $playerId !== $loggedPlayerProfile?->playerId) {
            return $this->render('unsolved_puzzles/private.html.twig', [
                'player' => $player,
                'collectionName' => $collectionName,
            ]);
        }

        $items = $this->getUnsolvedPuzzles->byPlayerId($player->playerId);

        $collectionOverview = new CollectionOverview(
            playerId: $playerId,
            collectionId: null,
            name: $collectionName,
            description: $this->translator->trans('unsolved_puzzles.description'),
            visibility: $visibility,
        );

        return $this->render('unsolved_puzzles/detail.html.twig', [
            'collection' => $collectionOverview,
            'items' => $items,
            'player' => $player,
        ]);
    }
}
