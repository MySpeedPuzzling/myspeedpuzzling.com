<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetBoxTextMismatches;
use SpeedPuzzling\Web\Results\BoxTextMismatchRow;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\BoxText\BoxTextMismatchDetector;
use SpeedPuzzling\Web\Value\BoxTextField;
use SpeedPuzzling\Web\Value\BoxTextMismatchSort;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The box-text mismatch list (docs/features/puzzle-approvals.md, "Box-text mismatches"): what the box says (pieces,
 * EAN, brand code, brand - read by the image pipeline) and the catalogue does not. Moderators fix the record
 * themselves (the direct edit) or dismiss; nothing is ever proposed automatically.
 */
final class BoxTextMismatchesController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'box-text-mismatch-dismiss';

    public function __construct(
        private readonly GetBoxTextMismatches $getBoxTextMismatches,
    ) {
    }

    #[Route(
        path: '/admin/box-text-mismatches',
        name: 'admin_box_text_mismatches',
        methods: ['GET'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request): Response
    {
        // Admins also see puzzles hidden now - moderators never do
        $isAdmin = $this->isGranted(AdminAccessVoter::ADMIN_ACCESS);
        $field = BoxTextField::tryFrom($request->query->getString('field'));
        $sort = BoxTextMismatchSort::tryFrom($request->query->getString('sort')) ?? BoxTextMismatchSort::MostSolved;

        $total = $this->getBoxTextMismatches->count($field, $isAdmin);
        $pages = max(1, (int) ceil($total / GetBoxTextMismatches::PAGE_SIZE));
        $page = min(max(1, $request->query->getInt('page', 1)), $pages);

        // A record fixed since the last detection run drops out at once (the next run removes its row)
        $mismatches = array_values(array_filter(
            $this->getBoxTextMismatches->page($field, $sort, $isAdmin, $page),
            static fn (BoxTextMismatchRow $row): bool => BoxTextMismatchDetector::stillMismatched($row->field, $row->boxValues, $row->catalogue),
        ));

        return $this->render('admin/box_text_mismatches.html.twig', [
            'mismatches' => $mismatches,
            'field' => $field,
            'fields' => BoxTextField::cases(),
            'sort' => $sort,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
    }
}
