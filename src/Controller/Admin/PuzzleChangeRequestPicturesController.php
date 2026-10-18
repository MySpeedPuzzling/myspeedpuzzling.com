<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The picture review (docs/features/puzzle-approvals.md "Picture proposals"): pending proposals of a new picture,
 * the current picture and the proposed one side by side, the pipeline's surest first, a page at a time. Each card is
 * decided on its own (DecidePictureProposalController - the existing approve / reject messages), from the keyboard
 * too. By default the image pipeline's artwork crops (`?tag=artwork_crop`); `?tag=all` lists every proposed picture.
 */
final class PuzzleChangeRequestPicturesController extends AbstractController
{
    public const int PER_PAGE = 24;

    public const string DEFAULT_TAG = 'artwork_crop';

    public const string ANY_TAG = 'all';

    public function __construct(
        private readonly GetPuzzleChangeRequests $getPuzzleChangeRequests,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-change-requests/pictures',
        name: 'admin_puzzle_change_request_pictures',
        methods: ['GET'],
        // Before the detail page's /admin/puzzle-change-requests/{id}
        priority: 10,
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request): Response
    {
        // Admins also see proposals for secret competition puzzles - moderators never do (PuzzleSecrecy)
        $isAdmin = $this->isGranted(AdminAccessVoter::ADMIN_ACCESS);
        $filter = self::filter($request);
        $total = $this->getPuzzleChangeRequests->countPendingPictureProposals($filter, $isAdmin);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $pages);

        return $this->render('admin/puzzle_change_request_pictures.html.twig', [
            'proposals' => $this->getPuzzleChangeRequests->pendingPictureProposals(
                $filter,
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE,
                $isAdmin,
            ),
            'filter' => $filter,
            // The tag as the links carry it - "all" when every tag is listed
            'tag_parameter' => $filter->tag ?? self::ANY_TAG,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'known_tags' => $this->getPuzzleChangeRequests->knownTags(),
        ]);
    }

    /**
     * The grid's filter - the queue's, with the artwork crops as the default tag.
     */
    public static function filter(Request $request): PuzzleChangeRequestFilter
    {
        $filter = PuzzleChangeRequestFilter::fromRequest($request);
        $tag = $request->query->get('tag');

        if ($tag === self::ANY_TAG) {
            return new PuzzleChangeRequestFilter($filter->source, null);
        }

        if ($tag === null || $tag === '') {
            return new PuzzleChangeRequestFilter($filter->source, self::DEFAULT_TAG);
        }

        return $filter;
    }
}
