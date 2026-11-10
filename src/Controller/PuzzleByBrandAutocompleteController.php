<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use SpeedPuzzling\Web\Services\PuzzleNameLanguage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleByBrandAutocompleteController extends AbstractController
{
    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private PuzzleChoicesBuilder $puzzleChoicesBuilder,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleNameLanguage $puzzleNameLanguage,
        #[Autowire('%env(default::SENTRY_RELEASE)%')]
        readonly private null|string $release,
    ) {
    }

    #[Route(
        path: '/{_locale}/puzzle-by-brand-autocomplete/',
        name: 'puzzle_by_brand_autocomplete',
    )]
    public function __invoke(Request $request): Response
    {
        /** @var string|null $brandSearch */
        $brandSearch = $request->query->get('brand');

        if (!is_string($brandSearch) || Uuid::isValid((string) $brandSearch) === false) {
            return $this->json(['error' => 'Unknown brand id'], 404);
        }

        // The add-to-round form: whoever organises the competition also gets its own secret puzzles - one puzzle may
        // serve a round of every category. Anybody else never gets a puzzle before its hide_until.
        $competitionId = $request->query->getString('competition');
        $secretPuzzlesOfCompetition = Uuid::isValid($competitionId) && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId)
            ? $competitionId
            : null;

        // ... and their own secret puzzles, also one removed from its round
        $secretPuzzlesAddedBy = $secretPuzzlesOfCompetition !== null
            ? $this->retrieveLoggedUserProfile->getProfile()?->playerId
            : null;

        // The public list (up to 7 MB of JSON for Ravensburger): the browser keeps it and asks with If-None-Match, a
        // 304 skips reading and building it. Revalidated every time (no-cache, never max-age) - a puzzle just added
        // must be in the next form, or it gets added twice. The organisers' variant holds secret puzzles and depends on
        // round membership: never an ETag.
        $etag = null;

        if ($secretPuzzlesOfCompetition === null) {
            $etag = sha1(implode('|', [
                PuzzleChoicesBuilder::FORMAT_VERSION,
                $this->release ?? '',
                $this->searchPuzzle->brandFingerprint($brandSearch),
                $request->getLocale(),
                $this->puzzleNameLanguage->forViewer() ?? '',
            ]));

            $notModified = new Response();
            self::revalidatedPrivately($notModified, $etag);

            if ($notModified->isNotModified($request)) {
                return $notModified;
            }
        }

        $response = new JsonResponse([
            'results' => $this->puzzleChoicesBuilder->build(
                $this->searchPuzzle->byBrandId($brandSearch, $secretPuzzlesOfCompetition, $secretPuzzlesAddedBy),
                $request->getLocale(),
            ),
        ]);

        if ($etag !== null) {
            self::revalidatedPrivately($response, $etag);
        }

        return $response;
    }

    private static function revalidatedPrivately(Response $response, string $etag): void
    {
        $response->setEtag($etag);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
    }
}
