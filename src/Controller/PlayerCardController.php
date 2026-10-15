<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerCard;
use SpeedPuzzling\Web\Query\GetPlayerCardContext;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\PlayerActionsResolver;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PlayerCardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The player card (docs/features/navigation README §4.8, CARD-*; first built for the Players page,
 * docs/features/players-page/README.md): fetched into <turbo-frame id="player-card"> when a player name is tapped
 * (assets/player_card.js → assets/player_card_layer.js), a minimal noindex page otherwise. Two statements: the profile
 * (GetPlayerProfile::byId() 404s hidden players) and the card incl. its latest puzzles - plus one for the context block
 * of the page it was opened from (`?context=`, GetPlayerCardContext, CARD-02) and one only when the player switched direct
 * messages off (PlayerActionsResolver, the decisions shared with the player header).
 *
 * A card never shows a private player - only to themselves and their allow list. Favorite and Compare stay inside the
 * frame: both come back here through their `return` (the card's URL with its `context`), so the card shows the new
 * state and the flash. A guest's sign-in links carry the profile as `return`; the layer points them at the page the
 * card was opened on (data-card-return-page) - the guest card stays the same for everybody (shared cache).
 */
final class PlayerCardController extends AbstractController
{
    public const string FRAME_ID = 'player-card';

    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerCard $getPlayerCard,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerActionsResolver $playerActionsResolver,
        readonly private GetPlayerCardContext $getPlayerCardContext,
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

        // The context block (CARD-02) - only after the gate above, and never a status of its own: a context that does
        // not apply is no block. Read raw: get() throws (400) on an array (?context[]=…), which is no context too
        // (QA-04 R12) - tryFrom() takes strings only
        $context = PlayerCardContext::tryFrom($request->query->all()['context'] ?? null);

        $response = $this->render($inFrame ? 'players/_card_frame.html.twig' : 'players/card.html.twig', [
            'player' => $player,
            'card' => $card,
            'actions' => $this->playerActionsResolver->resolve($player, $viewer),
            'context' => $context,
            'context_block' => $context !== null ? $this->getPlayerCardContext->forCard($context, $player, $viewer) : null,
            'viewer_is_member' => $viewerIsMember,
            'activity' => $viewerIsMember ? $card->activity($this->clock->now()) : [],
        ]);

        // Same URL, two bodies: a cache must never hand the frame to a full-page visit or the other way round
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
