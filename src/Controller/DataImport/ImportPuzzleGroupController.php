<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One pile of an import's puzzles (docs/features/data-import/guided-flow.md §6) - what the screen is depends on the
 * pile:
 *
 * - **Looks like a match** (`probably`): the picture grid - TILES_PER_SCREEN puzzles that still wait, of the part
 *   "the same puzzle, less proof" (`part=same`), each ticked on the screen and confirmed with one tap; then the rest
 *   of the pile (`part=differs`) as cards confirmed one by one. A screen goes on after the puzzle the one before
 *   ended with (`after` - an id), so what was left for later stays behind;
 * - **Pick the right one** (`choose`): where a round of "one puzzle per screen" starts and rests (`done` - how many
 *   the round just did), with "Keep the rest just for me" folded;
 * - **Not in our database** (`not-found`): one answer for all of them;
 * - **Matched**, **Matched like last time** - and every pile with a `page` in its address: the list
 *   (docs/features/data-import/pairing-screens.md "The lists"), fifty cards a page.
 *
 * The address holds ids and numbers only. Another player's import is a 404. A fixed number of statements whatever
 * the pile's size: the import, the overview and the live line's start - and at most three for the pile's own part.
 */
final class ImportPuzzleGroupController extends AbstractController
{
    public const int PUZZLES_PER_PAGE = 50;
    public const int TILES_PER_SCREEN = 20;
    public const string GROUPS = 'found|probably|choose|not-found|remembered';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
        readonly private GetImportAddScreen $getImportAddScreen,
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

        $parameters = $this->screen($importId, $player->playerId, $group, $request->query);

        if ($parameters === null) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        return $this->render('data_import/puzzle_group.html.twig', $parameters);
    }

    /**
     * What the screen of a pile shows - for its page, and for the answer given on it that is shown in place
     * (DecideImportPuzzlesController): the same screen, as it stands after the answer. `$query` is what the page's
     * address says (`page`, `part`, `after`, `done`). Null: the import is past its set-up.
     *
     * @param InputBag<string> $query
     *
     * @return null|array<string, mixed>
     */
    public function screen(string $importId, string $playerId, string $group, InputBag $query): null|array
    {
        $import = $this->getDataImports->byIdOfPlayer($importId, $playerId);
        $overview = $this->getImportPuzzleScreen->overview($importId, $playerId);

        if ($overview->beingSetUp === false) {
            return null;
        }

        $total = $overview->groups[$group]['total'];
        $pages = max(1, (int) ceil($total / self::PUZZLES_PER_PAGE));
        $page = min($pages, max(1, $query->getInt('page', 1)));

        // The list: the piles that are nothing else, and any pile asked for by its page
        $asList = $query->has('page') || in_array($group, ['found', 'remembered'], true);
        $withList = $asList || isset(GetImportPuzzleScreen::PILES[$group]);

        $parameters = [
            'import' => $import,
            'overview' => $overview,
            'group' => $group,
            'as_list' => $asList,
            'cards' => $total === 0 || $withList === false ? [] : $this->getImportPuzzleScreen->page($importId, $playerId, $group, self::PUZZLES_PER_PAGE, ($page - 1) * self::PUZZLES_PER_PAGE),
            'total' => $total,
            'open' => $overview->groups[$group]['open'],
            'page' => $page,
            'pages' => $pages,
            'live' => ImportPuzzlesController::live($this->getDataImports->progressOfPlayer($importId, $playerId)),
            // An answer for the whole pile still runs on the worker (DataImportPileAnswer - read with the overview)
            'is_running' => in_array($group, $overview->pilesBeingAnswered, true),
            'tiles_per_screen' => self::TILES_PER_SCREEN,
            'portion' => NextImportPuzzleController::PORTION,
        ];

        if ($asList === false && $group === 'probably') {
            $after = $query->getString('after');
            $after = Uuid::isValid($after) ? strtolower($after) : null;
            $counts = $this->getImportPuzzleScreen->partCounts($importId, $playerId, $after);
            $part = $query->getString('part');

            if ($part !== GetImportPuzzleScreen::PART_SAME && $part !== GetImportPuzzleScreen::PART_DIFFERS) {
                // The grid first; what needs a closer look when nothing of the grid waits
                $part = $counts['same'] > 0 || $counts['differs'] === 0 ? GetImportPuzzleScreen::PART_SAME : GetImportPuzzleScreen::PART_DIFFERS;
            }

            $parameters += [
                'part' => $part,
                'after' => $after,
                'counts' => $counts,
                'tiles' => $counts[$part] === 0 ? [] : $this->getImportPuzzleScreen->partPage($importId, $playerId, $part, self::TILES_PER_SCREEN, $after),
            ];
        }

        if ($asList === false && $group === 'choose') {
            $parameters += [
                'done' => min(NextImportPuzzleController::PORTION, max(0, $query->getInt('done'))),
                'round' => $this->getImportPuzzleScreen->nextRound($importId, $playerId, $group, NextImportPuzzleController::PORTION),
                // "Add what is ready now (614)" of the rest screen - one statement
                'add' => $this->getImportAddScreen->summary($importId, $playerId),
            ];
        }

        return $parameters;
    }
}
