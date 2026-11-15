<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportPuzzleChoiceRefused;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzlePile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every decision of the pairing screens (docs/features/data-import/pairing-screens.md "Decisions"): this catalogue
 * puzzle, "Wrong puzzle" / untick, keep it just for me, don't import it - one message (DecideImportPuzzles), each
 * change with the `decision_version` its page showed - and the bulk decisions of
 * docs/features/data-import/guided-flow.md §6:
 *
 * - `tick_all` - "Yes, these N are right" of the picture grid: **only the tiles whose box is still ticked**, at most
 *   one screen of them, as one message marked `bulkConfirm` - the handler refuses it whole when any of them is not
 *   "the same puzzle, less proof" (another brand, another piece count, several possible puzzles), whatever the form
 *   holds;
 * - `all_in_pile` - one answer for every puzzle of a pile that waits ("Keep them just for me" / "Don't import
 *   them"): the form names the pile and the answer, **never a puzzle** - the server works them out
 *   (ImportPuzzlePile), and only when the import stands as the screen showed it (`revision`).
 *
 * POST + CSRF; always a redirect:
 *
 * - saved from a list: back to the list's page, at the card;
 * - saved in "Do the next 20": on to the next puzzle that waits;
 * - decided meanwhile in another tab (or by a run of the pairing): nothing is written, the page as it stands now
 *   with a plain notice - never a silent overwrite;
 * - a puzzle of another piece count, tapped: nothing is written - the chooser with a 422 and "choose it anyway?";
 *   only the button that says yes (`confirmed_other_pieces`) takes it;
 * - a puzzle that may not be chosen: nothing is written, a notice.
 *
 * Another player's import is a 404 (the handler checks it).
 */
final class DecideImportPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
        readonly private ImportPuzzlePile $pile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/puzzles/decide',
        name: 'data_import_puzzles_decide',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $form = $request->request;
        $action = $form->getString('action');
        $importPuzzleId = $form->getString('puzzle');
        $group = in_array($form->getString('group'), GetImportPuzzleScreen::GROUPS, true) ? $form->getString('group') : 'found';
        $flow = in_array($form->getString('flow'), GetImportPuzzleScreen::GROUPS, true) ? $form->getString('flow') : null;
        $fromChooser = $form->getString('from') === 'chooser' && preg_match('/^' . Requirement::UUID . '$/i', $importPuzzleId) === 1;

        if ($action === 'all_in_pile') {
            return $this->answerForThePile($importId, $player->playerId, $form->getString('pile'), $form->getString('choice'), $form->getString('revision'));
        }

        // A screen of "Looks like a match" (the picture grid, or the cards to look at closer): where it stood
        $part = in_array($form->getString('part'), [GetImportPuzzleScreen::PART_SAME, GetImportPuzzleScreen::PART_DIFFERS], true) ? $form->getString('part') : null;
        $after = Uuid::isValid($form->getString('after')) ? strtolower($form->getString('after')) : null;

        // Where the player sees what is: the chooser they came from, the screen of the pile, else the list's page
        $here = match (true) {
            $fromChooser => $this->redirectToRoute('data_import_puzzle_choose', array_filter(['importId' => $importId, 'importPuzzleId' => $importPuzzleId, 'flow' => $flow, 'done' => $flow === null ? null : $form->getInt('done')], static fn (mixed $value): bool => $value !== null)),
            $part !== null => $this->redirectToRoute('data_import_puzzle_group', array_filter(['importId' => $importId, 'group' => 'probably', 'part' => $part, 'after' => $after], static fn (mixed $value): bool => $value !== null)),
            default => $this->redirectToRoute('data_import_puzzle_group', ['importId' => $importId, 'group' => $group, 'page' => max(1, $form->getInt('page', 1)), '_fragment' => $importPuzzleId !== '' ? 'puzzle-' . $importPuzzleId : null]),
        };

        $decisions = match ($action) {
            'catalogue' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Catalogue, $form->getString('catalogue'), $form->getInt('version'), $form->getString('confirmed_other_pieces') === '1')],
            'undecided' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Undecided, null, $form->getInt('version'))],
            'unlisted' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Unlisted, null, $form->getInt('version'))],
            'left_out' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::LeftOut, null, $form->getInt('version'))],
            'tick_all' => self::tickedTilesOfTheScreen($form->all('cards')),
            default => [],
        };

        if ($decisions === []) {
            $this->addFlash('info', $this->translator->trans('data_import.puzzles.flash.nothing'));

            return $here;
        }

        try {
            $this->messageBus->dispatch(new DecideImportPuzzles($importId, $player->playerId, $decisions, bulkConfirm: $action === 'tick_all'));
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof ImportPuzzleDecidedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('data_import.puzzles.flash.decided_meanwhile'));

                return $here;
            }

            // Asked about once more only where one puzzle was tapped - a set is refused, never asked about
            if ($cause instanceof ImportPuzzleChoiceRefused && $cause->reason === ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_UNCONFIRMED && $action === 'catalogue') {
                return $this->askAboutTheOtherPieceCount($importId, $player->playerId, $importPuzzleId, $form->getString('catalogue'), (string) $flow, $form->getInt('done')) ?? $here;
            }

            if ($cause instanceof ImportPuzzleChoiceRefused) {
                $this->addFlash('warning', $this->translator->trans(match ($cause->reason) {
                    ImportPuzzleChoiceRefused::NOT_CHOOSABLE => 'data_import.puzzles.flash.not_choosable',
                    ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_IN_GROUP, ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_UNCONFIRMED => 'data_import.puzzles.flash.other_piece_count',
                    ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK => 'data_import.puzzles.bulk.flash.closer_look',
                    default => 'data_import.puzzles.flash.refused',
                }));

                return $here;
            }

            throw $exception;
        }

        if ($action === 'tick_all') {
            $this->addFlash('success', $this->translator->trans('data_import.puzzles.bulk.flash.matched', ['%count%' => count($decisions), '%number%' => count($decisions)]));

            // On to the next screen: after the last tile this one showed - what was unticked stays behind, waiting
            $last = Uuid::isValid($form->getString('last')) ? strtolower($form->getString('last')) : $after;

            return $this->redirectToRoute('data_import_puzzle_group', array_filter(['importId' => $importId, 'group' => 'probably', 'part' => GetImportPuzzleScreen::PART_SAME, 'after' => $last], static fn (mixed $value): bool => $value !== null));
        }

        if ($flow !== null && $action !== 'undecided') {
            // "Do the next 20": every tap is saved, and leads on
            return $this->redirectToRoute('data_import_puzzle_next', ['importId' => $importId, 'group' => $flow, 'after' => $importPuzzleId, 'done' => $form->getInt('done') + 1, 'last' => $importPuzzleId]);
        }

        $this->addFlash('success', $this->translator->trans($action === 'undecided' ? 'data_import.puzzles.flash.taken_back' : 'data_import.puzzles.flash.saved'));

        return $here;
    }

    /**
     * A puzzle of another piece count was tapped: nothing is written - the chooser again, with a 422, the question
     * "your file says 500 pieces, this puzzle has 1,000 - choose it anyway?" and the button that says yes. Asked by
     * the server, so it is asked without a script too.
     */
    private function askAboutTheOtherPieceCount(string $importId, string $playerId, string $importPuzzleId, string $cataloguePuzzleId, string $flow, int $done): null|Response
    {
        $card = $this->getImportPuzzleScreen->one($importId, $playerId, $importPuzzleId);
        $asked = $this->getImportPuzzleScreen->offered($playerId, $cataloguePuzzleId, $card->piecesCount, $card->manufacturerId);

        if ($asked === null) {
            return null;
        }

        return $this->render(
            'data_import/puzzle_choose.html.twig',
            ImportPuzzleChooserController::parameters(
                $flow,
                $done,
                $this->getDataImports->byIdOfPlayer($importId, $playerId),
                $card,
                $this->getImportPuzzleScreen->rowsOf($importId, $playerId, $importPuzzleId, ImportPuzzleChooserController::ROWS_SHOWN),
                null,
                null,
                askOtherPieces: $asked,
            ),
            new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    /**
     * One answer for every puzzle of a pile that waits (guided-flow.md §6.3). Nothing is applied when the import
     * does not stand as the screen showed it - its sheets were read again, its puzzles looked up again - or while
     * puzzles are still looked up. Another player's import is a 404 (the progress is read for the player).
     */
    private function answerForThePile(string $importId, string $playerId, string $pile, string $choice, string $shownRevision): Response
    {
        if (isset(GetImportPuzzleScreen::PILES[$pile]) === false) {
            throw $this->createNotFoundException();
        }

        $back = $this->redirectToRoute('data_import_puzzle_group', ['importId' => $importId, 'group' => $pile]);
        $live = ImportPuzzlesController::live($this->getDataImports->progressOfPlayer($importId, $playerId));

        if ($live['working'] || $live['searching'] > 0) {
            $this->addFlash('warning', $this->translator->trans('data_import.puzzles.bulk.flash.still_looking'));

            return $back;
        }

        if (hash_equals($live['revision'], $shownRevision) === false) {
            $this->addFlash('warning', $this->translator->trans('data_import.puzzles.bulk.flash.changed'));

            return $back;
        }

        $answer = $this->pile->answer($importId, $playerId, $pile, $choice);

        switch ($answer['state']) {
            case ImportPuzzlePile::DONE:
                $this->addFlash('success', $this->translator->trans(
                    $choice === ImportPuzzleChoice::LeftOut->value ? 'data_import.puzzles.bulk.flash.left_out' : 'data_import.puzzles.bulk.flash.kept',
                    ['%count%' => $answer['decided'], '%number%' => $answer['decided']],
                ));

                if ($answer['skipped'] > 0) {
                    $this->addFlash('info', $this->translator->trans('data_import.puzzles.bulk.flash.skipped', ['%count%' => $answer['skipped'], '%number%' => $answer['skipped']]));
                }

                // The last pile: on to the next step - else back to the piles
                return $this->getImportPuzzleScreen->overview($importId, $playerId)->open() === 0
                    ? $this->redirectToRoute('data_import_detail', ['importId' => $importId, 'after' => 'puzzles'])
                    : $this->redirectToRoute('data_import_puzzles', ['importId' => $importId]);
            case ImportPuzzlePile::QUEUED:
                $this->addFlash('success', $this->translator->trans('data_import.puzzles.bulk.flash.queued', ['%number%' => $answer['waiting']]));

                return $back;
            case ImportPuzzlePile::BUSY:
                $this->addFlash('warning', $this->translator->trans('data_import.puzzles.bulk.flash.busy'));

                return $back;
            case ImportPuzzlePile::STILL_LOOKING:
                $this->addFlash('warning', $this->translator->trans('data_import.puzzles.bulk.flash.still_looking'));

                return $back;
            default:
                $this->addFlash('info', $this->translator->trans('data_import.puzzles.flash.nothing'));

                return $back;
        }
    }

    /**
     * "Yes, these N are right": the tiles of the screen whose box is **still ticked**, each with the catalogue puzzle
     * and the version it showed. A tile without its tick is not in it - it was unticked, and it waits.
     *
     * @param array<array-key, mixed> $cards import puzzle id => [tick, version, catalogue]
     *
     * @return list<ImportPuzzleDecisionChange>
     */
    private static function tickedTilesOfTheScreen(array $cards): array
    {
        $decisions = [];

        foreach (array_slice($cards, 0, ImportPuzzleGroupController::TILES_PER_SCREEN, true) as $importPuzzleId => $card) {
            if (is_array($card) && ($card['tick'] ?? null) === '1' && is_string($card['catalogue'] ?? null) && is_numeric($card['version'] ?? null)) {
                $decisions[] = new ImportPuzzleDecisionChange((string) $importPuzzleId, ImportPuzzleChoice::Catalogue, $card['catalogue'], (int) $card['version']);
            }
        }

        return $decisions;
    }
}
