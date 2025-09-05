<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlayerCollectionsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/kolekce-hrace/{playerId}',
            'en' => '/en/player-collections/{playerId}',
            'es' => '/es/colecciones-jugador/{playerId}',
            'ja' => '/ja/プレイヤーのコレクション/{playerId}',
            'fr' => '/fr/collections-joueur/{playerId}',
            'de' => '/de/spieler-sammlungen/{playerId}',
        ],
        name: 'player_collections',
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
        $isOwnProfile = $loggedPlayerProfile !== null && $loggedPlayerProfile->playerId === $player->playerId;

        $collections = $this->getPlayerCollections->byPlayerId($player->playerId, $isOwnProfile);

        return $this->render('collections/list.html.twig', [
            'collections' => $collections,
            'player' => $player,
            'isOwnProfile' => $isOwnProfile,
        ]);
    }
}
