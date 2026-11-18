<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantClaim;

use SpeedPuzzling\Web\Query\GetParticipantClaimPage;
use SpeedPuzzling\Web\Results\ParticipantClaimPage;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\ParticipantLinking\ClaimTokenMemo;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A personal claim link's page (docs/features/competitions-management/participant-linking.md §6.5): the organiser
 * handed this link to one person - "Jan Mikeš - is this you?". A GET never writes (mail scanners and prefetchers open
 * links); "Yes, link my profile" posts to participant_claim_confirm. One state per visit:
 * - `not_valid` (404): an unknown or cleared token, a removed participant, or an event not publicly visible to anybody
 *   off its team - one text for all, nothing named (DraftCanaryTest);
 * - `draft_team`: the event's team on its draft's link - it works once the event is published;
 * - `guest`: sign in or create an account and come back here (a validated `?return=`) - nothing says whether an account
 *   exists;
 * - `unlinked`, `already_yours`, `belongs_to_other` (the dispute is offered), `other_participant` (the switch).
 *
 * Limiter `participant_claim_page`: 30 per minute per IP, counted before anything is read; a token the database
 * does not know is remembered for a few seconds (ClaimTokenMemo) - asked again it costs no statement. `private, no-store`,
 * `Referrer-Policy: same-origin` (the token never leaves the site in a Referer), `X-Robots-Tag: noindex, nofollow`;
 * the route name keeps the token out of the logs (OnlineTokenRedactor).
 */
final class ParticipantClaimController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'submit';

    public function __construct(
        private readonly GetParticipantClaimPage $getParticipantClaimPage,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly ClaimTokenMemo $claimTokenMemo,
        private readonly RateLimiterFactoryInterface $participantClaimPageLimiter,
    ) {
    }

    #[Route(
        path: '/{_locale}/claim/{token}',
        name: 'participant_claim',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $token): Response
    {
        if ($this->participantClaimPageLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted() === false) {
            return self::withPrivateHeaders($this->render('participant_claim/page.html.twig', ['state' => 'too_many', 'page' => null], new Response(status: Response::HTTP_TOO_MANY_REQUESTS)));
        }

        if ($this->claimTokenMemo->isUnknown($token)) {
            return $this->notValid();
        }

        $viewerPlayerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;
        $page = $this->getParticipantClaimPage->byToken($token, $viewerPlayerId);

        if ($page === null) {
            $this->claimTokenMemo->rememberUnknown($token);

            return $this->notValid();
        }

        // Not remembered: the organiser may put the person back on the list
        if ($page->removed) {
            return $this->notValid();
        }

        if ($page->publiclyVisible === false) {
            if ($this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $page->competitionId) === false) {
                return $this->notValid();
            }

            return $this->page('draft_team', $page, $token);
        }

        return $this->page(self::state($page, $viewerPlayerId), $page, $token);
    }

    /**
     * What the page says to this viewer about this participant (§6.5's table)
     */
    public static function state(ParticipantClaimPage $page, null|string $viewerPlayerId): string
    {
        return match (true) {
            $viewerPlayerId === null => 'guest',
            $page->isLinkedTo($viewerPlayerId) => 'already_yours',
            $page->linkedPlayerId !== null => 'belongs_to_other',
            $page->viewerOtherName !== null => 'other_participant',
            default => 'unlinked',
        };
    }

    /**
     * The token is in the address: never stored by a browser or a proxy, never passed on in a Referer, never indexed
     */
    public static function withPrivateHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        // This Cache-Control stands - the session listener must not stamp its own over it for signed-in visitors
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    private function page(string $state, ParticipantClaimPage $page, string $token): Response
    {
        return self::withPrivateHeaders($this->render('participant_claim/page.html.twig', [
            'state' => $state,
            'page' => $page,
            'token' => $token,
            'event_url' => $this->competitionDetailUrl->of($page->competitionId),
            // Back here after signing in or creating the account (validated by the sign-in pages - ReturnUrl)
            'return_path' => $this->generateUrl('participant_claim', ['token' => $token]),
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]));
    }

    private function notValid(): Response
    {
        return self::withPrivateHeaders($this->render('participant_claim/page.html.twig', ['state' => 'not_valid', 'page' => null], new Response(status: Response::HTTP_NOT_FOUND)));
    }
}
