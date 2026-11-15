<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Do the next 20" (docs/features/data-import/pairing-screens.md "In portions"): leads to the next puzzle of a
 * group that still waits for the player - after the one just decided or skipped, else the first - one puzzle per
 * screen. After twenty decided in a row, and when nothing waits any more, it goes back to the group's list and says
 * how far the player is. A GET that changes nothing; the address carries ids and a number only.
 */
final class NextImportPuzzleController extends AbstractController
{
    public const int PORTION = 20;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/puzzles/{group}/next',
        name: 'data_import_puzzle_next',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'group' => ImportPuzzleGroupController::GROUPS],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $group): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        // Another player's import: a 404
        $this->getDataImports->byIdOfPlayer($importId, $player->playerId);

        $after = $request->query->getString('after');
        $after = Uuid::isValid($after) ? $after : null;
        $done = max(0, $request->query->getInt('done'));
        $list = $this->redirectToRoute('data_import_puzzle_group', ['importId' => $importId, 'group' => $group]);

        if ($done >= self::PORTION) {
            $open = $this->getImportPuzzleScreen->overview($importId, $player->playerId)->groups[$group]['open'];
            $this->addFlash('success', $this->translator->trans('data_import.puzzles.flash.portion_done', ['%done%' => $done, '%open%' => $open]));

            return $list;
        }

        $next = $this->getImportPuzzleScreen->nextUndecided($importId, $player->playerId, $group, $after);

        if ($next === null && $after !== null) {
            // The end of the list: once more from its beginning - what was skipped still waits
            $next = $this->getImportPuzzleScreen->nextUndecided($importId, $player->playerId, $group, null);
            $next = $next !== null && strtolower($next) === strtolower($after) ? null : $next;
        }

        if ($next === null) {
            $this->addFlash('success', $this->translator->trans('data_import.puzzles.flash.group_done'));

            return $list;
        }

        return $this->redirectToRoute('data_import_puzzle_choose', ['importId' => $importId, 'importPuzzleId' => $next, 'flow' => $group, 'done' => $done]);
    }
}
