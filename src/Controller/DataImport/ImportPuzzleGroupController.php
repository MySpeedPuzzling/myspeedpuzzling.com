<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One group of an import's puzzles as a list (docs/features/data-import/pairing-screens.md "The lists"): fifty cards
 * a page, each with what the file says, the catalogue puzzle it was paired with (or the best candidate) and why.
 * The page number is the only thing in the address. Another player's import is a 404. Four statements whatever the
 * group's size.
 */
final class ImportPuzzleGroupController extends AbstractController
{
    public const int PUZZLES_PER_PAGE = 50;
    public const string GROUPS = 'found|probably|choose|not-found|remembered';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/puzzles/{group}',
        name: 'data_import_puzzle_group',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'group' => self::GROUPS],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $group): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $overview = $this->getImportPuzzleScreen->overview($importId, $player->playerId);

        if ($overview->beingSetUp === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $total = $overview->groups[$group]['total'];
        $pages = max(1, (int) ceil($total / self::PUZZLES_PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('data_import/puzzle_group.html.twig', [
            'import' => $import,
            'overview' => $overview,
            'group' => $group,
            'cards' => $total === 0 ? [] : $this->getImportPuzzleScreen->page($importId, $player->playerId, $group, self::PUZZLES_PER_PAGE, ($page - 1) * self::PUZZLES_PER_PAGE),
            'total' => $total,
            'open' => $overview->groups[$group]['open'],
            'page' => $page,
            'pages' => $pages,
            'live' => ImportPuzzlesController::live($this->getDataImports->progressOfPlayer($importId, $player->playerId)),
        ]);
    }
}
