<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Services\DataImport\ImportInPlace;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One card of a list of an import's puzzles, as it stands now - a Turbo Stream that puts it in its place
 * (docs/features/data-import/pairing-screens.md "Answers in place"). Asked by the page itself for a card that says
 * "We are looking for it again…": an answer of "Matched like last time" was taken back, the puzzle is looked up
 * again in the background, and the card shows the pile it came to rest in - without a reload. A GET that changes
 * nothing; the address holds ids, a list's name and a page number. Another player's import, or a puzzle of another
 * import, is a 404. Two statements.
 */
final class ImportPuzzleCardController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/puzzle/{importPuzzleId}/card',
        name: 'data_import_puzzle_card',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'importPuzzleId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $importPuzzleId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        // Another player's import: a 404
        $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $card = $this->getImportPuzzleScreen->one($importId, $player->playerId, $importPuzzleId);
        $group = in_array($request->query->getString('group'), GetImportPuzzleScreen::GROUPS, true) ? $request->query->getString('group') : 'found';

        ImportInPlace::answerFor($request, 'data_import_puzzle_group', $this->generateUrl('data_import_puzzle_group', ['importId' => $importId, 'group' => $group]));

        return $this->render('data_import/_puzzle_card.stream.html.twig', [
            'card' => $card,
            'import_id' => $importId,
            'group' => $group,
            'page' => max(1, $request->query->getInt('page', 1)),
        ]);
    }
}
