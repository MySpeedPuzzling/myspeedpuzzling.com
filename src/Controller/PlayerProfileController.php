<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\HasExistingConversation;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlayerProfileController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetRanking $getRanking,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private TranslatorInterface $translator,
        readonly private GetTags $getTags,
        readonly private GetBadges $getBadges,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private HasExistingConversation $hasExistingConversation,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/profil-hrace/{playerId}',
            'en' => '/en/player-profile/{playerId}',
            'es' => '/es/perfil-jugador/{playerId}',
            'ja' => '/ja/プレイヤー-プロフィール/{playerId}',
            'fr' => '/fr/profil-joueur/{playerId}',
            'de' => '/de/spieler-profil/{playerId}',
        ],
        name: 'player_profile',
    )]
    public function __invoke(string $playerId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('ladder');
        }

        $canMessage = false;
        $loggedProfile = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedProfile !== null && $loggedProfile->playerId !== $player->playerId) {
            $canMessage = $player->allowDirectMessages
                || $this->hasExistingConversation->acceptedBetween($loggedProfile->playerId, $player->playerId);
        }

        return $this->render('player_profile.html.twig', [
            'player' => $player,
            'ranking' => $this->getRanking->allForPlayer($player->playerId),
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
            'tags' => $this->getTags->allGroupedPerPuzzle(),
            'badges' => $this->getBadges->forPlayer($player->playerId),
            'can_message' => $canMessage,
        ]);
    }
}
