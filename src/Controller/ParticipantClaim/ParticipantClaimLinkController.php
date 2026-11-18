<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantClaim;

use SpeedPuzzling\Web\Services\PreferredLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A personal claim link without a language (docs/features/competitions-management/participant-linking.md §6.5):
 * `/claim/{token}` - what the claim slips, the CSV and the copied links hand out, so the organiser's language never
 * becomes the person's. It sends the visitor to the claim page in the language of the signed-in player's profile, else
 * the browser's first one the site speaks, else English (PreferredLocale - like `compete_link`). Reads nothing of the
 * link. The token is in the address: never stored by a browser or a proxy, never passed on, never indexed, never logged
 * (the route name starts with `participant_claim` - OnlineTokenRedactor).
 */
final class ParticipantClaimLinkController extends AbstractController
{
    public function __construct(
        private readonly PreferredLocale $preferredLocale,
    ) {
    }

    #[Route(path: '/claim/{token}', name: 'participant_claim_link', methods: ['GET'])]
    public function __invoke(Request $request, string $token): Response
    {
        return ParticipantClaimController::withPrivateHeaders(
            $this->redirectToRoute('participant_claim', ['token' => $token, '_locale' => $this->preferredLocale->of($request)]),
        );
    }
}
