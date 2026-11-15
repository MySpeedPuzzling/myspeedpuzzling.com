<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportPuzzleChoiceRefused;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
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
 * puzzle, "Not this one" / untick, keep as unlisted, leave out, and "Tick all I see here" - one message
 * (DecideImportPuzzles), each change with the `decision_version` its page showed. POST + CSRF; always a redirect:
 *
 * - saved from a list: back to the list's page, at the card;
 * - saved in "Do the next 20": on to the next puzzle that waits;
 * - decided meanwhile in another tab (or by a run of the pairing): nothing is written, the page as it stands now
 *   with a plain notice - never a silent overwrite;
 * - a puzzle that may not be chosen: nothing is written, a notice.
 *
 * Another player's import is a 404 (the handler checks it).
 */
final class DecideImportPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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

        // Where the player sees what is: the chooser they came from, else the list's page
        $here = $fromChooser
            ? $this->redirectToRoute('data_import_puzzle_choose', array_filter(['importId' => $importId, 'importPuzzleId' => $importPuzzleId, 'flow' => $flow, 'done' => $flow === null ? null : $form->getInt('done')], static fn (mixed $value): bool => $value !== null))
            : $this->redirectToRoute('data_import_puzzle_group', ['importId' => $importId, 'group' => $group, 'page' => max(1, $form->getInt('page', 1)), '_fragment' => $importPuzzleId !== '' ? 'puzzle-' . $importPuzzleId : null]);

        $decisions = match ($action) {
            'catalogue' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Catalogue, $form->getString('catalogue'), $form->getInt('version'))],
            'undecided' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Undecided, null, $form->getInt('version'))],
            'unlisted' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::Unlisted, null, $form->getInt('version'))],
            'left_out' => [new ImportPuzzleDecisionChange($importPuzzleId, ImportPuzzleChoice::LeftOut, null, $form->getInt('version'))],
            'tick_all' => self::everyCardOfThePage($form->all('cards')),
            default => [],
        };

        if ($decisions === []) {
            $this->addFlash('info', $this->translator->trans('data_import.puzzles.flash.nothing'));

            return $here;
        }

        try {
            $this->messageBus->dispatch(new DecideImportPuzzles($importId, $player->playerId, $decisions));
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof ImportPuzzleDecidedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('data_import.puzzles.flash.decided_meanwhile'));

                return $here;
            }

            if ($cause instanceof ImportPuzzleChoiceRefused) {
                $this->addFlash('warning', $this->translator->trans(match ($cause->reason) {
                    ImportPuzzleChoiceRefused::NOT_CHOOSABLE => 'data_import.puzzles.flash.not_choosable',
                    ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_IN_GROUP => 'data_import.puzzles.flash.other_piece_count',
                    default => 'data_import.puzzles.flash.refused',
                }));

                return $here;
            }

            throw $exception;
        }

        if ($action === 'tick_all') {
            $this->addFlash('success', $this->translator->trans('data_import.puzzles.flash.ticked', ['%number%' => count($decisions)]));

            return $here;
        }

        if ($flow !== null && $action !== 'undecided') {
            // "Do the next 20": every tap is saved, and leads on
            return $this->redirectToRoute('data_import_puzzle_next', ['importId' => $importId, 'group' => $flow, 'after' => $importPuzzleId, 'done' => $form->getInt('done') + 1]);
        }

        $this->addFlash('success', $this->translator->trans($action === 'undecided' ? 'data_import.puzzles.flash.taken_back' : 'data_import.puzzles.flash.saved'));

        return $here;
    }

    /**
     * "Tick all I see here": the cards the page showed as waiting, each with the candidate and the version it showed.
     *
     * @param array<array-key, mixed> $cards import puzzle id => [version, catalogue]
     *
     * @return list<ImportPuzzleDecisionChange>
     */
    private static function everyCardOfThePage(array $cards): array
    {
        $decisions = [];

        foreach (array_slice($cards, 0, ImportPuzzleGroupController::PUZZLES_PER_PAGE, true) as $importPuzzleId => $card) {
            if (is_array($card) && is_string($card['catalogue'] ?? null) && is_numeric($card['version'] ?? null)) {
                $decisions[] = new ImportPuzzleDecisionChange((string) $importPuzzleId, ImportPuzzleChoice::Catalogue, $card['catalogue'], (int) $card['version']);
            }
        }

        return $decisions;
    }
}
