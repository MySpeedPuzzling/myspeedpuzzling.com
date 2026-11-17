<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Standings;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\PublicStandingSnapshot;
use SpeedPuzzling\Web\Services\Standings\PublicStandingJson;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A published standing as JSON for anybody (competition toolkit v2 S1, displays-standings.md §3.1) - overlays, big
 * screens, third-party stream tools (vMix, OBS widgets). On the stateless firewall: no session, no viewer - the guest
 * snapshot (PublicStandingSnapshots) is the answer for everybody (documented blocklist exemption, BlocklistCanaryTest).
 *
 * - 404 `{"error": "not_found"}` for an unknown or unpublished standing and for an event that is not public (drafts,
 *   unapproved) - the visibility is checked on every request (1 statement, never cached: it is not signalled).
 * - `Cache-Control: public, max-age=0, must-revalidate` + an ETag of the body: a client polling or answering a signal
 *   gets a 304 while nothing changed (warm: 1 statement). CORS `*` (GET only, no credentials). URLs in `?locale=`
 *   (default en).
 */
final class PublicStandingStateController extends AbstractController
{
    private const array LOCALES = ['en', 'cs', 'es', 'ja', 'fr', 'de'];

    public function __construct(
        private readonly PublicStandingSnapshots $snapshots,
        private readonly IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
    ) {
    }

    #[Route(
        path: '/api/competition-standings/{standingId}',
        name: 'public_standing_state',
        requirements: ['standingId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(Request $request, string $standingId): Response
    {
        $competitionId = $this->snapshots->competitionOf($standingId);

        if ($competitionId === null || $this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            return self::notFound();
        }

        $snapshot = $this->snapshots->forGuest($standingId);

        if ($snapshot === null || $snapshot->isPublished() === false) {
            return self::notFound();
        }

        $locale = $request->query->get('locale');
        $locale = is_string($locale) && in_array($locale, self::LOCALES, true) ? $locale : 'en';
        $body = json_encode(
            PublicStandingJson::of($snapshot, $this->standingUrl($snapshot, $locale), fn (null|string $roundSlug): null|string => $this->roundUrl($snapshot, $roundSlug, $locale)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $response = new JsonResponse($body, json: true);
        self::headers($response);
        $response->setPublic();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setEtag(sha1($body));
        $response->isNotModified($request);

        return $response;
    }

    private static function notFound(): JsonResponse
    {
        $response = new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        self::headers($response);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private static function headers(Response $response): void
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Access-Control-Allow-Origin', '*');
    }

    private function standingUrl(PublicStandingSnapshot $snapshot, string $locale): null|string
    {
        $data = $snapshot->toArray();

        if ($data['eventSlug'] === null) {
            return null;
        }

        return $data['seriesSlug'] !== null
            ? $this->generateUrl('edition_standing', ['seriesSlug' => $data['seriesSlug'], 'editionSlug' => $data['eventSlug'], 'standingSlug' => $data['slug'], '_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL)
            : $this->generateUrl('event_standing', ['slug' => $data['eventSlug'], 'standingSlug' => $data['slug'], '_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function roundUrl(PublicStandingSnapshot $snapshot, null|string $roundSlug, string $locale): null|string
    {
        $data = $snapshot->toArray();

        if ($roundSlug === null || $data['eventSlug'] === null) {
            return null;
        }

        return $data['seriesSlug'] !== null
            ? $this->generateUrl('edition_round_results', ['seriesSlug' => $data['seriesSlug'], 'editionSlug' => $data['eventSlug'], 'roundSlug' => $roundSlug, '_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL)
            : $this->generateUrl('event_round_results', ['slug' => $data['eventSlug'], 'roundSlug' => $roundSlug, '_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
