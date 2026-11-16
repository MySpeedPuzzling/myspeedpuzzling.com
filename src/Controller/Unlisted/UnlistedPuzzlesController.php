<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Entity\UnlistedPairing;
use SpeedPuzzling\Web\Query\GetUnlistedPuzzles;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage;
use SpeedPuzzling\Web\Services\Unlisted\ViewerUnlistedPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Unlisted puzzles" (docs/features/data-import/unlisted-puzzles.md "The pages"): the signed-in player's own puzzles
 * that are not in the catalogue, 50 a page. No player id is in the address - the list is always the reader's own; a
 * guest gets "not found". Two statements, whatever the size of the list.
 */
final class UnlistedPuzzlesController extends AbstractController
{
    public const int PER_PAGE = 50;

    public function __construct(
        readonly private UnlistedPuzzlePage $page,
        readonly private GetUnlistedPuzzles $getUnlistedPuzzles,
        readonly private ViewerUnlistedPuzzles $viewerUnlistedPuzzles,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/unlisted-puzzles',
        name: 'unlisted_puzzles',
        requirements: ['_locale' => ImportsHomeController::LOCALES],
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $playerId = $this->page->ownerPlayerId();
        // Matched ones leave the list - while their match can be taken back they stay reachable, folded under it
        $summary = $this->getUnlistedPuzzles->summary($playerId, $this->clock->now()->modify(sprintf('-%d days', UnlistedPairing::UNDO_DAYS)));
        // The menu of this very page - and of the next ones - knows without asking
        $this->viewerUnlistedPuzzles->remember($summary['puzzles'] > 0 || $summary['matched'] !== []);
        $pages = max(1, (int) ceil($summary['puzzles'] / self::PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        $response = $this->render('unlisted_puzzles/index.html.twig', [
            'summary' => $summary,
            'puzzles' => $this->getUnlistedPuzzles->page($playerId, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'pages' => $pages,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
