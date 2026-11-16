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
 * "Do the next 20" (docs/features/data-import/pairing-screens.md "In portions", guided-flow.md §6.2): leads to the
 * next puzzle of a pile that still waits for the player - after the one just decided or left for later, else the
 * first, **the puzzles with the most rows first** - one puzzle per screen. After twenty decided in a row it rests on
 * the pile's screen ("20 done - carry on or stop here", `done`); when nothing waits any more it goes there too.
 * `last` is the puzzle decided just before - the chooser offers to undo it. A GET that changes nothing; the address
 * carries ids and a number only.
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

        $target = $this->target(
            $importId,
            $player->playerId,
            $group,
            $request->query->getString('after'),
            $request->query->getInt('done'),
            $request->query->getString('last'),
        );

        if ($target['done']) {
            $this->addFlash('success', $this->translator->trans('data_import.puzzles.flash.group_done'));
        }

        return $this->redirectToRoute($target['route'], $target['parameters']);
    }

    /**
     * Where a round goes next - the chooser of the next puzzle that waits, or the pile's screen (to rest after a
     * portion, or because nothing waits: `done`). Asked by this address, and by an answer that is shown in place
     * (DecideImportPuzzlesController) - one rule for both.
     *
     * @return array{route: string, parameters: array<string, int|string>, done: bool}
     */
    public function target(string $importId, string $playerId, string $group, string $after, int $done, string $last): array
    {
        $after = Uuid::isValid($after) ? $after : null;
        $done = max(0, $done);
        $last = Uuid::isValid($last) ? strtolower($last) : null;

        if ($done >= self::PORTION) {
            // The rest screen: how many are done, how many are left - carry on, add what is ready, or stop
            return ['route' => 'data_import_puzzle_group', 'parameters' => ['importId' => $importId, 'group' => $group, 'done' => self::PORTION], 'done' => false];
        }

        $next = $this->getImportPuzzleScreen->nextUndecided($importId, $playerId, $group, $after);

        if ($next === null && $after !== null) {
            // The end of the list: once more from its beginning - what was skipped still waits
            $next = $this->getImportPuzzleScreen->nextUndecided($importId, $playerId, $group, null);
            $next = $next !== null && strtolower($next) === strtolower($after) ? null : $next;
        }

        if ($next === null) {
            return ['route' => 'data_import_puzzle_group', 'parameters' => ['importId' => $importId, 'group' => $group], 'done' => true];
        }

        return [
            'route' => 'data_import_puzzle_choose',
            'parameters' => array_filter(
                ['importId' => $importId, 'importPuzzleId' => $next, 'flow' => $group, 'done' => $done, 'last' => $last !== null && $last !== strtolower($next) ? $last : null],
                static fn (mixed $value): bool => $value !== null,
            ),
            'done' => false,
        ];
    }
}
