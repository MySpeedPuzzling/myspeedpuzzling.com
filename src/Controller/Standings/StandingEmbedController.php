<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Standings;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionStandingsLiveUpdates;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use SpeedPuzzling\Web\Services\Standings\StandingPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One published standing as a page made for an `<iframe>` on the organiser's own website (real events L3,
 * docs/features/competitions-management/standings.md "Embeddable standing") - the SPWS Wix site. The table part of the
 * public standing page as a guest sees it, on its own lean layout (templates/standing/embed.html.twig, Encore entry
 * `standing_embed`), live on the event's data-free standings signal.
 *
 * - Stateless firewall: no session, no cookie, no viewer - the answer is the same for everybody (the blocklist does not
 *   apply - a documented exemption like the public JSON; private players masked for everybody, the allowed friend too).
 * - 404 (a lean page, still frameable) for an unknown or unpublished standing and an event that is not public (drafts,
 *   unapproved) - the visibility is read on every request, before anything cached.
 * - The HTML is rendered once per generation of the event's standings, locale and theme (PublicStandingSnapshots::
 *   embedHtml()): warm 1 statement. `public, max-age=0, must-revalidate` + an ETag of the body - the page's own refetch
 *   after a signal (or its 60 s poll without a stream) gets a 304 while nothing changed.
 * - The only route that may be framed by any site: `Content-Security-Policy: frame-ancestors *` and no
 *   `X-Frame-Options` (FramingHeadersSubscriber gives every other answer `SAMEORIGIN`).
 */
final class StandingEmbedController extends AbstractController
{
    public const string ROUTE = 'standing_embed';
    public const string FRAME_ANCESTORS = 'frame-ancestors *';
    private const array THEMES = ['light', 'dark'];

    public function __construct(
        private readonly StandingPageBuilder $standingPageBuilder,
        private readonly PublicStandingSnapshots $snapshots,
        private readonly IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
    ) {
    }

    #[Route(
        path: '/embed/standing/{standingId}/{_locale}',
        name: self::ROUTE,
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'standingId' => FirstTryConflictsController::ID_REQUIREMENT],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $standingId): Response
    {
        $theme = $request->query->get('theme');
        $theme = is_string($theme) && in_array($theme, self::THEMES, true) ? $theme : 'light';
        $competitionId = $this->snapshots->competitionOf($standingId);

        if ($competitionId === null || $this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            return $this->notFound($theme);
        }

        try {
            $html = $this->snapshots->embedHtml(
                $standingId,
                $competitionId,
                $request->getLocale(),
                $theme,
                fn (): string => $this->renderView('standing/embed.html.twig', [
                    'embed' => $this->standingPageBuilder->buildEmbed($standingId),
                    'theme' => $theme,
                    'live_topic' => CompetitionStandingsLiveUpdates::topic($competitionId),
                ]),
            );
        } catch (CompetitionStandingNotFound) {
            return $this->notFound($theme);
        }

        $response = new Response($html);
        self::headers($response);
        $response->setPublic();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setEtag(sha1($html));
        $response->isNotModified($request);

        return $response;
    }

    private function notFound(string $theme): Response
    {
        $response = $this->render('standing/embed_not_found.html.twig', ['theme' => $theme], new Response(status: Response::HTTP_NOT_FOUND));
        self::headers($response);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private static function headers(Response $response): void
    {
        $response->headers->set('Content-Security-Policy', self::FRAME_ANCESTORS);
        $response->headers->remove('X-Frame-Options');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
}
