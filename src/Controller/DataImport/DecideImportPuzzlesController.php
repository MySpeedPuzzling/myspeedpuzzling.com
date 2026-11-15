<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportPuzzleChoiceRefused;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Results\ImportPuzzlesOverview;
use SpeedPuzzling\Web\Services\DataImport\ImportInPlace;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzlePile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
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
 * POST + CSRF. To Turbo (and the import's own script) the answer is shown **in place** - Turbo Streams for the screen
 * the form stood on, see shown() and docs/features/data-import/pairing-screens.md "Answers in place"; what is written
 * is the same either way. A plain browser post gets a redirect:
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
    private const string ON_LIST = 'list';
    private const string ON_PILE = 'pile';
    private const string ON_CHOOSER = 'chooser';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPuzzleScreen $getImportPuzzleScreen,
        readonly private ImportPuzzlePile $pile,
        readonly private ImportPuzzleGroupController $groupScreen,
        readonly private NextImportPuzzleController $next,
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
        // Shown in place (Turbo, the import's own script) - else the redirect, as ever
        $inPlace = ImportInPlace::asked($request);

        if ($action === 'all_in_pile') {
            return $this->answerForThePile($request, $inPlace, $importId, $player->playerId, $form->getString('pile'), $form->getString('choice'), $form->getString('revision'));
        }

        // A screen of "Looks like a match" (the picture grid, or the cards to look at closer): where it stood
        $part = in_array($form->getString('part'), [GetImportPuzzleScreen::PART_SAME, GetImportPuzzleScreen::PART_DIFFERS], true) ? $form->getString('part') : null;
        $after = Uuid::isValid($form->getString('after')) ? strtolower($form->getString('after')) : null;
        $page = max(1, $form->getInt('page', 1));
        $done = $form->getInt('done');

        // Where the player sees what is: the chooser they came from, the screen of the pile, else the list's page
        $here = match (true) {
            $fromChooser => $this->redirectToRoute('data_import_puzzle_choose', array_filter(['importId' => $importId, 'importPuzzleId' => $importPuzzleId, 'flow' => $flow, 'done' => $flow === null ? null : $done], static fn (mixed $value): bool => $value !== null)),
            $part !== null => $this->redirectToRoute('data_import_puzzle_group', array_filter(['importId' => $importId, 'group' => 'probably', 'part' => $part, 'after' => $after], static fn (mixed $value): bool => $value !== null)),
            default => $this->redirectToRoute('data_import_puzzle_group', ['importId' => $importId, 'group' => $group, 'page' => $page, '_fragment' => $importPuzzleId !== '' ? 'puzzle-' . $importPuzzleId : null]),
        };

        // The same place, for an answer shown in place: the screen the form stood on, and what leads back to it
        $where = [
            'screen' => match (true) {
                $fromChooser => self::ON_CHOOSER,
                // The cards to look at closer, the grid, and a card of the folded list of a pile's own screen
                $part !== null => self::ON_PILE,
                $form->getString('in_pile') === '1' && isset(GetImportPuzzleScreen::PILES[$group]) => self::ON_PILE,
                default => self::ON_LIST,
            },
            'importId' => $importId,
            'playerId' => $player->playerId,
            'puzzle' => $importPuzzleId,
            'group' => $part !== null ? 'probably' : $group,
            'page' => $page,
            'part' => $part,
            'after' => $after,
            'inPile' => $part === null && $form->getString('in_pile') === '1',
            'flow' => $flow,
            'done' => $flow === null ? 0 : max(0, $done),
        ];

        $decisions = match ($action) {
            'catalogue' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Catalogue, $form->getString('catalogue'), $form->getInt('version'), $form->getString('confirmed_other_pieces') === '1')],
            'undecided' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Undecided, null, $form->getInt('version'))],
            'unlisted' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Unlisted, null, $form->getInt('version'))],
            'left_out' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::LeftOut, null, $form->getInt('version'))],
            'tick_all' => self::tickedTilesOfTheScreen($form->all('cards')),
            default => [],
        };
        // One answer for several (the grid): said on the screen itself - an answer for one puzzle: in the line that stays in view
        $several = $action === 'tick_all';

        if ($decisions === []) {
            $text = $this->translator->trans('data_import.puzzles.flash.nothing');

            if ($inPlace) {
                return $this->shown($request, $where, ['type' => 'info', 'text' => $text], $here, $several);
            }

            $this->addFlash('info', $text);

            return $here;
        }

        try {
            $this->messageBus->dispatch(new DecideImportPuzzles($importId, $player->playerId, $decisions, bulkConfirm: $action === 'tick_all'));
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();
            $text = null;

            if ($cause instanceof ImportPuzzleDecidedMeanwhile) {
                $text = $this->translator->trans('data_import.puzzles.flash.decided_meanwhile');
            } elseif ($cause instanceof ImportPuzzleChoiceRefused && $cause->reason === ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_UNCONFIRMED && $action === 'catalogue') {
                // Asked about once more only where one puzzle was tapped - a set is refused, never asked about
                $asked = $this->askAboutTheOtherPieceCount($request, $inPlace && $fromChooser, $importId, $player->playerId, $importPuzzleId, $form->getString('catalogue'), (string) $flow, $done);

                if ($asked !== null) {
                    return $asked;
                }

                $text = $this->translator->trans('data_import.puzzles.flash.other_piece_count');
            } elseif ($cause instanceof ImportPuzzleChoiceRefused) {
                $text = $this->translator->trans(match ($cause->reason) {
                    ImportPuzzleChoiceRefused::NOT_CHOOSABLE => 'data_import.puzzles.flash.not_choosable',
                    ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_IN_GROUP, ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_UNCONFIRMED => 'data_import.puzzles.flash.other_piece_count',
                    ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK => 'data_import.puzzles.bulk.flash.closer_look',
                    default => 'data_import.puzzles.flash.refused',
                });
            }

            if ($text === null) {
                throw $exception;
            }

            if ($inPlace) {
                // Nothing was written: the screen as it stands now, and why
                return $this->shown($request, $where, ['type' => 'warning', 'text' => $text], $here, $several);
            }

            $this->addFlash('warning', $text);

            return $here;
        }

        if ($action === 'tick_all') {
            $text = $this->translator->trans('data_import.puzzles.bulk.flash.matched', ['%count%' => count($decisions), '%number%' => count($decisions)]);
            // On to the next screen: after the last tile this one showed - what was unticked stays behind, waiting
            $last = Uuid::isValid($form->getString('last')) ? strtolower($form->getString('last')) : $after;
            $next = $this->redirectToRoute('data_import_puzzle_group', array_filter(['importId' => $importId, 'group' => 'probably', 'part' => GetImportPuzzleScreen::PART_SAME, 'after' => $last], static fn (mixed $value): bool => $value !== null));

            if ($inPlace) {
                return $this->shown($request, ['part' => GetImportPuzzleScreen::PART_SAME, 'after' => $last] + $where, ['type' => 'success', 'text' => $text], $next, several: true, fresh: true);
            }

            $this->addFlash('success', $text);

            return $next;
        }

        // What the answer was, in the words of the piles ("1 puzzle kept just for you.") - and how it is taken back
        $said = [
            'type' => 'success',
            'text' => $this->translator->trans(match ($action) {
                'undecided' => 'data_import.puzzles.flash.taken_back',
                'unlisted' => 'data_import.puzzles.bulk.flash.kept',
                'left_out' => 'data_import.puzzles.bulk.flash.left_out',
                default => 'data_import.puzzles.bulk.flash.matched',
            }, ['%count%' => 1, '%number%' => 1]),
            // "Keep it just for me": where the puzzle is after adding, said at the moment it is picked
            'kept' => $action === 'unlisted',
            'undo' => $action === 'undecided' ? null : $where,
        ];

        if ($flow !== null && $action !== 'undecided') {
            // "Do the next 20": every tap is saved, and leads on
            $onward = $this->redirectToRoute('data_import_puzzle_next', ['importId' => $importId, 'group' => $flow, 'after' => $importPuzzleId, 'done' => $done + 1, 'last' => $importPuzzleId]);

            if ($inPlace === false) {
                return $onward;
            }

            $target = $this->next->target($importId, $player->playerId, $flow, $importPuzzleId, $done + 1, $importPuzzleId);

            if ($target['route'] !== 'data_import_puzzle_choose') {
                // The round rests, or nothing waits any more: that is another screen
                return $onward;
            }

            // The next puzzle takes the place of this one
            return $this->shown(
                $request,
                ['screen' => self::ON_CHOOSER, 'puzzle' => (string) $target['parameters']['importPuzzleId'], 'done' => $done + 1, 'last' => $importPuzzleId] + $where,
                $said,
                $onward,
                fresh: true,
            );
        }

        if ($inPlace) {
            return $this->shown($request, $where, $said, $here);
        }

        $this->addFlash('success', $this->translator->trans($action === 'undecided' ? 'data_import.puzzles.flash.taken_back' : 'data_import.puzzles.flash.saved') . ($action === 'unlisted' ? ' ' . $this->translator->trans('data_import.puzzles.said.kept_where') : ''));

        return $here;
    }

    /**
     * The answer, shown in place (docs/features/data-import/pairing-screens.md "Answers in place") - Turbo Streams
     * for the screen the form stood on, read after the answer was written (or refused) with the screens' own queries:
     *
     * - a list: the card where it stands, and the list's count;
     * - a pile's own screen (the grid, the cards to look at closer, the folded list of a pile): the screen - the
     *   card that was answered is gone, the next one moved up, the counts are right, and when nothing waits it says
     *   so with the way on;
     * - the chooser: the puzzle as it stands now - or, in a round, the next one;
     *
     * and what the answer did, in the line that stays in view - with "Undo the last one", and with the way to the
     * next step when no puzzle of the import waits any more.
     *
     * @param array{screen: string, importId: string, playerId: string, puzzle: string, group: string, page: int, part: null|string, after: null|string, inPile: bool, flow: null|string, done: int, last?: string} $where
     * @param array{type: string, text: string, kept?: bool, undo?: null|array{screen: string, importId: string, playerId: string, puzzle: string, group: string, page: int, part: null|string, after: null|string, inPile: bool, flow: null|string, done: int, last?: string}} $said
     */
    private function shown(Request $request, array $where, array $said, Response $otherwise, bool $several = false, bool $fresh = false): Response
    {
        $importId = $where['importId'];
        $playerId = $where['playerId'];
        $pile = null;
        $chooser = null;
        $card = null;
        $counts = null;
        $overview = null;
        $screenRoute = 'data_import_puzzle_group';
        $screenPath = $this->generateUrl('data_import_puzzle_group', ['importId' => $importId, 'group' => $where['group']]);

        if ($where['screen'] === self::ON_PILE) {
            /** @var InputBag<string> $query what the screen's address said */
            $query = new InputBag(array_filter(['part' => $where['part'], 'after' => $where['after']], static fn (null|string $value): bool => $value !== null));
            $pile = $this->groupScreen->screen($importId, $playerId, $where['group'], $query);

            if ($pile === null) {
                // The import is past its set-up: its own page says what is
                return $otherwise;
            }

            $overview = $pile['overview'];
            assert($overview instanceof ImportPuzzlesOverview);
            $pile['list_open'] = $where['inPile'];
            $pile['fresh'] = $fresh;
            $pile['notices'] = $several ? [$said] : [];
        } elseif (Uuid::isValid($where['puzzle']) === false) {
            return $otherwise;
        } elseif ($where['screen'] === self::ON_CHOOSER) {
            $shownCard = $this->getImportPuzzleScreen->one($importId, $playerId, $where['puzzle']);
            $chooser = ImportPuzzleChooserController::parameters(
                (string) $where['flow'],
                $where['done'],
                $this->getDataImports->byIdOfPlayer($importId, $playerId),
                $shownCard,
                $this->getImportPuzzleScreen->rowsOf($importId, $playerId, $where['puzzle'], ImportPuzzleChooserController::ROWS_SHOWN),
                null,
                null,
                last: $where['last'] ?? '',
            );
            $chooser['fresh'] = $fresh;
            $screenRoute = 'data_import_puzzle_choose';
            $screenPath = $this->generateUrl('data_import_puzzle_choose', ['importId' => $importId, 'importPuzzleId' => $where['puzzle']]);
        } else {
            $card = $this->getImportPuzzleScreen->one($importId, $playerId, $where['puzzle']);
            $overview = $this->getImportPuzzleScreen->overview($importId, $playerId);
            $counts = $overview->groups[$where['group']];
        }

        $overview ??= $this->getImportPuzzleScreen->overview($importId, $playerId);
        $undo = $said['undo'] ?? null;

        if ($undo !== null) {
            // Taken back with the version the answer left, and shown where the answer was given
            $answered = $undo['puzzle'];
            $undo = array_filter([
                'puzzle' => $answered,
                'version' => (string) $this->getImportPuzzleScreen->one($importId, $playerId, $answered)->decisionVersion,
                'group' => $undo['group'],
                'page' => (string) $undo['page'],
                'part' => $undo['part'],
                'after' => $undo['part'] === null ? null : $undo['after'],
                'in_pile' => $undo['inPile'] ? '1' : null,
                'from' => $undo['screen'] === self::ON_CHOOSER ? 'chooser' : null,
                'flow' => $undo['flow'],
                'done' => $undo['flow'] === null ? null : (string) $undo['done'],
            ], static fn (mixed $value): bool => $value !== null);
        }

        ImportInPlace::answerFor($request, $screenRoute, $screenPath);

        return $this->render('data_import/_puzzles_answer.stream.html.twig', [
            'import_id' => $importId,
            'pile' => $pile,
            'chooser' => $chooser,
            'card' => $card,
            'group' => $where['group'],
            'page' => $where['page'],
            'counts' => $counts,
            // One answer for several is said on the screen itself; the line that stays in view is then cleared
            'said' => $several ? null : [
                'type' => $said['type'],
                'text' => $said['text'],
                'kept' => $said['kept'] ?? false,
                'undo' => $undo,
                'import_id' => $importId,
                // The last puzzle that waited was answered: the way to the next step, where the player is
                'step_done' => $where['screen'] !== self::ON_PILE && $overview->puzzles > 0 && $overview->open() === 0 && $overview->searching === 0,
            ],
        ]);
    }

    /**
     * A puzzle of another piece count was tapped: nothing is written - the chooser again, with a 422, the question
     * "your file says 500 pieces, this puzzle has 1,000 - choose it anyway?" and the button that says yes. Asked by
     * the server, so it is asked without a script too.
     */
    private function askAboutTheOtherPieceCount(Request $request, bool $inPlace, string $importId, string $playerId, string $importPuzzleId, string $cataloguePuzzleId, string $flow, int $done): null|Response
    {
        $card = $this->getImportPuzzleScreen->one($importId, $playerId, $importPuzzleId);
        $asked = $this->getImportPuzzleScreen->offered($playerId, $cataloguePuzzleId, $card->piecesCount, $card->manufacturerId);

        if ($asked === null) {
            return null;
        }

        $chooser = ImportPuzzleChooserController::parameters(
            $flow,
            $done,
            $this->getDataImports->byIdOfPlayer($importId, $playerId),
            $card,
            $this->getImportPuzzleScreen->rowsOf($importId, $playerId, $importPuzzleId, ImportPuzzleChooserController::ROWS_SHOWN),
            null,
            null,
            askOtherPieces: $asked,
        );

        if ($inPlace) {
            // Asked on the chooser itself: the question takes its place at the top of the screen, and is shown
            ImportInPlace::answerFor($request, 'data_import_puzzle_choose', $this->generateUrl('data_import_puzzle_choose', ['importId' => $importId, 'importPuzzleId' => $importPuzzleId]));

            return $this->render('data_import/_puzzles_answer.stream.html.twig', [
                'import_id' => $importId,
                'pile' => null,
                'chooser' => ['fresh' => true] + $chooser,
                'card' => null,
                'counts' => null,
                'said' => null,
            ]);
        }

        return $this->render('data_import/puzzle_choose.html.twig', $chooser, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * One answer for every puzzle of a pile that waits (guided-flow.md §6.3). Nothing is applied when the import
     * does not stand as the screen showed it - its sheets were read again, its puzzles looked up again - or while
     * puzzles are still looked up, nor beside another answer for the same pile. Another player's import is a 404.
     */
    private function answerForThePile(Request $request, bool $inPlace, string $importId, string $playerId, string $pile, string $choice, string $shownRevision): Response
    {
        if (isset(GetImportPuzzleScreen::PILES[$pile]) === false) {
            throw $this->createNotFoundException();
        }

        $back = $this->redirectToRoute('data_import_puzzle_group', ['importId' => $importId, 'group' => $pile]);
        // Everything is checked where the answer is written down, under the import's lock (ImportPuzzlePile)
        $answer = $this->pile->answer($importId, $playerId, $pile, $choice, $shownRevision);
        $notices = [];

        switch ($answer['state']) {
            case ImportPuzzlePile::DONE:
                $notices[] = [
                    'type' => 'success',
                    'text' => $this->translator->trans(
                        $choice === ImportPuzzleChoice::LeftOut->value ? 'data_import.puzzles.bulk.flash.left_out' : 'data_import.puzzles.bulk.flash.kept',
                        ['%count%' => $answer['decided'], '%number%' => $answer['decided']],
                    ),
                    // Where the puzzles kept just for the player are after adding
                    'kept' => $choice !== ImportPuzzleChoice::LeftOut->value,
                ];

                if ($answer['skipped'] > 0) {
                    $notices[] = ['type' => 'info', 'text' => $this->translator->trans('data_import.puzzles.bulk.flash.skipped', ['%count%' => $answer['skipped'], '%number%' => $answer['skipped']])];
                }

                // The last pile: on to the next step - else back to the piles
                $back = $this->getImportPuzzleScreen->overview($importId, $playerId)->open() === 0
                    ? $this->redirectToRoute('data_import_detail', ['importId' => $importId, 'after' => 'puzzles'])
                    : $this->redirectToRoute('data_import_puzzles', ['importId' => $importId]);

                break;
            case ImportPuzzlePile::QUEUED:
                $notices[] = ['type' => 'success', 'text' => $this->translator->trans('data_import.puzzles.bulk.flash.queued', ['%number%' => $answer['waiting']])];

                break;
            case ImportPuzzlePile::BUSY:
                $notices[] = ['type' => 'warning', 'text' => $this->translator->trans('data_import.puzzles.bulk.flash.busy')];

                break;
            case ImportPuzzlePile::CHANGED:
                $notices[] = ['type' => 'warning', 'text' => $this->translator->trans('data_import.puzzles.bulk.flash.changed')];

                break;
            case ImportPuzzlePile::STILL_LOOKING:
                $notices[] = ['type' => 'warning', 'text' => $this->translator->trans('data_import.puzzles.bulk.flash.still_looking')];

                break;
            default:
                $notices[] = ['type' => 'info', 'text' => $this->translator->trans('data_import.puzzles.flash.nothing')];
        }

        if ($inPlace) {
            // The pile's own screen as it stands now - "Nothing left here" with the way on, or the answer being saved
            /** @var InputBag<string> $query the pile's own screen */
            $query = new InputBag();
            $screen = $this->groupScreen->screen($importId, $playerId, $pile, $query);

            if ($screen !== null) {
                ImportInPlace::answerFor($request, 'data_import_puzzle_group', $this->generateUrl('data_import_puzzle_group', ['importId' => $importId, 'group' => $pile]));

                return $this->render('data_import/_puzzles_answer.stream.html.twig', [
                    'import_id' => $importId,
                    'pile' => ['notices' => $notices, 'fresh' => true] + $screen,
                    'chooser' => null,
                    'card' => null,
                    'counts' => null,
                    'said' => null,
                ]);
            }
        }

        foreach ($notices as $notice) {
            $this->addFlash($notice['type'], $notice['text'] . (($notice['kept'] ?? false) ? ' ' . $this->translator->trans('data_import.puzzles.said.kept_where') : ''));
        }

        return $back;
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
