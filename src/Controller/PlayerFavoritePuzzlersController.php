<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlayerFavoritePuzzlersController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/oblibeni-hraci/{playerId}',
            'en' => '/en/player-favorites/{playerId}',
        ],
        name: 'player_favorite_puzzlers',
    )]
    public function __invoke(string $playerId, #[CurrentUser] UserInterface|null $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('ladder');
        }

        return $this->render('player_favorite_puzzlers.html.twig', [
            'player' => $player,
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
        ]);
    }
}
