<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerCard;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The player card on the Players page (docs/features/players-page/README.md, stream S1): fetched into
 * <turbo-frame id="player-card"> when a person is tapped (players_card_controller.js), a minimal noindex page
 * otherwise. Two statements: the profile (GetPlayerProfile::byId() 404s hidden players) and the card's numbers.
 *
 * A card never shows a private player - only to themselves. Favorite and Compare stay inside the frame: both come
 * back here through their `return`, so the card shows the new state and the flash.
 */
final class PlayerCardController extends AbstractController
{
    public const string FRAME_ID = 'player-card';

    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerCard $getPlayerCard,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/karta-puzzlera/{playerId}',
            'en' => '/en/puzzler-card/{playerId}',
            'es' => '/es/tarjeta-jugador/{playerId}',
            'ja' => '/ja/プレイヤーカード/{playerId}',
            'fr' => '/fr/carte-joueur/{playerId}',
            'de' => '/de/puzzler-karte/{playerId}',
        ],
        name: 'player_card',
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $playerId): Response
    {
        $inFrame = $request->headers->get('Turbo-Frame') === self::FRAME_ID;
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        $viewerIsMember = $viewer?->activeMembership === true;

        try {
            $player = $this->getPlayerProfile->byId($playerId);

            if ($player->isPrivate && $player->playerId !== $viewer?->playerId) {
                throw new PlayerNotFound();
            }

            $card = $this->getPlayerCard->byPlayerId($player->playerId, withActivity: $viewerIsMember);
        } catch (PlayerNotFound $exception) {
            if ($inFrame === false) {
                throw $exception;
            }

            // The same 404 for a hidden, private or deleted player - inside the frame, so the card says it neutrally
            $response = $this->render('players/_card_unavailable.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
            $response->setVary('Turbo-Frame', false);

            return $response;
        }

        $isOwnCard = $viewer !== null && $viewer->playerId === $player->playerId;
        $comparisonRef = ComparisonSubjectRef::player($player->playerId);

        // What Favorite and Compare offer: decided from the viewer's own profile row - no query
        $favorite = match (true) {
            $isOwnCard => null,
            $viewer === null => 'sign_in',
            in_array($player->playerId, $viewer->favoritePlayers, true) => 'on',
            default => 'off',
        };
        $compare = match (true) {
            $isOwnCard => null,
            $viewer === null => 'sign_in',
            $viewer->comparisonLineUp->contains($comparisonRef) => 'open',
            default => 'add',
        };

        $response = $this->render($inFrame ? 'players/_card_frame.html.twig' : 'players/card.html.twig', [
            'player' => $player,
            'card' => $card,
            'viewer_is_member' => $viewerIsMember,
            'favorite' => $favorite,
            'compare' => $compare,
            'comparison_ref' => $comparisonRef->toString(),
            'activity' => $viewerIsMember ? $card->activity($this->clock->now()) : [],
        ]);

        // Same URL, two bodies: a cache must never hand the frame to a full-page visit or the other way round
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
