<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\MspRating\RatingStoryBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A player's MSP Rating card (docs/features/msp-rating-history.md): tapped on a ladder row, it opens in the puzzler
 * card's popover / bottom sheet (players_card_controller.js, <turbo-frame id="player-card">); opened directly it is a
 * minimal noindex page; the profile loads the same story lazily into its own frame (PROFILE_FRAME_ID). Three
 * statements: the profile (place + the compared day's place), the rating, the stored days.
 *
 * The ladder is a global ranking - closed to private players for everybody, the allow list included - so the card
 * exists only for a player on it; anybody else gets the neutral "not available" (blocked, private, opted out, unrated).
 */
final class MspRatingCardController extends AbstractController
{
    public const string FRAME_ID = PlayerCardController::FRAME_ID;

    // The profile's rating block loads the same story lazily into this frame (player_profile.html.twig)
    public const string PROFILE_FRAME_ID = 'msp-rating-history';

    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private RatingStoryBuilder $ratingStoryBuilder,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/msp-rating/karta/{playerId}',
            'en' => '/en/msp-rating/card/{playerId}',
            'es' => '/es/msp-rating/tarjeta/{playerId}',
            'ja' => '/ja/msp-rating/card/{playerId}',
            'fr' => '/fr/msp-rating/carte/{playerId}',
            'de' => '/de/msp-rating/karte/{playerId}',
        ],
        name: 'msp_rating_card',
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $playerId): Response
    {
        $frame = $request->headers->get('Turbo-Frame');
        $inProfile = $frame === self::PROFILE_FRAME_ID;
        $inFrame = $frame === self::FRAME_ID || $inProfile;

        try {
            $player = $this->getPlayerProfile->byId($playerId);
            $story = $this->ratingStoryBuilder->forPlayer($player);

            if ($story === null) {
                throw new PlayerNotFound();
            }
        } catch (PlayerNotFound $exception) {
            if ($inFrame === false) {
                throw $exception;
            }

            // The same 404 for every reason - inside the frame, so the card says it neutrally
            $response = $inProfile
                ? new Response('<turbo-frame id="' . self::PROFILE_FRAME_ID . '"></turbo-frame>', Response::HTTP_NOT_FOUND)
                : $this->render('players/_card_unavailable.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
            $response->setVary('Turbo-Frame', false);

            return $response;
        }

        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        $isOwnCard = $viewer !== null && $viewer->playerId === $player->playerId;
        $comparisonRef = ComparisonSubjectRef::player($player->playerId);

        // What Compare offers, from the viewer's own profile row - no query
        $compare = match (true) {
            $isOwnCard => null,
            $viewer === null => 'sign_in',
            $viewer->comparisonLineUp->contains($comparisonRef) => 'open',
            default => 'add',
        };

        $template = match (true) {
            $inProfile => 'msp_rating/_profile_frame.html.twig',
            $inFrame => 'msp_rating/_card_frame.html.twig',
            default => 'msp_rating/card.html.twig',
        };

        $response = $this->render($template, [
            'player' => $player,
            'story' => $story,
            'compare' => $compare,
            'comparison_ref' => $comparisonRef->toString(),
        ]);

        // Same URL, two bodies: a cache must never hand the frame to a full-page visit or the other way round
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
