<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PlayerResultsPage;
use SpeedPuzzling\Web\Services\PlayerResults\PlayerResultsBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PlayerOverview;
use SpeedPuzzling\Web\Value\PlayerResultsCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A player's Results (docs/features/navigation README §4.7, PROF-05a): every result, searchable and filterable, By
 * puzzle or Every result - a plain server-rendered page whose state is the URL (PlayerResultsCriteria; members-only
 * values of anybody else are dropped there), not a Live component, so links work without JavaScript and history can
 * be pushed (implementation plan decision 69). A `Turbo-Frame: player-results` request gets the list region alone.
 *
 * Show more (PROF-05b) is a real link to `?page=N+1` (rows 1…50·(N+1)); the page's script asks the same URL with
 * `fragment=more` and gets a Turbo Stream instead: the next 50 rows appended to the list and its footer replaced. A
 * parameter, not the Accept header - Turbo 8 asks every form submission for streams.
 *
 * A player the viewer blocked (as on the profile: GetPlayerProfile::byId()) or an unknown id is a 404; a private
 * profile the viewer may not see goes to the profile, which explains it.
 */
final class PlayerResultsController extends AbstractController
{
    public const string FRAME_ID = 'player-results';

    public const string FRAGMENT_PARAMETER = 'fragment';

    public const string FRAGMENT_MORE = 'more';

    // Where a link came from - never a variant of the list (base.html.twig leaves them out of its clean URL too)
    private const array RETURN_PARAMETERS = ['return', 'return_url', 'return_title'];

    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerResultsBuilder $playerResultsBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vysledky-hrace/{playerId}',
            'en' => '/en/player-results/{playerId}',
            'es' => '/es/resultados-jugador/{playerId}',
            'ja' => '/ja/プレイヤー結果/{playerId}',
            'fr' => '/fr/resultats-joueur/{playerId}',
            'de' => '/de/spieler-ergebnisse/{playerId}',
        ],
        name: 'player_results',
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $playerId): Response
    {
        // PlayerNotFound extends NotFoundHttpException and bubbles up as 404 (also for a player hidden from the viewer)
        $player = $this->getPlayerProfile->byId($playerId);
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        // isPrivate = hidden from this viewer (PrivateProfileAccess): an allowed friend and the owner see the list
        if ($player->isPrivate && $viewer?->playerId !== $player->playerId) {
            return $this->redirectToRoute('player_profile', ['playerId' => $player->playerId]);
        }

        $criteria = PlayerResultsCriteria::fromRequest($request, isMember: $viewer?->activeMembership === true);

        if ($request->query->get(self::FRAGMENT_PARAMETER) === self::FRAGMENT_MORE) {
            $response = $this->render('player/results/_more.stream.html.twig', [
                'results' => $this->playerResultsBuilder->build($player->playerId, $criteria, appendPage: true),
            ]);
            $response->headers->set('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
            // Never a page of its own (nothing links it; a crawler executing the script must not index it)
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $results = $this->playerResultsBuilder->build($player->playerId, $criteria);

        $frameOnly = $request->headers->get('Turbo-Frame') === self::FRAME_ID;

        $response = $this->render($frameOnly ? 'player/results/_frame.html.twig' : 'player_results.html.twig', [
            'player' => $player,
            'results' => $results,
            'seo' => $this->seo($request, $player, $results),
        ]);

        // The page and its frame share one URL
        $response->setVary('Turbo-Frame', false);

        return $response;
    }

    /**
     * Search engines (README §4.7 SEO, PROF-07):
     * - a private profile (the player's own setting, whoever looks): noindex, nofollow
     * - the list's own URL (and `?page=1`): canonical; indexed - and only then with hreflang (alternates on a noindex
     *   page are a conflicting signal, README §4.7, QA-04 R22) - when the player has more results than the Overview
     *   shows (PlayerOverview::LATEST_RESULTS) - else it would duplicate the profile
     * - `?page=N` alone (N ≥ 2, the rows 1…50·N): followed, never indexed, its own canonical (the page it really
     *   shows - beyond the end the last one), no hreflang
     * - any filter, sort, view or tab (with or without a page): followed, never indexed, canonical = the list
     *
     * @return array{robots: string, canonical: string, hreflang: bool}
     */
    private function seo(Request $request, PlayerProfile $player, PlayerResultsPage $results): array
    {
        $list = $this->generateUrl('player_results', ['playerId' => $player->playerId], UrlGeneratorInterface::ABSOLUTE_URL);

        if ($player->isPrivateProfile) {
            return ['robots' => 'noindex, nofollow', 'canonical' => $list, 'hreflang' => false];
        }

        $parameters = array_values(array_diff(array_map(strval(...), array_keys($request->query->all())), self::RETURN_PARAMETERS));

        if ($parameters === [] || ($parameters === ['page'] && $results->criteria->page === 1)) {
            $indexable = $results->resultsCount > PlayerOverview::LATEST_RESULTS;

            return [
                'robots' => $indexable ? 'index, follow' : 'noindex, follow',
                'canonical' => $list,
                'hreflang' => $indexable,
            ];
        }

        if ($parameters === ['page'] && $results->page > 1) {
            return [
                'robots' => 'noindex, follow',
                'canonical' => $this->generateUrl('player_results', ['playerId' => $player->playerId, 'page' => $results->page], UrlGeneratorInterface::ABSOLUTE_URL),
                'hreflang' => false,
            ];
        }

        return ['robots' => 'noindex, follow', 'canonical' => $list, 'hreflang' => false];
    }
}
